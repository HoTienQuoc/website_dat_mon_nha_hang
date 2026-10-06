<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class KitchenController extends Controller
{
    public function index(Request $request): View
    {
        $query = Order::with(['items', 'tasks.assignee'])->whereIn('status', ['confirmed', 'preparing', 'ready']);
        if (! in_array($request->user()->role, ['admin', 'manager', 'head_chef'], true)) {
            $query->whereHas('tasks', fn ($q) => $q->where('assigned_to', $request->user()->id));
        }

        return view('staff.kitchen.index', ['orders' => $query->oldest('created_at')->paginate(20)]);
    }

    public function update(Request $request, OrderItem $item): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:preparing,ready,served', 'expected_status' => 'required|in:pending,preparing,ready']);
        DB::transaction(function () use ($request, $item, $data) {
            $order = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $user = $request->user();
            $kitchen = in_array($user->role, ['admin', 'manager', 'head_chef'], true) || ($user->role === 'chef' && $order->assignedTo($user, 'cooking'));
            $service = in_array($user->role, ['admin', 'manager'], true) || ($user->role === 'waiter' && $order->assignedTo($user, 'service'));
            abort_unless($data['status'] === 'served' ? $service && $order->fulfillment_type === 'dine_in' : $kitchen, 403);
            $next = ['pending' => 'preparing', 'preparing' => 'ready', 'ready' => 'served'];
            if ($item->quantity < 1 || ! in_array($order->status, ['confirmed', 'preparing', 'ready'], true) || $item->preparation_status !== $data['expected_status'] || ($next[$item->preparation_status] ?? null) !== $data['status']) {
                throw ValidationException::withMessages(['status' => __('Món đã được cập nhật. Vui lòng tải lại màn hình.')]);
            }
            if (in_array($data['status'], ['ready', 'served'], true) && $order->tasks()->where('kind', 'preparation')->where('status', '!=', 'done')->exists()) {
                throw ValidationException::withMessages(['status' => __('Phụ bếp cần hoàn tất sơ chế trước khi báo món sẵn sàng.')]);
            }
            if ($data['status'] === 'served' && ! $order->restaurant_table_id) {
                throw ValidationException::withMessages(['status' => __('Cần xếp bàn và phân công phục vụ trước khi bàn giao món.')]);
            }
            $item->update(['preparation_status' => $data['status']]);
            $previous = $order->status;
            if (! $order->items()->where('quantity', '>', 0)->where('preparation_status', '!=', 'served')->exists()) {
                $order->status = 'served';
                $order->tasks()->where('kind', 'service')->update(['status' => 'done']);
            } elseif (! $order->items()->where('quantity', '>', 0)->whereNotIn('preparation_status', ['ready', 'served'])->exists()) {
                $order->status = 'ready';
                $order->tasks()->where('kind', 'cooking')->update(['status' => 'done']);
            } else {
                $order->status = 'preparing';
                $order->tasks()->where('kind', 'cooking')->update(['status' => 'in_progress']);
            }
            $order->save();
            $order->histories()->create(['user_id' => $user->id, 'from_status' => $previous, 'to_status' => $order->status, 'reason' => $item->display_name.': '.__(OrderItem::PREPARATION[$data['status']])]);
        }, 3);

        return back()->with('success', __('Đã cập nhật tiến độ món.'));
    }
}
