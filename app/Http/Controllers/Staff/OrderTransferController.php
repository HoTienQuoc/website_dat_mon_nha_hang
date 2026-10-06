<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Services\OrderAdjustments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderTransferController extends Controller
{
    public function merge(Request $request, Order $order, OrderAdjustments $adjustments): RedirectResponse
    {
        $data = $request->validate(['target_order_id' => 'required|integer', 'expected_total' => 'required|integer', 'target_total' => 'required|integer', 'reason' => 'required|string|min:5|max:500']);
        $target = DB::transaction(function () use ($request, $order, $data, $adjustments) {
            $rows = Order::whereIn('id', [$order->id, $data['target_order_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $rows->get($order->id);
            $target = $rows->get($data['target_order_id']);
            if (! $target || $source->id === $target->id) {
                throw ValidationException::withMessages(['target_order_id' => __('Chọn một đơn tại bàn khác.')]);
            }
            foreach ([$source, $target] as $o) {
                $adjustments->assertEditable($o);
                if ($o->fulfillment_type !== 'dine_in') {
                    throw ValidationException::withMessages(['order' => __('Chỉ gộp hoặc tách đơn tại bàn.')]);
                }
            }
            if ($source->user_id !== $target->user_id || $source->is_demo !== $target->is_demo || $source->total !== (int) $data['expected_total'] || $target->total !== (int) $data['target_total']) {
                throw ValidationException::withMessages(['order' => __('Đơn đã thay đổi hoặc không cùng khách tài khoản / loại dữ liệu.')]);
            }
            foreach ($source->items()->where('quantity', '>', 0)->get() as $item) {
                $target->items()->create($item->only(['dish_id', 'dish_name', 'dish_name_en', 'unit_price', 'quantity', 'preparation_status']));
            }
            if ($source->note) {
                $target->note = trim(($target->note ?? '')."\n".'#'.$source->id.': '.$source->note);
            }
            foreach ($source->tasks as $task) {
                if (! $target->tasks()->where('kind', $task->kind)->exists()) {
                    $target->tasks()->create($task->only(['kind', 'assigned_to', 'assigned_by', 'status', 'note']));
                }
            }
            $previous = $source->status;
            $source->update(['status' => 'cancelled', 'merged_into_id' => $target->id, 'cancellation_reason' => __('Gộp vào đơn').' #'.$target->id.' · '.$data['reason']]);
            $source->histories()->create(['user_id' => $request->user()->id, 'from_status' => $previous, 'to_status' => 'cancelled', 'reason' => $source->cancellation_reason]);
            $adjustments->recalculate($target, $request->user(), __('Nhận món từ đơn').' #'.$source->id.' · '.$data['reason']);
            if ($target->items()->where('quantity', '>', 0)->whereIn('preparation_status', ['pending', 'preparing'])->exists()) {
                $target->tasks()->whereIn('kind', ['cooking', 'preparation'])->where('status', 'done')->update(['status' => 'pending']);
            }
            if ($target->status !== 'served') {
                $target->tasks()->where('kind', 'service')->where('status', 'done')->update(['status' => 'pending']);
            }

            return $target;
        }, 3);

        return redirect()->route('staff.orders.show', $target)->with('success', __('Đã gộp đơn, bàn nguồn đã được giải phóng.'));
    }

    public function split(Request $request, Order $order, OrderAdjustments $adjustments): RedirectResponse
    {
        $data = $request->validate(['restaurant_table_id' => 'required|integer', 'expected_total' => 'required|integer', 'quantities' => 'required|array|max:100', 'quantities.*' => 'required|integer|min:0|max:100', 'reason' => 'required|string|min:5|max:500']);
        $child = DB::transaction(function () use ($request, $order, $data, $adjustments) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $adjustments->assertEditable($order);
            if ($order->fulfillment_type !== 'dine_in' || $order->total !== (int) $data['expected_total']) {
                throw ValidationException::withMessages(['order' => __('Đơn đã thay đổi hoặc không phải đơn tại bàn.')]);
            }
            $table = RestaurantTable::whereKey($data['restaurant_table_id'])->where('is_active', true)->lockForUpdate()->first();
            if (! $table || $table->hasActiveOrderForUpdate()) {
                throw ValidationException::withMessages(['restaurant_table_id' => __('Bàn không khả dụng hoặc đơn không thể xếp bàn.')]);
            }
            $selected = array_filter($data['quantities'], fn ($q) => (int) $q > 0);
            $items = $order->items()->where('quantity', '>', 0)->get()->keyBy('id');
            if (! $selected || array_sum($selected) >= $items->sum('quantity')) {
                throw ValidationException::withMessages(['quantities' => __('Chọn một phần món để tách, đơn gốc phải còn món.')]);
            }
            foreach ($selected as $id => $qty) {
                if (! $items->has($id) || $qty > $items[$id]->quantity) {
                    throw ValidationException::withMessages(['quantities' => __('Số lượng tách không hợp lệ.')]);
                }
            }
            $child = Order::create([...$order->only(['note', 'user_id', 'customer_name', 'customer_phone', 'customer_email', 'fulfillment_type', 'is_demo', 'locale', 'payment_method']), 'code' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'split_from_id' => $order->id, 'restaurant_table_id' => $table->id, 'table_name' => $table->name, 'payment_status' => 'unpaid', 'status' => 'confirmed', 'subtotal' => 0, 'delivery_fee' => 0, 'total' => 0]);
            foreach ($selected as $id => $qty) {
                $item = $items[$id];
                $child->items()->create([...$item->only(['dish_id', 'dish_name', 'dish_name_en', 'unit_price', 'preparation_status']), 'quantity' => $qty]);
                $item->update(['quantity' => $item->quantity - $qty]);
            }
            foreach ($order->tasks as $task) {
                $child->tasks()->create($task->only(['kind', 'assigned_to', 'assigned_by', 'status', 'note']));
            }
            $adjustments->recalculate($order, $request->user(), __('Tách món sang đơn').' #'.$child->id.' · '.$data['reason']);
            $adjustments->recalculate($child, $request->user(), __('Tách từ đơn').' #'.$order->id.' · '.$data['reason']);

            return $child;
        }, 3);

        return redirect()->route('staff.orders.show', $child)->with('success', __('Đã tách đơn sang bàn mới.'));
    }
}
