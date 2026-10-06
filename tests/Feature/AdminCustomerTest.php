<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_login_url_renders_not_found_on_a_fresh_request(): void
    {
        $this->get('/dang-nhap')
            ->assertNotFound()
            ->assertSee('Không tìm thấy')
            ->assertSee(route('menu.index'), false);
    }

    public function test_public_pages_never_advertise_internal_login_even_when_manager_is_signed_in(): void
    {
        foreach ([null, User::factory()->create(['role' => 'admin'])] as $user) {
            if ($user) {
                $this->actingAs($user);
            }
            $this->get('/')->assertOk()->assertDontSee(route('login'), false)->assertDontSee(route('staff.orders.index'), false)->assertDontSee(route('admin.staff.index'), false);
        }
    }

    public function test_internal_login_has_its_own_route_and_layout(): void
    {
        $this->assertSame('/nhan-vien/dang-nhap', route('login', [], false));
        $this->get(route('login'))->assertOk()->assertSee('Quản trị &amp; vận hành nhà hàng', false)->assertDontSee(route('cart.index'), false)->assertDontSee(route('customer.login'), false);
        $this->get('/dang-nhap')->assertNotFound();
    }

    public function test_manager_creates_customer_with_fixed_role_and_updates_locks_without_losing_orders(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['name' => 'New customer', 'email' => 'NEW@example.test', 'password' => 'Account-pass-123', 'password_confirmation' => 'Account-pass-123', 'role' => 'admin'];
        $this->post(route('admin.customers.store'), $data)->assertRedirect();
        $customer = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('customer', $customer->role);
        $this->assertTrue(Hash::check('Account-pass-123', $customer->password));
        $order = Order::factory()->create(['user_id' => $customer->id]);
        DB::table('sessions')->insert(['id' => 'customer-session', 'user_id' => $customer->id, 'payload' => 'test', 'last_activity' => time()]);
        $oldToken = $customer->remember_token;
        $this->put(route('admin.customers.update', $customer), ['name' => 'Changed', 'email' => $customer->email, 'phone' => '0901234567', 'is_active' => 0, 'role' => 'admin'])->assertRedirect();
        $this->assertSame('customer', $customer->fresh()->role);
        $this->assertFalse($customer->fresh()->is_active);
        $this->assertNotSame($oldToken, $customer->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'customer-session']);
        $this->assertModelExists($order);
        $this->put(route('admin.customers.update', $customer), ['name' => 'Changed', 'email' => $customer->email, 'is_active' => 1])->assertRedirect();
        $this->assertTrue($customer->fresh()->is_active);
        $this->get(route('admin.customers.edit', $customer))->assertOk()->assertSee('#'.$order->id);
    }

    public function test_customer_search_and_status_filters_exclude_staff(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer', 'name' => 'Customer Example', 'is_active' => false]);
        $this->actingAs($manager)->get(route('admin.customers.index', ['q' => 'Customer Example', 'active' => '0']))->assertOk()->assertViewHas('customers', fn ($users) => $users->count() === 1 && $users->first()->id === $customer->id);
        $this->get(route('admin.customers.edit', $manager))->assertNotFound();
        $this->put(route('admin.customers.update', $manager), [])->assertNotFound();
    }

    public function test_customer_accounts_are_restricted_to_managers_in_both_languages(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        foreach (['cashier', 'head_chef', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson(route('admin.customers.index'))->assertForbidden();
            $this->postJson(route('admin.customers.store'), [])->assertForbidden();
            $this->putJson(route('admin.customers.update', $customer), [])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->withSession(['locale' => 'en'])->get(route('admin.customers.index'))->assertOk()->assertSee('Customer accounts');
        $this->get(route('admin.customers.edit', $customer))->assertOk()->assertSee('Order history');
    }
}
