<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrderAdjustments
{
    public function assertEditable(Order $order): void
    {
        if ($order->payment_method !== 'cash' || $order->payment_status !== 'unpaid' || ! in_array($order->status, ['pending', 'confirmed', 'preparing', 'ready', 'served'], true) || $order->cash_shift_id) {
            throw ValidationException::withMessages(['order' => __('Chỉ sửa đơn tiền mặt chưa thanh toán và chưa bàn giao giao hàng.')]);
        }
    }

    public function authorize(User $user, Order $order): void
    {
        abort_unless($user->coordinatesOrders() || ($user->role === 'waiter' && $order->assignedTo($user, 'service')), 403);
    }

    public function recalculate(Order $order, User $actor, string $reason): void
    {
        $items = $order->items()->where('quantity', '>', 0)->get();
        $previous = $order->status;
        $order->subtotal = $items->sum(fn ($i) => $i->quantity * $i->unit_price);
        if ($items->isEmpty()) {
            $order->status = 'cancelled';
            $order->cancellation_reason = $reason;
            $order->delivery_fee = 0;
        } elseif ($order->status !== 'pending') {
            $states = $items->pluck('preparation_status');
            $order->status = $states->every(fn ($s) => $s === 'served') && $order->fulfillment_type === 'dine_in' ? 'served' : ($states->every(fn ($s) => in_array($s, ['ready', 'served'], true)) ? 'ready' : ($states->contains('preparing') || $states->contains('served') || $states->contains('ready') ? 'preparing' : 'confirmed'));
        }
        $order->total = $order->subtotal + $order->delivery_fee;
        if ($order->total > 1000000000) {
            throw ValidationException::withMessages(['order' => __('Tổng đơn vượt giới hạn cho phép.')]);
        }
        $order->save();
        $order->histories()->create(['user_id' => $actor->id, 'from_status' => $previous, 'to_status' => $order->status, 'reason' => $reason]);
    }
}
