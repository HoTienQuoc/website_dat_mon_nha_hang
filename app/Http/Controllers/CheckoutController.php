<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Services\Cart;
use App\Services\OrderAccess;
use App\Services\PlaceOrder;
use App\Services\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(Request $request, Cart $cart): View|RedirectResponse
    {
        if (! $request->session()->get('cart')) {
            return redirect()->route('cart.index')->withErrors(['cart' => __('Giỏ hàng đang trống.')]);
        }

        return view('checkout.create', ['zones' => DeliveryZone::where('service_city', DeliveryZone::SERVICE_CITY)->where('is_active', true)->orderBy('name')->get(), 'lines' => $cart->lines($request->session()->get('cart', []))]);
    }

    public function review(CheckoutRequest $request, Cart $cart): RedirectResponse
    {
        $data = $request->validated();
        $quantities = $request->session()->get('cart', []);
        $lines = $cart->lines($quantities, true);
        $zone = $data['fulfillment_type'] === 'delivery' ? DeliveryZone::findOrFail($data['delivery_zone_id']) : null;
        RestaurantSetting::current()->assertCanOrder((int) $lines->sum('line_total'));
        if (($data['payment_method'] ?? 'cash') === 'stripe' && ! app(StripeGateway::class)->ready()) {
            throw ValidationException::withMessages(['payment' => __('Stripe Test chưa được cấu hình. Vui lòng chọn tiền mặt hoặc liên hệ nhà hàng.')]);
        }
        $total = (int) $lines->sum('line_total') + ($zone?->fee ?? 0);
        $key = (string) Str::uuid();
        $drafts = $request->session()->get('checkout_drafts', []);
        $drafts[$key] = ['data' => $data, 'cart' => $quantities, 'total' => $total, 'created_at' => time(), 'lines' => $lines->map(fn ($line) => ['name' => $line['dish']->display_name, 'quantity' => $line['quantity'], 'line_total' => $line['line_total']])->all(), 'zone' => $zone ? ['name' => $zone->display_name, 'fee' => $zone->fee] : null];
        $request->session()->put('checkout_drafts', array_slice($drafts, -10, null, true));
        if (! $request->session()->has('checkout_owner')) {
            $request->session()->put('checkout_owner', Str::random(64));
        }

        return redirect()->route('checkout.confirm', $key);
    }

    public function confirm(Request $request, string $key): View
    {
        $draft = $request->session()->get('checkout_drafts.'.$key);
        abort_unless($draft, 404);

        return view('checkout.review', ['data' => $draft['data'], 'lines' => $draft['lines'], 'zone' => $draft['zone'], 'total' => $draft['total'], 'key' => $key]);
    }

    public function store(Request $request, PlaceOrder $placeOrder): RedirectResponse
    {
        $data = $request->validate(['idempotency_key' => 'required|uuid']);
        $key = $data['idempotency_key'];
        $draft = $request->session()->get('checkout_drafts.'.$key);
        abort_unless($draft && $request->session()->has('checkout_owner'), 419, __('Phiên đặt món đã hết hạn.'));
        if (time() - $draft['created_at'] > 3600) {
            return redirect()->route('checkout.create')->withErrors(['order' => __('Phiên xác nhận đã hết hạn. Vui lòng kiểm tra lại đơn.')]);
        }
        $order = $placeOrder->handle($draft['data'], $draft['cart'], $key, hash('sha256', $request->session()->get('checkout_owner')), $draft['total'], $request->user()?->role === 'customer' && $request->user()->is_active ? $request->user()->id : null);
        $ids = $request->session()->get('verified_orders', []);
        $ids[] = $order->id;
        $request->session()->put('verified_orders', array_slice(array_unique($ids), -30));
        if ($request->session()->get('cart') === $draft['cart']) {
            $request->session()->forget('cart');
        }

        return redirect()->route('orders.show', $order->code)->with('success', __('Đã gửi đơn. Nhà hàng sẽ xác nhận sớm.'));
    }

    public function show(Request $request, Order $order): View
    {
        app(OrderAccess::class)->authorize($request, $order);

        return view('orders.show', ['order' => $order->load(['items', 'histories', 'payments'])]);
    }
}
