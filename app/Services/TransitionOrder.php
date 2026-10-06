<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionOrder
{
    public function allowed(Order $order, User $user): array
    {
        if (! $user->isStaff()) {
            return [];
        }
        if (in_array($order->payment_method, ['stripe', 'demo_card'], true) && $order->payment_status !== 'paid') {
            return $order->status === 'pending' && in_array($order->payment_status, ['unpaid', 'refund_failed'], true) && $user->coordinatesOrders() ? ['cancelled'] : [];
        }
        $next = match ($order->status) {
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => [$order->fulfillment_type === 'delivery' ? 'delivering' : ($order->fulfillment_type === 'dine_in' ? 'served' : 'completed'), 'cancelled'],
            'delivering' => ['delivered', 'delivery_failed'],
            'delivery_failed' => ['delivering', 'cancelled'],
            'delivered', 'served' => ['completed'],
            default => [],
        };

        return array_values(array_filter($next, fn ($status) => match ($user->role) {
            'admin', 'manager' => true,
            'cashier' => in_array($status, ['confirmed', 'completed'], true) || ($status === 'cancelled' && in_array($order->status, ['pending', 'confirmed'], true)),
            'head_chef' => in_array($status, ['preparing', 'ready'], true),
            'chef' => $order->assignedTo($user, 'cooking') && in_array($status, ['preparing', 'ready'], true),
            'waiter' => $order->assignedTo($user, 'service') && $status === 'served',
            'courier' => $order->assignedTo($user, 'delivery') && in_array($status, ['delivering', 'delivered', 'delivery_failed'], true) && $order->status !== 'delivery_failed',
            default => false,
        }));
    }

    public function handle(Order $order, User $user, array $data): Order
    {
        return DB::transaction(function () use ($order, $user, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== $data['expected_status']) {
                throw ValidationException::withMessages(['status' => __('Đơn đã được người khác cập nhật. Vui lòng tải lại trang.')]);
            }
            abort_unless(in_array($data['status'], $this->allowed($order, $user), true), 403, __('Vai trò của bạn không được chuyển đơn sang trạng thái này.'));
            $target = $data['status'];
            if ($order->fulfillment_type === 'delivery' && in_array($target, ['confirmed', 'delivering'], true) && ! $order->address_verified_at) {
                throw ValidationException::withMessages(['address' => __('Cần đối chiếu và xác nhận địa chỉ thuộc Nha Trang trước khi tiếp nhận hoặc giao đơn.')]);
            }
            if ($target === 'ready' && $order->tasks()->where('kind', 'preparation')->where('status', '!=', 'done')->exists()) {
                throw ValidationException::withMessages(['status' => __('Phụ bếp cần hoàn tất sơ chế trước khi báo món sẵn sàng.')]);
            }
            if ($target === 'served' && (! $order->restaurant_table_id || ! $order->tasks()->where('kind', 'service')->whereHas('assignee', fn ($q) => $q->where('is_active', true)->where('role', 'waiter'))->exists())) {
                throw ValidationException::withMessages(['status' => __('Cần xếp bàn và phân công phục vụ trước khi bàn giao món.')]);
            }
            if ($target === 'delivering' && ! $order->tasks()->where('kind', 'delivery')->whereHas('assignee', fn ($q) => $q->where('is_active', true)->where('role', 'courier'))->exists()) {
                throw ValidationException::withMessages(['status' => __('Cần phân công nhân viên giao hàng đang hoạt động.')]);
            }
            if ($target === 'delivery_failed' && empty(trim($data['reason'] ?? ''))) {
                throw ValidationException::withMessages(['reason' => __('Vui lòng ghi lý do giao thất bại.')]);
            }
            if ($target === 'delivered' && $order->payment_method === 'cash') {
                if (empty($data['cash_collected'])) {
                    throw ValidationException::withMessages(['cash_collected' => __('Xác nhận đã thu đủ tiền mặt của khách trước khi báo đã giao.')]);
                }
                $order->cod_collected_at = now();
                $order->cod_collected_by = $user->id;
            }
            if ($target === 'cancelled') {
                if (empty(trim($data['reason'] ?? ''))) {
                    throw ValidationException::withMessages(['reason' => __('Vui lòng nhập lý do hủy đơn.')]);
                }
                if (in_array($order->status, ['preparing', 'ready', 'delivery_failed'], true) && empty($data['approve_exception'])) {
                    throw ValidationException::withMessages(['approve_exception' => __('Quản lý phải xác nhận duyệt ngoại lệ hủy sau khi chuẩn bị.')]);
                }
                $order->cancellation_reason = $data['reason'];
            }
            if ($target === 'completed') {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (empty($data['handed_over']) || (in_array($order->payment_method, ['stripe', 'demo_card'], true) ? $order->payment_status !== 'paid' : empty($data['paid']))) {
                    throw ValidationException::withMessages(['paid' => __('Phải xác nhận đã giao món và đã thu đủ tiền trước khi hoàn tất.')]);
                }
                if ($order->fulfillment_type === 'delivery' && $order->payment_method === 'cash') {
                    if (! $order->cod_collected_at) {
                        throw ValidationException::withMessages(['paid' => __('Chưa có xác nhận thu hộ từ nhân viên giao hàng.')]);
                    }
                    $order->cod_settled_at = now();
                }
                $order->paid_at ??= now();
                $order->payment_status = 'paid';
                $order->completed_at = now();
                $order->completed_by = $user->id;
            }
            if ($target === 'preparing') {
                $order->items()->where('quantity', '>', 0)->where('preparation_status', 'pending')->update(['preparation_status' => 'preparing']);
            }
            if ($target === 'ready') {
                $order->items()->where('quantity', '>', 0)->whereIn('preparation_status', ['pending', 'preparing'])->update(['preparation_status' => 'ready']);
            }
            if (in_array($target, ['served', 'delivering', 'completed'], true)) {
                $order->items()->where('quantity', '>', 0)->update(['preparation_status' => 'served']);
            }
            $taskChange = match ($target) {
                'preparing' => ['cooking', 'in_progress'], 'ready' => ['cooking', 'done'],
                'served' => ['service', 'done'], 'delivering' => ['delivery', 'in_progress'],
                'delivered' => ['delivery', 'done'], 'delivery_failed' => ['delivery', 'failed'], default => null,
            };
            if ($taskChange) {
                $order->tasks()->where('kind', $taskChange[0])->update(['status' => $taskChange[1]]);
            }
            $previous = $order->status;
            $order->status = $target;
            $order->save();
            $order->histories()->create(['user_id' => $user->id, 'from_status' => $previous, 'to_status' => $target, 'reason' => $data['reason'] ?? null]);

            return $order;
        }, 3);
    }
}
