<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function card(): array
    {
        return ['cardholder' => 'Demo Guest', 'card_number' => '4242 4242 4242 4242', 'expiry' => now()->addYear()->format('m/y'), 'cvc' => '123', 'email' => 'demo@example.test', 'billing_address' => '12 Duong Mau, Nha Trang', 'country' => 'VN', 'postal_code' => '65000'];
    }

    public function test_demo_card_validates_without_flashing_sensitive_input_and_succeeds_once(): void
    {
        $order = Order::factory()->create(['payment_method' => 'demo_card']);
        $this->withSession(['verified_orders' => [$order->id]])->get(route('demo-payments.create', $order->code))->assertOk()->assertSee('Đây là bản demo');
        foreach ([['card_number' => '123'], ['card_number' => '4242424242424241'], ['expiry' => '13/30'], ['expiry' => '01/20'], ['cvc' => 'xx'], ['email' => 'invalid'], ['cardholder' => ''], ['billing_address' => ''], ['country' => 'US'], ['postal_code' => 'abcd']] as $bad) {
            $this->post(route('demo-payments.store', $order->code), [...$this->card(), ...$bad])->assertSessionHasErrors(array_keys($bad))->assertSessionMissing('_old_input.card_number')->assertSessionMissing('_old_input.cvc');
        }
        $this->post(route('demo-payments.store', $order->code), $this->card())->assertRedirect(route('orders.show', $order->code));
        $this->post(route('demo-payments.store', $order->code), $this->card())->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'demo', 'amount' => $order->total, 'status' => 'paid']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertTrue($order->fresh()->is_demo);
        $stored = AuditLog::all()->toJson().$order->payments()->first()->toJson();
        $this->assertStringNotContainsString('4242424242424242', $stored);
        $this->assertStringNotContainsString('"cvc"', $stored);
    }

    public function test_demo_card_requires_order_access_and_unpaid_orders_cannot_be_processed(): void
    {
        $order = Order::factory()->create(['payment_method' => 'demo_card']);
        $this->post(route('demo-payments.store', $order->code), $this->card())->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertForbidden();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_demo_payment_refund_is_owner_only_and_logged(): void
    {
        $order = Order::factory()->create(['payment_method' => 'demo_card']);
        $this->withSession(['verified_orders' => [$order->id]])->post(route('demo-payments.store', $order->code), $this->card());
        $order->update(['status' => 'cancelled']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->postJson(route('demo-payments.refund', $order), ['confirm' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('demo-payments.refund', $order), ['confirm' => 1])->assertRedirect();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded', 'refunded_amount' => $order->total]);
        $this->assertTrue(AuditLog::where('subject_type', 'Payment')->where('action', 'updated')->exists());
    }

    public function test_waiter_creates_table_order_adds_items_with_current_prices_and_no_duplicates(): void
    {
        $waiter = User::factory()->create(['role' => 'waiter']);
        $table = RestaurantTable::create(['name' => 'T01', 'capacity' => 4, 'is_active' => true]);
        $dish = Dish::factory()->create(['price' => 65000]);
        $data = ['request_key' => (string) Str::uuid(), 'restaurant_table_id' => $table->id, 'quantities' => [$dish->id => 2]];
        $this->actingAs($waiter)->get(route('staff.pos.create'))->assertOk();
        $this->post(route('staff.pos.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('staff.pos.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $order = Order::firstOrFail();
        $this->assertSame(130000, $order->total);
        $this->assertTrue($order->assignedTo($waiter, 'service'));
        $dish->update(['price' => 70000]);
        $order->update(['status' => 'served']);
        $order->items()->update(['preparation_status' => 'served']);
        $extra = ['request_key' => (string) Str::uuid(), 'quantities' => [$dish->id => 1]];
        $this->get(route('staff.pos.edit', $order))->assertOk();
        $this->post(route('staff.pos.update', $order), $extra)->assertSessionHasNoErrors();
        $this->post(route('staff.pos.update', $order), $extra)->assertRedirect();
        $this->assertSame(200000, $order->fresh()->total);
        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertSame([65000, 70000], $order->items()->orderBy('id')->pluck('unit_price')->all());
        $this->assertSame(['served', 'pending'], $order->items()->orderBy('id')->pluck('preparation_status')->all());
    }

    public function test_pos_blocks_other_waiter_paid_orders_unavailable_dishes_and_occupied_tables(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $waiter = User::factory()->create(['role' => 'waiter']);
        $table = RestaurantTable::create(['name' => 'T01', 'capacity' => 4, 'is_active' => true]);
        $dish = Dish::factory()->create();
        $order = Order::factory()->create(['fulfillment_type' => 'dine_in', 'status' => 'confirmed', 'restaurant_table_id' => $table->id]);
        $data = ['request_key' => (string) Str::uuid(), 'quantities' => [$dish->id => 1]];
        $this->actingAs($waiter)->postJson(route('staff.pos.update', $order), $data)->assertForbidden();
        $this->actingAs($admin)->post(route('staff.pos.store'), [...$data, 'restaurant_table_id' => $table->id])->assertSessionHasErrors('restaurant_table_id');
        $order->update(['payment_status' => 'paid']);
        $this->post(route('staff.pos.update', $order), $data)->assertSessionHasErrors('order');
        $order->update(['payment_status' => 'unpaid']);
        $dish->update(['is_available' => false]);
        $this->post(route('staff.pos.update', $order), $data)->assertSessionHasErrors('quantities');
    }

    public function test_item_workflow_and_kitchen_page_scope(): void
    {
        $chef = User::factory()->create(['role' => 'chef']);
        $head = User::factory()->create(['role' => 'head_chef']);
        $order = Order::factory()->create(['status' => 'confirmed']);
        $other = Order::factory()->create(['status' => 'confirmed']);
        $order->tasks()->create(['kind' => 'cooking', 'assigned_to' => $chef->id, 'assigned_by' => $head->id, 'status' => 'pending']);
        $item = $order->items()->create(['dish_name' => 'Soup', 'unit_price' => 65000, 'quantity' => 1]);
        $this->actingAs($chef)->get(route('staff.kitchen.index'))->assertOk()->assertSee('Soup')->assertDontSee(route('staff.orders.show', $other), false)->assertSee('data-refresh="kitchen-board"', false);
        $this->patch(route('staff.kitchen.update', $item), ['expected_status' => 'pending', 'status' => 'preparing'])->assertSessionHasNoErrors();
        $this->patch(route('staff.kitchen.update', $item), ['expected_status' => 'preparing', 'status' => 'ready'])->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->status);
        $this->patch(route('staff.kitchen.update', $item), ['expected_status' => 'preparing', 'status' => 'ready'])->assertSessionHasErrors('status');
    }

    public function test_shift_excludes_demo_and_requires_explanation_then_prevents_double_closing(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $real = Order::factory()->create(['status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'cash', 'total' => 65000, 'completed_at' => now(), 'completed_by' => $cashier->id]);
        $demo = Order::factory()->create(['status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'demo_card', 'is_demo' => true, 'total' => 75000, 'completed_at' => now(), 'completed_by' => $cashier->id]);
        $data = ['expected_order_ids' => $real->id.','.$demo->id, 'counted_cash' => 60000];
        $this->actingAs($cashier)->get(route('staff.shifts.index'))->assertOk()->assertSee('65,000');
        $this->post(route('staff.shifts.store'), $data)->assertSessionHasErrors('note');
        $this->post(route('staff.shifts.store'), [...$data, 'note' => 'Chênh lệch đang kiểm tra'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_shifts', ['expected_cash' => 65000, 'counted_cash' => 60000, 'demo_total' => 75000, 'order_count' => 2]);
        $this->post(route('staff.shifts.store'), [...$data, 'note' => 'Duplicate'])->assertSessionHasErrors('shift');
        $this->assertDatabaseCount('cash_shifts', 1);
    }

    public function test_audit_is_owner_only_and_records_price_and_role_changes_without_secrets(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $dish = Dish::factory()->create(['price' => 65000]);
        $this->actingAs($owner);
        $dish->update(['price' => 70000]);
        $log = AuditLog::where('subject_type', 'Dish')->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(65000, $log->before['price']);
        $this->assertSame(70000, $log->after['price']);
        $this->assertSame($owner->id, $log->user_id);
        $this->get(route('admin.audit'))->assertOk()->assertSee('70000');
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->getJson(route('admin.audit'))->assertForbidden();
        $this->assertStringNotContainsString('password', AuditLog::where('subject_type', 'User')->get()->toJson());
    }
}
