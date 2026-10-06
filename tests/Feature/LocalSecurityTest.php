<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_only_endpoints_reject_every_other_role(): void
    {
        foreach (['manager', 'cashier', 'head_chef', 'chef', 'kitchen_assistant', 'waiter', 'courier', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['admin.settings.edit', 'admin.revenue', 'admin.revenue.export', 'admin.audit'] as $route) {
                $this->getJson(route($route))->assertForbidden();
            }
        }
    }

    public function test_role_assignment_revokes_database_sessions_and_cannot_be_escalated_by_manager(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $waiter = User::factory()->create(['role' => 'waiter']);
        DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $waiter->id, 'payload' => 'test', 'last_activity' => time()]);
        $data = ['name' => $waiter->name, 'email' => $waiter->email, 'role' => 'admin', 'is_active' => 1];
        $this->actingAs($manager)->put(route('admin.staff.update', $waiter), $data)->assertSessionHasErrors('role');
        $this->assertSame('waiter', $waiter->fresh()->role);
        $this->actingAs($owner)->put(route('admin.staff.update', $waiter), [...$data, 'role' => 'waiter', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['user_id' => $waiter->id]);
        $this->actingAs($waiter->fresh())->getJson(route('staff.orders.index'))->assertForbidden();
    }

    public function test_untrusted_customer_text_is_escaped_and_cannot_set_order_payment_or_owner(): void
    {
        $dish = Dish::factory()->create(['price' => 65000]);
        $customer = User::factory()->create(['role' => 'customer']);
        $injection = '<script>alert("xss")</script>';
        $this->actingAs($customer)->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), ['customer_name' => $injection, 'customer_email' => $customer->email, 'fulfillment_type' => 'pickup', 'payment_method' => 'cash', 'note' => $injection, 'total' => 1, 'payment_status' => 'paid', 'user_id' => 999, 'is_demo' => false])->assertSessionHasNoErrors();
        $key = array_key_last(session('checkout_drafts'));
        $this->post(route('orders.store'), ['idempotency_key' => $key, 'total' => 1, 'payment_status' => 'paid'])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame(65000, $order->total);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame($customer->id, $order->user_id);
        $this->get(route('orders.show', $order->code))->assertOk()->assertDontSee($injection, false)->assertSee('&lt;script&gt;', false);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('staff.orders.show', $order))->assertOk()->assertDontSee($injection, false);
    }

    public function test_guest_access_cannot_be_forged_and_login_spaces_are_separate(): void
    {
        $order = Order::factory()->create();
        $this->withCookie('verified_orders', (string) $order->id)->getJson(route('orders.show', $order->code))->assertNotFound();
        $this->getJson(route('orders.receipt', $order->code))->assertNotFound();
        $customer = User::factory()->create(['role' => 'customer']);
        $staff = User::factory()->create(['role' => 'cashier']);
        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('customer.login.store'), ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->get('/missing-local-page')->assertNotFound()->assertDontSee('Undefined variable');
    }

    public function test_invalid_card_arrays_expiry_and_cvc_never_change_payment_or_flash_sensitive_data(): void
    {
        $order = Order::factory()->create(['payment_method' => 'demo_card']);
        $this->withSession(['verified_orders' => [$order->id]]);
        $base = ['cardholder' => 'Local Demo', 'card_number' => '4242424242424242', 'expiry' => now()->addYear()->format('m/y'), 'cvc' => '123', 'email' => 'demo@example.test', 'billing_address' => '12 Duong Mau Nha Trang', 'country' => 'VN', 'postal_code' => '65000'];
        foreach ([['card_number' => ['bad']], ['expiry' => ['bad']], ['cvc' => ['bad']], ['postal_code' => ['bad']]] as $bad) {
            $this->post(route('demo-payments.store', $order->code), [...$base, ...$bad])->assertSessionHasErrors(array_keys($bad))->assertSessionMissing('_old_input.card_number')->assertSessionMissing('_old_input.cvc');
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_card_validation_messages_follow_the_selected_language(): void
    {
        $order = Order::factory()->create(['payment_method' => 'demo_card']);
        foreach (['vi' => 'tên trên thẻ', 'en' => 'cardholder name'] as $locale => $label) {
            $this->withSession(['locale' => $locale, 'verified_orders' => [$order->id]])
                ->post(route('demo-payments.store', $order->code), [])->assertSessionHasErrors('cardholder');
            $this->assertStringContainsString($label,session('errors')->first('cardholder'));
        }
    }
}
