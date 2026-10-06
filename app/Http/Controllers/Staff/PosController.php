<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function create(): View
    {
        return $this->form();
    }

    public function edit(Request $request, Order $order): View
    {
        $this->authorizeOrder($request, $order);

        return $this->form($order);
    }

    private function form(?Order $order = null): View
    {
        return view('staff.pos.form', ['order' => $order, 'dishes' => Dish::where('is_available', true)->orderBy('name')->get(), 'tables' => RestaurantTable::where('is_active', true)->whereDoesntHave('activeOrders')->orderBy('name')->get(), 'key' => (string) Str::uuid()]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless($request->user()->coordinatesOrders() || ($request->user()->role === 'waiter' && $order->assignedTo($request->user(), 'service')), 403);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->save($request, $order);
    }

    private function save(Request $request, ?Order $order = null): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'restaurant_table_id' => $order ? 'nullable' : 'required|integer', 'customer_name' => 'nullable|string|max:100', 'note' => 'nullable|string|max:500', 'quantities' => 'required|array|max:100', 'quantities.*' => 'required|integer|min:0|max:20']);
        $order = DB::transaction(function () use ($request, $order, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('order_additions')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $request->user()->id && (! $order || $order->id === $existing->order_id), 403);

                return Order::findOrFail($existing->order_id);
            }
            if ($order) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->authorizeOrder($request, $order);
                if ($order->fulfillment_type !== 'dine_in' || $order->payment_status !== 'unpaid' || $order->payment_method !== 'cash' || ! in_array($order->status, ['confirmed', 'preparing', 'ready', 'served'], true)) {
                    throw ValidationException::withMessages(['order' => __('Chỉ thêm món vào đơn tại bàn chưa thanh toán bằng tiền mặt và chưa kết thúc.')]);
                }
            }
            $selected = array_filter($data['quantities'], fn ($q) => (int) $q > 0);
            if (! $selected || array_sum($selected) > 100) {
                throw ValidationException::withMessages(['quantities' => __('Chọn ít nhất một món, tối đa 100 phần mỗi lượt.')]);
            }
            $dishes = Dish::whereIn('id', array_keys($selected))->where('is_available', true)->orderBy('id')->lockForUpdate()->get();
            if ($dishes->count() !== count($selected)) {
                throw ValidationException::withMessages(['quantities' => __('Một món đã hết hoặc không tồn tại. Vui lòng chọn lại.')]);
            }
            if (! $order) {
                $table = RestaurantTable::whereKey($data['restaurant_table_id'])->where('is_active', true)->lockForUpdate()->first();
                if (! $table || $table->hasActiveOrderForUpdate()) {
                    throw ValidationException::withMessages(['restaurant_table_id' => __('Bàn không khả dụng hoặc đơn không thể xếp bàn.')]);
                }
                $order = Order::create(['code' => (string) Str::uuid(), 'idempotency_key' => $data['request_key'], 'customer_name' => ($data['customer_name'] ?? null) ?: __('Khách tại bàn'), 'customer_phone' => null, 'fulfillment_type' => 'dine_in', 'restaurant_table_id' => $table->id, 'table_name' => $table->name, 'status' => 'confirmed', 'payment_method' => 'cash', 'payment_status' => 'unpaid', 'subtotal' => 0, 'delivery_fee' => 0, 'total' => 0, 'note' => $data['note'] ?? null, 'locale' => app()->getLocale()]);
                if ($request->user()->role === 'waiter') {
                    $order->tasks()->create(['kind' => 'service', 'assigned_to' => $request->user()->id, 'assigned_by' => $request->user()->id, 'status' => 'pending']);
                }
            }
            $subtotal = 0;
            foreach ($dishes as $dish) {
                $quantity = (int) $selected[$dish->id];
                $subtotal += $dish->price * $quantity;
                $order->items()->create(['dish_id' => $dish->id, 'dish_name' => $dish->name, 'dish_name_en' => $dish->name_en, 'unit_price' => $dish->price, 'quantity' => $quantity, 'preparation_status' => 'pending']);
            }
            if ($order->subtotal + $subtotal > 1000000000) {
                throw ValidationException::withMessages(['order' => __('Tổng đơn vượt giới hạn cho phép.')]);
            }
            $previous = $order->status;
            if (in_array($previous, ['ready', 'served'], true)) {
                $order->status = 'preparing';
                $order->tasks()->whereIn('kind', ['cooking', 'service', 'preparation'])->update(['status' => 'pending']);
            }
            $order->subtotal += $subtotal;
            $order->total = $order->subtotal + $order->delivery_fee;
            $order->save();
            DB::table('order_additions')->insert(['request_key' => $data['request_key'], 'order_id' => $order->id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $order->histories()->create(['user_id' => $request->user()->id, 'from_status' => $previous, 'to_status' => $order->status, 'reason' => __('Gọi món tại bàn').': '.count($selected).' · '.$subtotal.' VND']);

            return $order;
        }, 3);

        return redirect()->route('staff.orders.show', $order)->with('success', __('Đã gửi món xuống bếp.'));
    }
}
