<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderAlertsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Order::whereNotIn('status', ['completed', 'cancelled']);
        if ($request->user()->role === 'head_chef') {
            $query->whereIn('status', ['confirmed', 'preparing', 'ready']);
        }
        if (! $request->user()->coordinatesOrders() && $request->user()->role !== 'head_chef') {
            $query->whereHas('tasks', fn ($q) => $q->where('assigned_to', $request->user()->id));
        }
        $orders = $query->select(['id', 'status', 'total', 'payment_status', 'updated_at'])->with(['tasks:id,order_id,assigned_to,status', 'items:id,order_id,quantity,preparation_status'])->orderBy('id')->get();

        return response()->json(['version' => hash('sha256', $orders->toJson()), 'message' => __('Danh sách đơn đang xử lý có cập nhật.').' '.__('Đơn đang xử lý: :count', ['count' => $orders->count()])])->header('Cache-Control', 'private, no-store');
    }
}
