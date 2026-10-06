<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeGateway;
use App\Services\TransitionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payment(): Payment
    {
        $order = Order::factory()->create(['payment_method' => 'stripe', 'payment_status' => 'pending']);

        return $order->payments()->create(['attempt_key' => (string) Str::uuid(), 'amount' => $order->total, 'currency' => 'vnd']);
    }

    private function checkoutSession(Payment $payment, array $extra = []): array
    {
        return array_replace(['id' => 'cs_test_'.$payment->id, 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'payment', 'client_reference_id' => $payment->order->code, 'metadata' => ['payment_id' => (string) $payment->id, 'order_code' => $payment->order->code, 'attempt_key' => $payment->attempt_key], 'amount_total' => $payment->amount, 'currency' => 'vnd', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_'.$payment->id], $extra);
    }

    private function webhook(array $object, string $type = 'checkout.session.completed', string $id = 'evt_test_1', bool $valid = true): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_unit']);
        $payload = json_encode(['id' => $id, 'object' => 'event', 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, $valid ? 'whsec_unit' : 'wrong');

        return $this->call('POST', route('stripe.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature"], $payload);
    }

    public function test_signed_webhook_records_payment_once_and_late_expiration_cannot_reverse_it(): void
    {
        $payment = $this->payment();
        $session = $this->checkoutSession($payment);
        $this->webhook($session)->assertOk();
        $paidAt = $payment->order->fresh()->paid_at;
        $this->webhook($session)->assertOk();
        $this->webhook($this->checkoutSession($payment, ['status' => 'expired', 'payment_status' => 'unpaid']), 'checkout.session.expired', 'evt_late')->assertOk();
        $this->assertDatabaseCount('stripe_events', 2);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $payment->order->fresh()->payment_status);
        $this->assertTrue($paidAt->equalTo($payment->order->fresh()->paid_at));
        $this->assertSame('pending', $payment->order->fresh()->status);
    }

    public function test_invalid_signature_and_mismatched_amount_are_rejected(): void
    {
        $payment = $this->payment();
        $this->webhook($this->checkoutSession($payment), valid: false)->assertStatus(400);
        $this->webhook($this->checkoutSession($payment, ['amount_total' => 1]))->assertUnprocessable();
        $this->assertSame('pending', $payment->order->fresh()->payment_status);
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_unpaid_checkout_and_live_session_do_not_mark_order_paid(): void
    {
        $payment = $this->payment();
        $this->webhook($this->checkoutSession($payment, ['payment_status' => 'unpaid']))->assertOk();
        $this->webhook($this->checkoutSession($payment, ['livemode' => true]), id: 'evt_live')->assertUnprocessable();
        $this->assertNull($payment->order->fresh()->paid_at);
        $allowed = app(TransitionOrder::class)->allowed($payment->order->fresh(), User::factory()->create(['role' => 'admin']));
        $this->assertNotContains('confirmed', $allowed);
    }

    public function test_customer_cannot_pay_or_reconcile_another_order_and_success_url_cannot_forge_payment(): void
    {
        $payment = $this->payment();
        $order = $payment->order;
        $this->post(route('payments.store', $order->code))->assertNotFound();
        $this->get(route('payments.return', $order->code))->assertNotFound();
        $this->withSession(['verified_orders' => [$order->id]])->get(route('payments.return', $order->code).'?session_id=cs_test_fake')->assertRedirect();
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_repeated_checkout_reuses_one_attempt_and_uses_server_amount(): void
    {
        $order = Order::factory()->create(['payment_method' => 'stripe']);
        $this->mock(StripeGateway::class, function ($mock) {
            $mock->shouldReceive('ready')->twice()->andReturnTrue();
            $mock->shouldReceive('createCheckout')->once()->andReturnUsing(fn ($order, $payment) => $this->checkoutSession($payment, ['status' => 'open', 'payment_status' => 'unpaid', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']));
            $mock->shouldReceive('retrieveCheckout')->once()->andReturnUsing(fn ($id) => $this->checkoutSession(Payment::firstOrFail(), ['status' => 'open', 'payment_status' => 'unpaid', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']));
        });
        $this->withSession(['verified_orders' => [$order->id]])->post(route('payments.store', $order->code), ['amount' => 1])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $this->post(route('payments.store', $order->code))->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['amount' => 65000]);
    }

    public function test_paid_stripe_order_completes_after_handover_without_cash_checkbox(): void
    {
        $order = Order::factory()->create(['status' => 'ready', 'payment_method' => 'stripe', 'payment_status' => 'paid', 'paid_at' => now()->subHour()]);
        $paidAt = $order->paid_at;
        app(TransitionOrder::class)->handle($order, User::factory()->create(['role' => 'cashier']), ['status' => 'completed', 'expected_status' => 'ready', 'handed_over' => true]);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
    }

    public function test_refund_retry_after_confirmed_failure_uses_new_key_and_accepts_new_refund(): void
    {
        $payment = $this->payment();
        $this->webhook($this->checkoutSession($payment))->assertOk();
        $payment->order->update(['status' => 'cancelled']);
        $payment->refresh()->update(['status' => 'refund_failed', 'refund_id' => 're_old', 'refund_key' => 'old']);
        $payment->order->update(['payment_status' => 'refund_failed']);
        $this->mock(StripeGateway::class, function ($mock) use ($payment) {
            $mock->shouldReceive('refund')->once()->withArgs(fn ($p) => $p->refund_key !== 'old' && $p->refund_id === null)->andReturn(['id' => 're_new', 'payment_intent' => $payment->stripe_payment_intent, 'amount' => 65000, 'currency' => 'vnd', 'status' => 'succeeded']);
        });
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->post(route('payments.refund', $payment->order), ['confirm' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('payments.refund', $payment->order), ['confirm' => 1])->assertRedirect();
        $this->assertSame('refunded', $payment->order->fresh()->payment_status);
        $this->webhook(['id' => 're_new', 'payment_intent' => $payment->stripe_payment_intent, 'amount' => 65000, 'currency' => 'vnd', 'status' => 'pending'], 'refund.updated', 'evt_refund_late')->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
    }

    public function test_payment_state_is_rechecked_under_database_lock_before_creating_checkout(): void
    {
        $order = Order::factory()->create(['payment_method' => 'stripe']);
        $this->mock(StripeGateway::class, function ($mock) use ($order) {
            $mock->shouldReceive('ready')->once()->andReturnUsing(function () use ($order) {
                $order->update(['payment_status' => 'paid', 'paid_at' => now()]);

                return true;
            });
            $mock->shouldNotReceive('createCheckout');
        });
        $this->withSession(['verified_orders' => [$order->id]])->post(route('payments.store', $order->code))->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }
}
