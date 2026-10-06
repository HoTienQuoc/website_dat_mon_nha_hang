<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderAccess;
use App\Services\StripePayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;

class PaymentController extends Controller
{
    private function authorizeCustomer(Request $request, Order $order): void
    {
        app(OrderAccess::class)->authorize($request, $order);
    }

    public function store(Request $request, Order $order, StripePayments $payments): RedirectResponse
    {
        $this->authorizeCustomer($request, $order);
        try {
            return redirect()->away($payments->start($order));
        } catch (ApiErrorException $e) {
            return back()->withErrors(['payment' => __('Không kết nối được Stripe. Đơn của bạn được giữ lại, vui lòng thử lại.')]);
        }
    }

    public function returned(Request $request, Order $order, StripePayments $payments): RedirectResponse
    {
        $this->authorizeCustomer($request, $order);
        try {
            $payments->reconcile($order);
        } catch (ApiErrorException $e) {
            return redirect()->route('orders.show', $order->code)->withErrors(['payment' => __('Đang chờ xác nhận từ Stripe. Vui lòng kiểm tra lại sau.')]);
        }

        return redirect()->route('orders.show', $order->code);
    }

    public function sync(Request $request, Order $order, StripePayments $payments): RedirectResponse
    {
        try {
            $payments->reconcile($order);
        } catch (ApiErrorException $e) {
            return back()->withErrors(['payment' => __('Không kết nối được Stripe. Vui lòng thử lại.')]);
        }

        return back()->with('success', __('Đã đối chiếu thanh toán với Stripe.'));
    }

    public function expire(Request $request, Order $order, StripePayments $payments): RedirectResponse
    {
        try {
            $payments->closeCheckout($order);
        } catch (ApiErrorException $e) {
            return back()->withErrors(['payment' => __('Không đóng được phiên Stripe. Vui lòng đối chiếu lại trước khi hủy đơn.')]);
        }

        return back()->with('success', __('Đã kiểm tra và đóng phiên thanh toán còn mở.'));
    }

    public function refund(Request $request, Order $order, StripePayments $payments): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        try {
            $payments->refund($order);
        } catch (ApiErrorException $e) {
            return back()->withErrors(['payment' => __('Stripe chưa xác nhận hoàn tiền. Vui lòng đối chiếu hoặc thử lại cùng yêu cầu.')]);
        }

        return back()->with('success', __('Đã gửi yêu cầu hoàn tiền và cập nhật trạng thái từ Stripe.'));
    }
}
