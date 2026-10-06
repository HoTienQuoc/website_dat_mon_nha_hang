<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class StripeGateway
{
    public function ready(): bool
    {
        return str_starts_with((string) config('services.stripe.secret'), 'sk_test_') && str_starts_with((string) config('services.stripe.webhook_secret'), 'whsec_');
    }

    private function client(): StripeClient
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['payment' => __('Stripe Test chưa được cấu hình. Vui lòng chọn tiền mặt hoặc liên hệ nhà hàng.')]);
        }
        $http = new CurlClient;
        $http->setTimeout(20);
        $http->setConnectTimeout(10);
        ApiRequestor::setHttpClient($http);

        return new StripeClient(config('services.stripe.secret'));
    }

    public function createCheckout(Order $order, Payment $payment): array
    {
        $lines = $order->items->map(fn ($item) => ['price_data' => ['currency' => 'vnd', 'unit_amount' => $item->unit_price, 'product_data' => ['name' => $order->locale === 'en' ? ($item->dish_name_en ?: $item->dish_name) : $item->dish_name]], 'quantity' => $item->quantity])->all();
        if ($order->delivery_fee > 0) {
            $lines[] = ['price_data' => ['currency' => 'vnd', 'unit_amount' => $order->delivery_fee, 'product_data' => ['name' => $order->locale === 'en' ? 'Delivery fee' : 'Phí giao hàng']], 'quantity' => 1];
        }
        $data = ['mode' => 'payment', 'payment_method_types' => ['card'], 'line_items' => $lines, 'client_reference_id' => $order->code,
            'metadata' => ['order_code' => $order->code, 'payment_id' => (string) $payment->id, 'attempt_key' => $payment->attempt_key],
            'payment_intent_data' => ['metadata' => ['order_code' => $order->code, 'payment_id' => (string) $payment->id]],
            'success_url' => route('payments.return', $order->code).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.show', $order->code), 'locale' => $order->locale === 'en' ? 'en' : 'auto',
        ];
        if ($order->customer_email) {
            $data['customer_email'] = $order->customer_email;
        }

        return $this->client()->checkout->sessions->create($data, ['idempotency_key' => 'checkout-'.$payment->attempt_key])->toArray();
    }

    public function retrieveCheckout(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id, [])->toArray();
    }

    public function expireCheckout(string $id): array
    {
        return $this->client()->checkout->sessions->expire($id, [])->toArray();
    }

    public function refund(Payment $payment): array
    {
        return $this->client()->refunds->create(['payment_intent' => $payment->stripe_payment_intent, 'amount' => $payment->amount, 'metadata' => ['payment_id' => (string) $payment->id]], ['idempotency_key' => 'refund-'.$payment->refund_key])->toArray();
    }
}
