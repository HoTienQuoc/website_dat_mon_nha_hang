<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    public function test_registration_forces_customer_role_and_never_grants_staff_access(): void
    {
        $this->post(route('customer.register.store'), ['name' => 'Customer', 'email' => 'CUSTOMER@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123', 'role' => 'admin', 'is_active' => 0])->assertRedirect(route('customer.account'));
        $user = User::firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('Strong-password-123', $user->password));
        $this->assertSame('customer@example.test', $user->email);
        $this->get(route('customer.account'))->assertOk();
        $this->getJson(route('staff.orders.index'))->assertForbidden();
        $this->postJson(route('admin.dishes.store'), [])->assertForbidden();
    }

    public function test_customer_login_remember_cookie_and_logout(): void
    {
        $user = $this->customer();
        $this->post(route('customer.login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $response = $this->post(route('customer.login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 1]);
        $response->assertRedirect(route('customer.account'))->assertCookie(auth()->guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('menu.index'));
        $this->assertGuest();
        $this->get(route('customer.account'))->assertRedirect(route('customer.login'));
    }

    public function test_customer_and_staff_login_ports_do_not_cross_roles(): void
    {
        $customer = $this->customer();
        $staff = User::factory()->create(['role' => 'cashier']);
        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('customer.login.store'), ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_profile_cannot_modify_role_email_or_active_flag(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->patch(route('customer.update'), ['name' => 'New name', 'phone' => '090 123 4567', 'default_address' => '12 Test Street', 'role' => 'admin', 'email' => 'changed@example.test', 'is_active' => false])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => '0901234567', 'role' => 'customer', 'email' => $user->email, 'is_active' => true]);
    }

    public function test_account_history_and_documents_only_show_owned_orders(): void
    {
        $customer = $this->customer();
        $own = Order::factory()->create(['user_id' => $customer->id]);
        $other = Order::factory()->create(['user_id' => $this->customer()->id]);
        $this->actingAs($customer)->get(route('customer.account'))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 1 && $orders->first()->id === $own->id);
        $this->get(route('orders.show', $own->code))->assertOk();
        $this->get(route('orders.receipt', $own->code))->assertOk();
        $this->withSession(['verified_orders' => [$other->id]])->get(route('orders.show', $other->code))->assertNotFound();
        $this->get(route('orders.receipt', $other->code))->assertNotFound();
        $this->post(route('payments.store', $other->code))->assertNotFound();
        $this->post(route('orders.cancel-request', $other->code), ['reason' => 'Try cancelling'])->assertNotFound();
    }

    public function test_signed_in_checkout_associates_account_on_server_and_guest_can_order_by_email_only(): void
    {
        $dish = Dish::factory()->create();
        $customer = $this->customer();
        $data = ['customer_name' => 'Email guest', 'customer_email' => 'GUEST@example.test', 'fulfillment_type' => 'pickup', 'user_id' => $customer->id];
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $data)->assertRedirect();
        $key = array_key_last(session('checkout_drafts'));
        $this->get(route('checkout.confirm', $key))->assertOk()->assertSee('guest@example.test');
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertRedirect();
        $guest = Order::firstOrFail();
        $this->assertNull($guest->user_id);
        $this->assertNull($guest->customer_phone);
        $this->assertSame('guest@example.test', $guest->customer_email);
        $this->actingAs($customer)->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $data);
        $key = array_key_last(session('checkout_drafts'));
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertRedirect();
        $this->assertSame($customer->id, Order::latest('id')->first()->user_id);
    }

    public function test_email_lookup_matches_exact_contact_and_does_not_attach_orders_to_an_account(): void
    {
        $order = Order::factory()->create(['customer_phone' => null, 'customer_email' => 'guest@example.test']);
        $this->post(route('orders.lookup.submit'), ['code' => $order->code, 'contact' => 'wrong@example.test'])->assertSessionHasErrors('lookup');
        $this->post(route('orders.lookup.submit'), ['code' => $order->code, 'contact' => 'GUEST@example.test'])->assertRedirect(route('orders.show', $order->code));
        $this->get(route('orders.show', $order->code))->assertOk();
        $this->assertNull($order->fresh()->user_id);
        $owned = Order::factory()->create(['user_id' => $this->customer()->id, 'customer_email' => 'guest@example.test']);
        $this->post(route('orders.lookup.submit'), ['code' => $owned->code, 'contact' => 'guest@example.test'])->assertSessionHasErrors('lookup');
    }

    public function test_password_change_requires_current_password_and_revokes_session(): void
    {
        $user = $this->customer();
        $this->actingAs($user);
        $data = ['current_password' => 'wrong', 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'];
        $this->put(route('customer.password'), $data)->assertSessionHasErrors('current_password');
        $this->put(route('customer.password'), [...$data, 'current_password' => 'password'])->assertRedirect(route('customer.login'));
        $this->assertGuest();
        $this->assertTrue(Hash::check('New-password-123', $user->fresh()->password));
    }

    public function test_language_cookie_is_persistent_and_only_vi_en_are_allowed(): void
    {
        $response = $this->post(route('locale.update'), ['locale' => 'en']);
        $response->assertCookie('restaurant_locale', 'en');
        $cookie = $response->getCookie('restaurant_locale');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->flushSession();
        $this->withCookie('restaurant_locale', 'en')->get('/')->assertHeader('Content-Language', 'en');
        $this->flushSession();
        $this->withCookie('restaurant_locale', 'fr')->get('/')->assertHeader('Content-Language', 'vi');
        $this->withCookie('role', 'manager')->getJson(route('admin.dishes.index'))->assertUnauthorized();
    }

    public function test_staff_management_cannot_promote_customer_and_inactive_customer_loses_access(): void
    {
        $customer = $this->customer();
        $manager = User::factory()->create(['role' => 'admin']);
        $this->actingAs($manager)->get(route('admin.staff.index'))->assertOk()->assertDontSee($customer->email);
        $this->put(route('admin.staff.update', $customer), ['name' => $customer->name, 'email' => $customer->email, 'role' => 'admin', 'is_active' => 1])->assertNotFound();
        $customer->is_active = false;
        $customer->save();
        $this->actingAs($customer)->getJson(route('customer.account'))->assertForbidden();
    }
}
