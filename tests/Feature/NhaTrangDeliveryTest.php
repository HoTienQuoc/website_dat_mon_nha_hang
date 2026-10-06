<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NhaTrangDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_hides_and_rejects_zones_outside_nha_trang(): void
    {
        $dish = Dish::factory()->create();
        $outside = DeliveryZone::factory()->create(['name' => 'Outside zone', 'service_city' => 'Ho Chi Minh']);
        $this->withSession(['cart' => [$dish->id => 1]])->get(route('checkout.create'))->assertOk()->assertDontSee('Outside zone');
        $this->post(route('checkout.review'), ['customer_name' => 'Guest', 'customer_phone' => '0901234567', 'fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address' => '123 Example Street', 'delivery_zone_id' => $outside->id])->assertSessionHasErrors('delivery_zone_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_local_delivery_saves_real_order_with_zone_fee_and_rechecks_city_at_submission(): void
    {
        $dish = Dish::factory()->create(['price' => 65000]);
        $zone = DeliveryZone::factory()->create(['fee' => 15000]);
        $data = ['customer_name' => 'Guest', 'customer_email' => 'guest@example.test', 'fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address' => '123 Duong Mau, Nha Trang', 'delivery_city' => 'Nha Trang', 'delivery_zone_id' => $zone->id];
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $data)->assertSessionHasNoErrors();
        $key = array_key_last(session('checkout_drafts'));
        $zone->update(['service_city' => 'Outside']);
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertSessionHasErrors('delivery_zone_id');
        $this->assertDatabaseCount('orders', 0);
        $zone->update(['service_city' => 'Nha Trang']);
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertRedirect();
        $this->assertDatabaseHas('orders', ['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'total' => 80000, 'delivery_zone_id' => $zone->id]);
    }

    public function test_delivery_rejects_another_city_even_with_a_local_zone(): void
    {
        $dish = Dish::factory()->create();
        $zone = DeliveryZone::factory()->create();
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), ['customer_name' => 'Guest', 'customer_phone' => '0901234567', 'fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address' => '123 Example Street', 'delivery_zone_id' => $zone->id, 'delivery_city' => 'Da Nang'])->assertSessionHasErrors('delivery_city');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_dine_in_has_no_delivery_address_or_fee(): void
    {
        $dish = Dish::factory()->create();
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), ['customer_name' => 'Guest', 'customer_phone' => '0901234567', 'fulfillment_type' => 'dine_in'])->assertSessionHasNoErrors();
        $key = array_key_last(session('checkout_drafts'));
        $this->get(route('checkout.confirm', $key))->assertOk()->assertSee('Dùng tại nhà hàng');
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('dine_in', $order->fulfillment_type);
        $this->assertSame(0, $order->delivery_fee);
        $this->assertNull($order->address);
    }
}
