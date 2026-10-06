<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DemoPaymentController extends Controller
{
    public function create(Request $request, Order $order): View|RedirectResponse
    {
        app(OrderAccess::class)->authorize($request, $order);
        abort_unless($order->payment_method === 'demo_card', 404);
        if ($order->payment_status === 'paid') {
            return redirect()->route('orders.show', $order->code);
        }
        abort_unless($order->status === 'pending', 409);

        return view('payments.demo', ['order' => $order]);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        app(OrderAccess::class)->authorize($request, $order);
        abort_unless($order->payment_method === 'demo_card', 404);
        $input = $request->only(['cardholder', 'card_number', 'expiry', 'cvc', 'email', 'billing_address', 'country', 'postal_code']);
        if (is_string($input['card_number'] ?? null)) {
            $input['card_number'] = str_replace(' ', '', $input['card_number']);
        }
        $validator = Validator::make($input, [
            'cardholder' => 'required|string|min:2|max:100', 'email' => 'required|email|max:255',
            'card_number' => ['required', 'regex:/^[0-9]{13,19}$/', function ($attribute, $value, $fail) {
                if (! is_string($value) || ! ctype_digit($value)) {
                    return;
                }
                $sum = 0;
                $digits = strrev($value);
                for ($i = 0; $i < strlen($digits); $i++) {
                    $n = (int) $digits[$i];
                    if ($i % 2 === 1) {
                        $n *= 2;
                        if ($n > 9) {
                            $n -= 9;
                        }
                    } $sum += $n;
                }
                if ($sum % 10 !== 0 || (int) $value === 0) {
                    $fail(__('Số thẻ không đúng định dạng kiểm tra.'));
                }
            }],
            'expiry' => ['required', 'regex:/^(0[1-9]|1[0-2])\/[0-9]{2}$/', function ($attribute, $value, $fail) {
                if (! is_string($value) || ! preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $value, $m)) {
                    return;
                }
                if ((2000 + (int) $m[2]) * 100 + (int) $m[1] < (int) now()->format('Ym')) {
                    $fail(__('Thẻ đã hết hạn.'));
                }
            }],
            'cvc' => ['required', 'regex:/^[0-9]{3,4}$/'], 'billing_address' => 'required|string|min:8|max:255', 'country' => 'required|in:VN', 'postal_code' => ['required', 'regex:/^[0-9]{5}$/'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status === 'paid') {
                return;
            }
            abort_unless($order->status === 'pending' && $order->payment_status === 'unpaid', 409);
            $order->payments()->create(['attempt_key' => (string) Str::uuid(), 'provider' => 'demo', 'amount' => $order->total, 'currency' => 'vnd', 'status' => 'paid', 'paid_at' => now()]);
            $order->update(['payment_status' => 'paid', 'paid_at' => now(), 'is_demo' => true]);
            $order->histories()->create(['from_status' => $order->status, 'to_status' => $order->status, 'reason' => __('Thanh toán mô phỏng thành công — không thu tiền thật.')]);
        }, 3);

        return redirect()->route('orders.show', $order->code)->with('success', __('Thanh toán mô phỏng thành công — không thu tiền thật.'));
    }

    public function refund(Request $request, Order $order): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->payment_method === 'demo_card' && $order->status === 'cancelled', 409);
            if ($order->payment_status === 'refunded') {
                return;
            }
            abort_unless($order->payment_status === 'paid', 409);
            $order->payments()->where('provider', 'demo')->where('status', 'paid')->get()->each(fn ($p) => $p->update(['status' => 'refunded', 'refunded_amount' => $p->amount]));
            $order->update(['payment_status' => 'refunded']);
        });

        return back()->with('success', __('Đã hoàn tiền mô phỏng.'));
    }
}
