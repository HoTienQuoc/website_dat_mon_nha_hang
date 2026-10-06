<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_requires_verified_owner_or_authorized_staff(): void
    {
        $order = Order::factory()->create();
        $this->get(route('orders.receipt', $order->code))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'head_chef']))->get(route('orders.receipt', $order->code))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->get(route('orders.receipt', $order->code))->assertOk()->assertSee($order->code)->assertSee('65.000');
    }

    public function test_cancellation_request_is_idempotent_and_does_not_cancel_or_refund_automatically(): void
    {
        $order = Order::factory()->create(['payment_method' => 'stripe', 'payment_status' => 'paid']);
        $url = route('orders.cancel-request', $order->code);
        $this->post($url, ['reason' => 'Changed my plans'])->assertNotFound();
        $this->withSession(['verified_orders' => [$order->id]])->post($url, ['reason' => 'Changed my plans'])->assertRedirect();
        $time = $order->fresh()->cancellation_requested_at;
        $this->post($url, ['reason' => 'Second request'])->assertRedirect();
        $this->assertSame('Changed my plans', $order->fresh()->cancellation_request_reason);
        $this->assertTrue($time->equalTo($order->fresh()->cancellation_requested_at));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->get(route('orders.receipt', $order->code))->assertOk();
    }

    public function test_closed_order_cannot_request_cancellation(): void
    {
        $order = Order::factory()->create(['status' => 'completed']);
        $this->withSession(['verified_orders' => [$order->id]])->post(route('orders.cancel-request', $order->code), ['reason' => 'Cancel please'])->assertSessionHasErrors('reason');
    }
}
