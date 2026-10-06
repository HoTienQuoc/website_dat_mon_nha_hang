<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_manager_can_create_update_and_delete_dish_without_changing_order_snapshot(): void
    {
        $category = Category::factory()->create();
        $this->actingAs($this->manager());
        $this->post(route('admin.dishes.store'), ['name' => 'Cơm mới', 'category_id' => $category->id, 'price' => 50000, 'is_available' => 1])->assertRedirect(route('admin.dishes.index'));
        $dish = Dish::firstOrFail();
        $order = Order::factory()->create();
        $order->items()->create(['dish_id' => $dish->id, 'dish_name' => $dish->name, 'unit_price' => 50000, 'quantity' => 1]);
        $this->put(route('admin.dishes.update', $dish), ['name' => 'Cơm đổi tên', 'category_id' => $category->id, 'price' => 70000, 'is_available' => 0])->assertRedirect();
        $this->assertDatabaseHas('dishes', ['id' => $dish->id, 'name' => 'Cơm đổi tên', 'price' => 70000, 'is_available' => 0]);
        $this->assertDatabaseHas('order_items', ['dish_id' => $dish->id, 'dish_name' => 'Cơm mới', 'unit_price' => 50000]);
        $this->delete(route('admin.dishes.destroy', $dish))->assertRedirect();
        $this->assertModelMissing($dish);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'dish_id' => null, 'dish_name' => 'Cơm mới', 'unit_price' => 50000]);
    }

    public function test_category_crud_rejects_deletion_of_nonempty_group(): void
    {
        $this->actingAs($this->manager())->post(route('admin.categories.store'), ['name' => 'Món chay'])->assertRedirect();
        $category = Category::firstOrFail();
        $this->put(route('admin.categories.update', $category), ['name' => 'Món chay mới'])->assertRedirect();
        $this->assertSame('Món chay mới', $category->fresh()->name);
        $dish = Dish::factory()->for($category)->create();
        $this->delete(route('admin.categories.destroy', $category))->assertSessionHasErrors('category');
        $this->assertModelExists($category);
        $dish->delete();
        $this->delete(route('admin.categories.destroy', $category))->assertRedirect();
        $this->assertModelMissing($category);
    }

    public function test_zone_crud_preserves_used_zones(): void
    {
        $this->actingAs($this->manager())->post(route('admin.zones.store'), ['name' => 'Vùng mới', 'fee' => 10000, 'is_active' => 1])->assertRedirect();
        $zone = DeliveryZone::firstOrFail();
        $this->put(route('admin.zones.update', $zone), ['name' => 'Vùng mới', 'fee' => 12000, 'is_active' => 0])->assertRedirect();
        $this->assertSame(12000, $zone->fresh()->fee);
        Order::factory()->create(['delivery_zone_id' => $zone->id]);
        $this->delete(route('admin.zones.destroy', $zone))->assertSessionHasErrors('zone');
        $this->assertModelExists($zone);
        $empty = DeliveryZone::factory()->create();
        $this->delete(route('admin.zones.destroy', $empty))->assertRedirect();
        $this->assertModelMissing($empty);
    }

    public function test_upload_accepts_image_and_rejects_executable_content(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create();
        $this->actingAs($this->manager());
        $data = ['name' => 'Món có ảnh', 'category_id' => $category->id, 'price' => 50000, 'is_available' => 1];
        $this->post(route('admin.dishes.store'), [...$data, 'image' => UploadedFile::fake()->create('bad.php', 1, 'application/x-php')])->assertSessionHasErrors('image');
        $png = UploadedFile::fake()->createWithContent('dish.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG4sAAAAASUVORK5CYII='));
        $this->post(route('admin.dishes.store'), [...$data, 'image' => $png])->assertRedirect();
        $dish = Dish::firstOrFail();
        Storage::disk('public')->assertExists($dish->image_path);
        $this->delete(route('admin.dishes.destroy', $dish));
        Storage::disk('public')->assertMissing($dish->image_path);
    }

    public function test_invalid_price_cannot_be_saved_and_cashier_cannot_mutate(): void
    {
        $category = Category::factory()->create();
        $data = ['name' => 'Món lỗi', 'category_id' => $category->id, 'price' => -100, 'is_available' => 1];
        $this->actingAs($this->manager())->post(route('admin.dishes.store'), $data)->assertSessionHasErrors('price');
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->postJson(route('admin.dishes.store'), [...$data, 'price' => 50000])->assertForbidden();
        $this->assertDatabaseCount('dishes', 0);
    }

    public function test_revenue_counts_only_paid_completed_orders_in_requested_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 21)->startOfDay());
        Order::factory()->create(['status' => 'completed', 'payment_status' => 'paid', 'subtotal' => 100000, 'delivery_fee' => 15000, 'total' => 115000, 'paid_at' => '2026-09-20 15:00:00', 'completed_at' => '2026-09-20 15:00:00']);
        Order::factory()->create(['status' => 'cancelled', 'total' => 200000, 'paid_at' => now(), 'completed_at' => now()]);
        Order::factory()->create(['status' => 'completed', 'total' => 300000, 'completed_at' => now(), 'paid_at' => null]);
        Order::factory()->create(['status' => 'completed', 'paid_at' => '2026-08-01', 'completed_at' => '2026-08-01']);
        $this->actingAs($this->manager())->get(route('admin.revenue', ['from' => '2026-09-01', 'to' => '2026-09-21']))->assertOk()->assertViewHas('summary', fn ($s) => (int) $s->revenue === 115000 && (int) $s->order_count === 1 && (int) $s->delivery_total === 15000)->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
    }

    public function test_admin_pages_render_and_invalid_date_range_is_rejected(): void
    {
        $dish = Dish::factory()->create();
        $this->actingAs($this->manager());
        foreach (['admin.dishes.index', 'admin.dishes.create', 'admin.categories.index', 'admin.zones.index', 'admin.revenue'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.dishes.edit', $dish))->assertOk();
        $this->get(route('admin.revenue', ['from' => '2026-09-21', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
    }

    public function test_shared_seed_image_is_kept_until_last_dish_is_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('seed/shared.svg', '<svg/>');
        $first = Dish::factory()->create(['image_path' => 'seed/shared.svg']);
        $second = Dish::factory()->create(['image_path' => 'seed/shared.svg']);
        $this->actingAs($this->manager())->delete(route('admin.dishes.destroy', $first))->assertRedirect();
        Storage::disk('public')->assertExists('seed/shared.svg');
        $this->delete(route('admin.dishes.destroy', $second))->assertRedirect();
        Storage::disk('public')->assertMissing('seed/shared.svg');
    }

    public function test_revenue_excludes_refunds_and_can_separate_demo_orders(): void
    {
        foreach (['paid', 'refund_pending', 'refund_failed', 'refunded', 'partially_refunded', 'unpaid'] as $status) {
            Order::factory()->create(['status' => 'completed', 'payment_status' => $status, 'paid_at' => now(), 'completed_at' => now()]);
        }
        Order::factory()->create(['status' => 'completed', 'payment_status' => 'paid', 'paid_at' => now(), 'completed_at' => now(), 'is_demo' => true]);
        $this->actingAs($this->manager())->get(route('admin.revenue', ['dataset' => 'real']))->assertOk()->assertViewHas('summary', fn ($s) => (int) $s->revenue === 65000 && (int) $s->order_count === 1);
        $this->get(route('admin.revenue', ['dataset' => 'demo']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 1 && $orders->first()->is_demo);
    }
}
