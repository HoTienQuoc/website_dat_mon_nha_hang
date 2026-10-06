<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalizationAndSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_switch_persists_and_menu_searches_english_names(): void
    {
        Dish::factory()->create(['name' => 'Cơm gà', 'name_en' => 'Chicken rice', 'description_en' => 'Steamed rice with chicken']);
        $this->post(route('locale.update'), ['locale' => 'en'])->assertRedirect()->assertSessionHas('locale', 'en');
        $this->get('/?q=Chicken')->assertOk()->assertHeader('Content-Language', 'en')->assertSee('Chicken rice')->assertSee('Steamed rice with chicken');
        $this->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->post(route('locale.update'), ['locale' => 'vi']);
        $this->get('/')->assertSee('Cơm gà');
    }

    public function test_demo_seed_is_meaningful_repeatable_and_preserves_existing_changes(): void
    {
        Storage::fake('public');
        config(['restaurant.demo_password' => 'Test-password-123']);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('dishes', 18);
        $this->assertDatabaseCount('categories', 6);
        $this->assertDatabaseCount('orders', 52);
        $this->assertSame(52, Order::where('is_demo', true)->count());
        $this->assertDatabaseCount('payments', 0);
        foreach (Order::with('items', 'histories')->where('demo_key', 'like', 'restaurant-demo-v1-%')->get() as $sample) {
            $expectedPreparation = match ($sample->status) {
                'completed' => 'served', 'ready' => 'ready', 'preparing' => 'preparing', 'cancelled' => 'cancelled', default => 'pending',
            };
            $this->assertTrue($sample->items->every(fn ($item) => $item->preparation_status === $expectedPreparation));
            if ($sample->fulfillment_type === 'delivery') {
                $this->assertSame('Nha Trang', $sample->delivery_ward);
                $this->assertStringContainsString('Nha Trang', $sample->address);
                if ($sample->status === 'completed') {
                    $this->assertTrue($sample->histories->contains('to_status', 'delivered'));
                }
            }
        }
        $dish = Dish::firstOrFail();
        $dish->update(['price' => 99000]);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('orders', 52);
        $this->assertSame(99000, $dish->fresh()->price);
        foreach (Order::with('items')->get() as $order) {
            $this->assertSame($order->total, $order->items->sum(fn ($item) => $item->unit_price * $item->quantity) + $order->delivery_fee);
        }
        $this->assertSame(0, Dish::whereNull('name_en')->count());
    }

    public function test_manager_pages_render_in_english(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->withSession(['locale' => 'en']);
        foreach (['admin.settings.edit', 'admin.dishes.index', 'admin.categories.index', 'admin.zones.index', 'admin.staff.index', 'admin.revenue', 'staff.orders.index'] as $route) {
            $this->get(route($route))->assertOk()->assertHeader('Content-Language', 'en');
        }
    }

    public function test_closed_restaurant_rejects_checkout(): void
    {
        RestaurantSetting::create(['id' => 1, 'name' => 'Test', 'name_en' => 'Test', 'address' => 'Test', 'address_en' => 'Test', 'phone' => '0900000000', 'opens_at' => '09:00', 'closes_at' => '22:00', 'accepting_orders' => false]);
        $dish = Dish::factory()->create();
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), ['customer_name' => 'Test', 'customer_phone' => '0901234567', 'fulfillment_type' => 'pickup'])->assertSessionHasErrors();
        $this->assertDatabaseCount('orders', 0);
    }
}
