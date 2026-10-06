<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderUpdatesController extends Controller
{
    public function __invoke(Request $request, Order $order, OrderAccess $access): JsonResponse
    {
        $access->authorize($request, $order);
        $items = $order->items()->get(['id', 'quantity', 'preparation_status']);

        return response()->json(['version' => hash('sha256', json_encode([$order->status, $order->payment_status, $order->total, $order->table_name, $order->cancellation_reason, $items])), 'message' => $order->statusLabel().' · '.$order->paymentLabel(), 'status' => $order->statusLabel()])->header('Cache-Control', 'private, no-store');
    }
}
