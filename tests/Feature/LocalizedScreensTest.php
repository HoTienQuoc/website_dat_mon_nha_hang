<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalizedScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_management_screens_render_in_both_languages(): void
    {
        Storage::fake('public');
        config(['restaurant.demo_password' => 'Local-testing-123!']);
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('role', 'admin')->firstOrFail();
        $dish = Dish::firstOrFail();
        $order = Order::factory()->create(['payment_method' => 'demo_card']);
        $order->items()->create(['dish_name' => 'Món thử', 'dish_name_en' => 'Test dish', 'unit_price' => 65000, 'quantity' => 1]);
        foreach (['vi', 'en'] as $locale) {
            auth()->logout();
            $this->withSession(['locale' => $locale, 'cart' => [$dish->id => 1], 'verified_orders' => [$order->id]]);
            foreach (['menu.index', 'cart.index', 'checkout.create', 'orders.lookup', 'customer.login', 'customer.register', 'customer.password.request', 'login'] as $route) {
                $this->get(route($route))->assertOk()->assertHeader('Content-Language', $locale)->assertDontSee('@endif', false)->assertDontSee('Undefined variable');
            }
            $this->get(route('orders.show', $order->code))->assertOk()->assertSee($locale === 'en' ? 'Test dish' : 'Món thử');
            $this->get(route('demo-payments.create', $order->code))->assertOk();
            $this->actingAs($owner);
            foreach (['admin.dishes.index', 'admin.dishes.create', 'admin.categories.index', 'admin.zones.index', 'admin.customers.index', 'admin.staff.index', 'admin.settings.edit', 'admin.revenue', 'admin.audit', 'staff.orders.index', 'staff.tables.index', 'staff.pos.create', 'staff.kitchen.index', 'staff.shifts.index'] as $route) {
                $this->get(route($route))->assertOk()->assertHeader('Content-Language', $locale)->assertDontSee('@endif', false);
            }
            $this->get(route('admin.dishes.edit', $dish))->assertOk();
            $this->get(route('staff.orders.show', $order))->assertOk();
        }
    }

    public function test_each_staff_role_can_open_its_assignment_and_language_specific_navigation(): void
    {
        foreach (array_keys(User::STAFF_ROLES) as $role) {
            $user = User::factory()->create(['role' => $role]);
            $order = Order::factory()->create(['status' => 'confirmed', 'fulfillment_type' => $role === 'courier' ? 'delivery' : 'dine_in']);
            $kind = match ($role) {
                'chef','head_chef' => 'cooking','kitchen_assistant' => 'preparation','courier' => 'delivery',default => 'service'
            };
            $order->tasks()->create(['kind' => $kind, 'assigned_to' => $user->id, 'assigned_by' => $user->id, 'status' => 'pending']);
            foreach (['vi', 'en'] as $locale) {
                $this->actingAs($user)->withSession(['locale' => $locale])->get(route('staff.orders.index'))->assertOk()->assertSee(__(User::STAFF_ROLES[$role], locale: $locale));
                $response = $this->get(route('staff.orders.show', $order))->assertOk()->assertHeader('Content-Language', $locale);
                if ($user->isKitchen()) {
                    $response->assertDontSee('0901234567');
                }
            }
        }
    }
}
