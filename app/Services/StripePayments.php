<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StripeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StripePayments
{
    public function __construct(private StripeGateway $gateway) {}

    public function start(Order $order): string
    {
        return Cache::lock('stripe-order-'.$order->id, 60)->block(5, function () use ($order) {
            $order = $order->fresh(['items']);
            if ($order->payment_method !== 'stripe' || $order->status !== 'pending' || ! in_array($order->payment_status, ['unpaid', 'pending'], true)) {
                throw ValidationException::withMessages(['payment' => __('Đơn không còn khả dụng để thanh toán.')]);
            }
            if (! $this->gateway->ready()) {
                throw ValidationException::withMessages(['payment' => __('Stripe Test chưa được cấu hình. Vui lòng chọn tiền mặt hoặc liên hệ nhà hàng.')]);
            }
            $payment = DB::transaction(function () use ($order) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->status !== 'pending' || ! in_array($order->payment_status, ['unpaid', 'pending'], true)) {
                    throw ValidationException::withMessages(['payment' => __('Đơn không còn khả dụng để thanh toán.')]);
                }
                $payment = $order->payments()->whereIn('status', ['created', 'open'])->latest('id')->first();
                if (! $payment) {
                    $payment = $order->payments()->create(['attempt_key' => (string) Str::uuid(), 'amount' => $order->total, 'currency' => 'vnd']);
                }
                $order->update(['payment_status' => 'pending']);

                return $payment;
            });
            $session = $payment->stripe_session_id ? $this->gateway->retrieveCheckout($payment->stripe_session_id) : $this->gateway->createCheckout($order, $payment);
            $this->applyCheckout($session);
            if (($session['status'] ?? '') !== 'open' || ! str_starts_with((string) ($session['url'] ?? ''), 'https://checkout.stripe.com/')) {
                throw ValidationException::withMessages(['payment' => __('Phiên thanh toán đã kết thúc. Vui lòng tải lại đơn để xem trạng thái hoặc thử lại.')]);
            }

            return $session['url'];
        });
    }

    public function reconcile(Order $order): void
    {
        $payment = $order->payments()->whereNotNull('stripe_session_id')->latest('id')->first();
        if ($payment) {
            $this->applyCheckout($this->gateway->retrieveCheckout($payment->stripe_session_id));
        }
    }

    public function closeCheckout(Order $order): void
    {
        Cache::lock('stripe-order-'.$order->id, 60)->block(5, function () use ($order) {
            $payment = $order->payments()->whereIn('status', ['created', 'open'])->latest('id')->first();
            if (! $payment) {
                return;
            }
            // Recover a remotely created session after a timeout using the same idempotency key.
            $session = $payment->stripe_session_id ? $this->gateway->retrieveCheckout($payment->stripe_session_id) : $this->gateway->createCheckout($order->load('items'), $payment);
            $this->applyCheckout($session);
            if (($session['status'] ?? '') === 'open') {
                $this->applyCheckout($this->gateway->expireCheckout($session['id']));
            }
        });
    }

    public function refund(Order $order): void
    {
        Cache::lock('stripe-order-'.$order->id, 60)->block(5, function () use ($order) {
            $payment = DB::transaction(function () use ($order) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->status !== 'cancelled' || $order->payment_method !== 'stripe' || ! in_array($order->payment_status, ['paid', 'refund_pending', 'refund_failed'], true)) {
                    throw ValidationException::withMessages(['payment' => __('Chỉ hoàn tiền toàn phần cho đơn Stripe đã thanh toán và đã hủy.')]);
                }
                $payment = $order->payments()->whereNotNull('stripe_payment_intent')->whereIn('status', ['paid', 'refund_pending', 'refund_failed'])->latest('id')->firstOrFail();
                if (! $payment->refund_key || $payment->status === 'refund_failed') {
                    $payment->refund_key = (string) Str::uuid();
                    $payment->refund_id = null;
                }
                $payment->status = 'refund_pending';
                $payment->save();
                $order->update(['payment_status' => 'refund_pending']);

                return $payment;
            });
            $this->applyRefund($this->gateway->refund($payment));
        });
    }

    public function processEvent(array $event): void
    {
        if (($event['livemode'] ?? true) !== false) {
            throw ValidationException::withMessages(['stripe' => 'Test events only.']);
        }
        DB::transaction(function () use ($event) {
            if (StripeEvent::where('event_id', $event['id'])->exists()) {
                return;
            }
            $object = $event['data']['object'];
            match ($event['type']) {
                'checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired' => $this->applyCheckout($object),
                'refund.created','refund.updated','refund.failed' => $this->applyRefund($object),
                'charge.refunded' => $this->applyChargeRefund($object),
                default => null,
            };
            StripeEvent::firstOrCreate(['event_id' => $event['id']], ['type' => $event['type']]);
        }, 3);
    }

    public function applyCheckout(array $session): void
    {
        DB::transaction(function () use ($session) {
            $payment = Payment::find($session['metadata']['payment_id'] ?? 0);
            if (! $payment) {
                return;
            }
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $valid = ($session['livemode'] ?? true) === false && ($session['mode'] ?? '') === 'payment'
            && ($session['client_reference_id'] ?? '') === $order->code && ($session['metadata']['order_code'] ?? '') === $order->code
            && ($session['metadata']['attempt_key'] ?? '') === $payment->attempt_key
            && ($session['currency'] ?? '') === 'vnd' && ($session['amount_total'] ?? -1) === $payment->amount && $payment->amount === $order->total
            && $order->payment_method === 'stripe' && str_starts_with((string) ($session['id'] ?? ''), 'cs_test_')
            && (! $payment->stripe_session_id || $payment->stripe_session_id === $session['id']);
            if (! $valid) {
                throw ValidationException::withMessages(['payment' => __('Dữ liệu thanh toán Stripe không khớp đơn hàng.')]);
            }
            $payment->stripe_session_id = $session['id'];
            if (isset($session['expires_at'])) {
                $payment->expires_at = Carbon::createFromTimestamp($session['expires_at']);
            }
            if (in_array($payment->status, ['paid', 'refund_pending', 'refunded', 'refund_failed', 'partially_refunded'], true)) {
                $payment->save();

                return;
            }
            if (($session['payment_status'] ?? '') === 'paid' && ($session['status'] ?? '') === 'complete') {
                $intent = $session['payment_intent'] ?? null;
                if (! is_string($intent) || ! str_starts_with($intent, 'pi_')) {
                    throw ValidationException::withMessages(['payment' => 'Invalid payment intent.']);
                }
                $payment->stripe_payment_intent = $intent;
                $payment->status = 'paid';
                $payment->paid_at = now();
                $order->payment_status = $order->status === 'cancelled' ? 'refund_pending' : 'paid';
                $order->paid_at = now();
                $order->save();
            } elseif (($session['status'] ?? '') === 'expired') {
                $payment->status = 'expired';
                if ($order->payment_status === 'pending' && ! $order->payments()->where('id', '>', $payment->id)->exists()) {
                    $order->update(['payment_status' => 'unpaid']);
                }
            } else {
                $payment->status = 'open';
            }
            $payment->save();
        }, 3);
    }

    public function applyRefund(array $refund): void
    {
        $payment = Payment::where('stripe_payment_intent', $refund['payment_intent'] ?? '')->first();
        if (! $payment) {
            return;
        }
        DB::transaction(function () use ($payment, $refund) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (($refund['amount'] ?? -1) !== $payment->amount || ($refund['currency'] ?? '') !== 'vnd') {
                return;
            }
            if ($payment->status === 'refunded') {
                return;
            }
            if ($payment->refund_id && $payment->refund_id !== $refund['id'] && $payment->status !== 'refund_failed') {
                return;
            }
            $payment->refund_id = $refund['id'];
            $payment->status = match ($refund['status'] ?? '') {
                'succeeded' => 'refunded','failed','canceled' => 'refund_failed',default => 'refund_pending'
            };
            if ($payment->status === 'refunded') {
                $payment->refunded_amount = $payment->amount;
            }
            $payment->save();
            $order->update(['payment_status' => $payment->status]);
        });
    }

    private function applyChargeRefund(array $charge): void
    {
        $payment = Payment::where('stripe_payment_intent', $charge['payment_intent'] ?? '')->first();
        if (! $payment || ($charge['currency'] ?? '') !== 'vnd') {
            return;
        }
        DB::transaction(function () use ($payment, $charge) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $amount = (int) ($charge['amount_refunded'] ?? 0);
            if ($amount <= $payment->refunded_amount || $amount > $payment->amount) {
                return;
            }
            $status = $amount === $payment->amount ? 'refunded' : 'partially_refunded';
            $payment->update(['refunded_amount' => $amount, 'status' => $status]);
            $order->update(['payment_status' => $status]);
        });
    }
}
