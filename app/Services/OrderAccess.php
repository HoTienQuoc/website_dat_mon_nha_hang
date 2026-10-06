<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderAccess
{
    public function allows(Request $request, Order $order): bool
    {
        if ($order->user_id) {
            return $request->user()?->can('view', $order) ?? false;
        }

        return in_array($order->id, $request->session()->get('verified_orders', []), true);
    }

    public function authorize(Request $request, Order $order): void
    {
        abort_unless($this->allows($request, $order), 404);
    }
}
