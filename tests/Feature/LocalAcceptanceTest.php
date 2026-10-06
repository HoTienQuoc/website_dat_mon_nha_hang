<?php

namespace Tests\Feature;

use App\Models\CashShift;
use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function checkout(array $data): Order
    {
        $dish = Dish::factory()->create(['name' => 'Cơm gà', 'name_en' => 'Chicken rice', 'price' => 65000]);
        $this->post(route('cart.store', $dish), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->get(route('cart.index'))->assertOk();
        $this->post(route('checkout.review'), ['customer_name' => 'Khách nghiệm thu', ...$data])->assertSessionHasNoErrors();
        $key = array_key_last(session('checkout_drafts'));
        $this->get(route('checkout.confirm', $key))->assertOk();
        $this->post(route('orders.store'), ['idempotency_key' => $key])->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->get(route('orders.show', $order->code))->assertOk();

        return $order;
    }

    private function prepare(Order $order, User $head, User $chef, User $assistant): void
    {
        $this->actingAs($head)->post(route('staff.assignments.store', $order), ['kind' => 'cooking', 'assigned_to' => $chef->id])->assertSessionHasNoErrors();
        $this->post(route('staff.assignments.store', $order), ['kind' => 'preparation', 'assigned_to' => $assistant->id])->assertSessionHasNoErrors();
        $prep = $order->tasks()->where('kind', 'preparation')->firstOrFail();
        $this->actingAs($assistant)->patch(route('staff.tasks.update', $prep), ['expected_status' => 'pending', 'status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->patch(route('staff.tasks.update', $prep), ['expected_status' => 'in_progress', 'status' => 'done'])->assertSessionHasNoErrors();
        $item = $order->items()->firstOrFail();
        $this->actingAs($chef)->get(route('staff.kitchen.index'))->assertOk();
        $this->patch(route('staff.kitchen.update', $item), ['expected_status' => 'pending', 'status' => 'preparing'])->assertSessionHasNoErrors();
        $this->patch(route('staff.kitchen.update', $item), ['expected_status' => 'preparing', 'status' => 'ready'])->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_guest_dine_in_runs_through_kitchen_waiter_cashier_and_owner_settlement(): void
    {
        $owner = $this->staff('admin');
        $manager = $this->staff('manager');
        $cashier = $this->staff('cashier');
        $head = $this->staff('head_chef');
        $chef = $this->staff('chef');
        $assistant = $this->staff('kitchen_assistant');
        $waiter = $this->staff('waiter');
        $this->withSession(['locale' => 'en']);
        $order = $this->checkout(['customer_email' => 'guest@example.test', 'fulfillment_type' => 'dine_in', 'payment_method' => 'cash']);
        $this->assertNull($order->user_id);
        $this->assertNull($order->customer_phone);
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertSessionHasNoErrors();
        $table = RestaurantTable::create(['name' => 'AC01', 'capacity' => 4, 'is_active' => true]);
        $this->actingAs($manager)->put(route('staff.tables.assign', $order), ['restaurant_table_id' => $table->id])->assertSessionHasNoErrors();
        $this->post(route('staff.assignments.store', $order), ['kind' => 'service', 'assigned_to' => $waiter->id])->assertSessionHasNoErrors();
        $this->prepare($order, $head, $chef, $assistant);
        $this->actingAs($waiter)->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'served'])->assertSessionHasNoErrors();
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'served', 'status' => 'completed', 'handed_over' => 1, 'paid' => 1])->assertSessionHasNoErrors();
        $this->post(route('staff.shifts.store'), ['expected_order_ids' => (string) $order->id, 'counted_cash' => 130000])->assertSessionHasNoErrors();
        $this->assertSame(0, $table->activeOrders()->count());
        $this->assertSame(130000, CashShift::firstOrFail()->expected_cash);
        $this->actingAs($owner)->get(route('admin.revenue'))->assertOk()->assertSee('130.000');
        $this->get(route('orders.receipt', $order->code))->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_registered_customer_nha_trang_delivery_cod_completes_after_cash_handover(): void
    {
        $customer = $this->staff('customer');
        $manager = $this->staff('manager');
        $cashier = $this->staff('cashier');
        $head = $this->staff('head_chef');
        $chef = $this->staff('chef');
        $assistant = $this->staff('kitchen_assistant');
        $courier = $this->staff('courier');
        $zone = DeliveryZone::factory()->create(['fee' => 15000]);
        $this->actingAs($customer);
        $order = $this->checkout(['customer_phone' => '0901234567', 'fulfillment_type' => 'delivery', 'delivery_zone_id' => $zone->id, 'delivery_city' => 'Nha Trang', 'delivery_ward' => 'Tây Nha Trang', 'address' => '12 Đường Mẫu, Tây Nha Trang', 'payment_method' => 'cash']);
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame(145000, $order->total);
        $this->actingAs($manager)->post(route('staff.address.verify', $order), ['expected_address' => $order->address, 'delivery_ward' => 'Tây Nha Trang', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->post(route('staff.assignments.store', $order), ['kind' => 'delivery', 'assigned_to' => $courier->id])->assertSessionHasNoErrors();
        $this->prepare($order, $head, $chef, $assistant);
        $this->actingAs($courier)->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'delivering'])->assertSessionHasNoErrors();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'delivering', 'status' => 'delivered', 'cash_collected' => 1])->assertSessionHasNoErrors();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'delivered', 'status' => 'completed', 'paid' => 1, 'handed_over' => 1])->assertSessionHasNoErrors();
        $this->post(route('staff.shifts.store'), ['expected_order_ids' => (string) $order->id, 'counted_cash' => 145000])->assertSessionHasNoErrors();
        $this->actingAs($customer)->get(route('customer.account'))->assertOk()->assertSee($order->code);
        $this->get(route('orders.updates', $order->code))->assertOk()->assertJson(['status' => 'Hoàn tất']);
        $this->assertNotNull($order->fresh()->cod_settled_at);
    }

    public function test_demo_card_guest_can_request_cancellation_and_owner_refunds_once(): void
    {
        $this->freezeTime();
        $order = $this->checkout(['customer_email' => 'demo@example.test', 'fulfillment_type' => 'pickup', 'payment_method' => 'demo_card']);
        $data = ['cardholder' => 'Demo Guest', 'card_number' => '4242 4242 4242 4242', 'expiry' => now()->addYear()->format('m/y'), 'cvc' => '123', 'email' => 'demo@example.test', 'billing_address' => '12 Duong Mau Nha Trang', 'country' => 'VN', 'postal_code' => '65000'];
        $this->post(route('demo-payments.store', $order->code), $data)->assertSessionHasNoErrors();
        $this->post(route('orders.cancel-request', $order->code), ['reason' => 'Khách đổi kế hoạch nhận món'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff('manager'))->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Đồng ý yêu cầu của khách'])->assertSessionHasNoErrors();
        $owner = $this->staff('admin');
        $this->actingAs($owner)->post(route('demo-payments.refund', $order), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->post(route('demo-payments.refund', $order), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(130000,$order->payments()->first()->refunded_amount);
        $this->assertSame(1,$order->payments()->count());
        $this->get(route('admin.revenue.export'))->assertOk()->assertDontSee($order->code);
    }
}
