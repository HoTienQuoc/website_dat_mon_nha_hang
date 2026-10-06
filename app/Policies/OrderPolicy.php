<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->is_active && $user->role === 'customer' && $order->user_id === $user->id;
    }

    public function manage(User $user, Order $order): bool
    {
        return $user->isStaff() && ($user->coordinatesOrders() || ($user->role === 'head_chef' && in_array($order->status, ['confirmed', 'preparing', 'ready'], true)) || $order->assignedTo($user));
    }

    public function printReceipt(User $user, Order $order): bool
    {
        return $this->view($user, $order) || ($user->isStaff() && in_array($user->role, ['admin', 'manager', 'cashier'], true));
    }
}
