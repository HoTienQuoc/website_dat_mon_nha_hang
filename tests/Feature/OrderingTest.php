<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderingTest extends TestCase
{
    use RefreshDatabase;

    private function details(array $extra = []): array
    {
        return array_merge(['customer_name' => 'Khách thử', 'customer_phone' => '0901234567', 'fulfillment_type' => 'pickup'], $extra);
    }

    public function test_cart_can_add_update_and_remove_dishes(): void
    {
        $dish = Dish::factory()->create();
        $this->post(route('cart.store', $dish), ['quantity' => 2])->assertSessionHas('cart.'.$dish->id, 2);
        $this->patch(route('cart.update', $dish), ['quantity' => 1])->assertSessionHas('cart.'.$dish->id, 1);
        $this->get(route('cart.index'))->assertOk()->assertSee('65.000');
        $this->delete(route('cart.destroy', $dish))->assertSessionMissing('cart.'.$dish->id);
    }

    public function test_cart_rejects_zero_fractional_and_excessive_quantities(): void
    {
        $dish = Dish::factory()->create();
        foreach ([0, -1, 1.5, 100] as $qty) {
            $this->post(route('cart.store', $dish), ['quantity' => $qty])->assertSessionHasErrors('quantity');
        }
        $this->assertEmpty(session('cart', []));
    }

    public function test_unavailable_dish_cannot_be_added(): void
    {
        $dish = Dish::factory()->create(['is_available' => false]);
        $this->post(route('cart.store', $dish), ['quantity' => 1])->assertSessionHasErrors('cart');
        $this->assertEmpty(session('cart', []));
    }

    public function test_checkout_creates_one_order_and_replays_without_duplicates(): void
    {
        $dish = Dish::factory()->create(['name' => 'Cơm gà', 'price' => 65000]);
        $this->withSession(['cart' => [$dish->id => 2]])->post(route('checkout.review'), $this->details())->assertRedirect();
        $key = array_key_last(session('checkout_drafts'));
        $this->get(route('checkout.confirm', $key))->assertOk()->assertSee('130.000');
        $this->post(route('orders.store'), ['idempotency_key' => $key, 'total' => 1])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame(130000, $order->total);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'dish_name' => 'Cơm gà', 'unit_price' => 65000, 'quantity' => 2]);
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertRedirect(route('orders.show', $order->code));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_status_histories', 1);
        $this->get(route('orders.show', $order->code))->assertSee('130.000');
    }

    public function test_delivery_requires_address_and_served_zone(): void
    {
        $dish = Dish::factory()->create();
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $this->details(['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang']))->assertSessionHasErrors(['address', 'delivery_zone_id']);
        $zone = DeliveryZone::factory()->create(['is_active' => false]);
        $this->post(route('checkout.review'), $this->details(['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address' => '12 Đường thử nghiệm', 'delivery_zone_id' => $zone->id]))->assertSessionHasErrors('delivery_zone_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delivery_fee_comes_from_database_and_is_snapshotted(): void
    {
        $dish = Dish::factory()->create();
        $zone = DeliveryZone::factory()->create(['fee' => 20000, 'name_en' => 'Test Ward']);
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $this->details(['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address' => '12 Đường thử nghiệm', 'delivery_zone_id' => $zone->id, 'delivery_fee' => 1]))->assertRedirect();
        $key = array_key_last(session('checkout_drafts'));
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertRedirect();
        $this->assertDatabaseHas('orders', ['total' => 85000, 'delivery_fee' => 20000, 'delivery_zone_name' => $zone->name, 'delivery_zone_name_en' => 'Test Ward']);
    }

    public function test_checkout_rejects_missing_customer_information(): void
    {
        $this->post(route('checkout.review'), [])->assertSessionHasErrors(['customer_name', 'customer_phone', 'fulfillment_type']);
        $this->post(route('checkout.review'), $this->details(['customer_phone' => 'abc']))->assertSessionHasErrors('customer_phone');
    }

    public function test_empty_cart_and_forged_checkout_key_are_rejected(): void
    {
        $this->get(route('checkout.create'))->assertRedirect(route('cart.index'));
        $this->post(route('checkout.review'), $this->details())->assertSessionHasErrors('cart');
        $this->post(route('orders.store'), ['idempotency_key' => (string) Str::uuid()])->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_price_change_requires_new_confirmation(): void
    {
        $dish = Dish::factory()->create();
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $this->details())->assertRedirect();
        $key = array_key_last(session('checkout_drafts'));
        $dish->update(['price' => 80000]);
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_disabled_dish_after_review_blocks_entire_order(): void
    {
        $first = Dish::factory()->create();
        $second = Dish::factory()->create();
        $this->withSession(['cart' => [$first->id => 1, $second->id => 1]])->post(route('checkout.review'), $this->details());
        $key = array_key_last(session('checkout_drafts'));
        $second->update(['is_available' => false]);
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_disabled_delivery_zone_after_review_is_rejected(): void
    {
        $dish = Dish::factory()->create();
        $zone = DeliveryZone::factory()->create();
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $this->details(['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address' => '12 Đường thử nghiệm', 'delivery_zone_id' => $zone->id]));
        $key = array_key_last(session('checkout_drafts'));
        $zone->update(['is_active' => false]);
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertSessionHasErrors('delivery_zone_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_lookup_requires_phone_and_does_not_expose_other_orders(): void
    {
        $order = Order::factory()->create();
        $other = Order::factory()->create();
        $this->get(route('orders.show', $order->code))->assertNotFound();
        $this->post(route('orders.lookup.submit'), ['code' => $order->code, 'phone' => '0900000000'])->assertSessionHasErrors('lookup');
        $this->get(route('orders.show', $order->code))->assertNotFound();
        $this->post(route('orders.lookup.submit'), ['code' => $order->code, 'phone' => $order->customer_phone])->assertRedirect(route('orders.show', $order->code));
        $this->get(route('orders.show', $order->code))->assertOk();
        $this->get(route('orders.show', $other->code))->assertNotFound();
    }

    public function test_lookup_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('orders.lookup.submit'), ['code' => (string) Str::uuid(), 'phone' => '0900000000']);
        }
        $this->post(route('orders.lookup.submit'), ['code' => (string) Str::uuid(), 'phone' => '0900000000'])->assertStatus(429);
    }

    public function test_menu_escapes_user_supplied_dish_name(): void
    {
        Dish::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->get('/')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
