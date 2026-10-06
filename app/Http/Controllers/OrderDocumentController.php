<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Services\OrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderDocumentController extends Controller
{
    public function receipt(Request $request, Order $order): View
    {
        $staff = $request->user();
        $authorized = $staff?->can('printReceipt', $order) ?? false;
        abort_unless($authorized || app(OrderAccess::class)->allows($request, $order), 404);

        return view('orders.receipt', ['order' => $order->load('items'), 'restaurant' => RestaurantSetting::current()]);
    }

    public function requestCancellation(Request $request, Order $order): RedirectResponse
    {
        app(OrderAccess::class)->authorize($request, $order);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['cancelled', 'completed', 'served', 'delivered', 'delivering'], true)) {
                throw ValidationException::withMessages(['reason' => __('Đơn đã kết thúc, không thể yêu cầu hủy.')]);
            }
            if ($order->cancellation_requested_at) {
                return;
            }
            $order->update(['cancellation_requested_at' => now(), 'cancellation_request_reason' => $data['reason']]);
        });

        return back()->with('success', __('Đã gửi yêu cầu hủy. Nhà hàng sẽ xem xét; đơn chỉ được hủy khi nhân viên xác nhận.'));
    }
}
