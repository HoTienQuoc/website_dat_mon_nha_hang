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

class RestaurantEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private function table(string $name): RestaurantTable
    {
        return RestaurantTable::create(['name' => $name, 'capacity' => 4, 'is_active' => true]);
    }

    private function order(array $overrides = []): Order
    {
        $o = Order::factory()->create(['fulfillment_type' => 'dine_in', 'status' => 'confirmed', 'subtotal' => 195000, 'total' => 195000, ...$overrides]);
        $o->items()->create(['dish_name' => 'Cơm gà', 'dish_name_en' => 'Chicken rice', 'unit_price' => 65000, 'quantity' => 3, 'preparation_status' => 'pending']);

        return $o;
    }

    public function test_item_adjustment_recalculates_preserves_audit_and_rejects_stale_or_paid_changes(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $data = ['quantity' => 2, 'expected_quantity' => 3, 'expected_status' => 'pending', 'reason' => 'Khách giảm một phần'];
        $this->patch(route('staff.items.adjust', $item), $data)->assertSessionHasNoErrors();
        $this->assertSame(130000, $order->fresh()->total);
        $this->patch(route('staff.items.adjust', $item), $data)->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'OrderItem', 'subject_id' => $item->id, 'action' => 'updated']);
        $order->update(['payment_status' => 'paid']);
        $this->patch(route('staff.items.adjust', $item), [...$data, 'expected_quantity' => 2])->assertSessionHasErrors('order');
    }

    public function test_prepared_cancellation_requires_manager_approval_and_does_not_block_remaining_food(): void
    {
        $order = $this->order(['status' => 'preparing']);
        $item = $order->items()->first();
        $item->update(['preparation_status' => 'preparing']);
        $other = $order->items()->create(['dish_name' => 'Soup', 'unit_price' => 30000, 'quantity' => 1, 'preparation_status' => 'ready']);
        $data = ['quantity' => 0, 'expected_quantity' => 3, 'expected_status' => 'preparing', 'reason' => 'Khách báo dị ứng', 'approve_prepared' => 1];
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->patchJson(route('staff.items.adjust', $item), $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'manager']))->patch(route('staff.items.adjust', $item), array_diff_key($data, ['approve_prepared' => 1]))->assertSessionHasErrors('approve_prepared');
        $this->patch(route('staff.items.adjust', $item), $data)->assertSessionHasNoErrors();
        $this->assertSame(30000, $order->fresh()->total);
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('cancelled', $item->fresh()->preparation_status);
        $this->get(route('staff.kitchen.index'))->assertOk()->assertDontSee('Cơm gà')->assertSee('Soup');
    }

    public function test_waiter_cannot_edit_unassigned_order_and_cancelling_all_items_releases_table(): void
    {
        $order = $this->order(['restaurant_table_id' => $this->table('A')->id]);
        $item = $order->items()->first();
        $data = ['quantity' => 0, 'expected_quantity' => 3, 'expected_status' => 'pending', 'reason' => 'Khách không dùng món'];
        $this->actingAs(User::factory()->create(['role' => 'waiter']))->patchJson(route('staff.items.adjust', $item), $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('staff.items.adjust', $item), $data)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->total);
        $this->assertFalse($order->restaurantTable->activeOrders()->exists());
    }

    public function test_split_and_merge_preserve_price_totals_history_and_release_source_table(): void
    {
        $a = $this->table('A');
        $b = $this->table('B');
        $order = $this->order(['restaurant_table_id' => $a->id, 'table_name' => 'A', 'note' => 'Dị ứng đậu nành / Soy allergy']);
        $item = $order->items()->first();
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->get(route('staff.orders.show', $order))->assertOk()->assertSee('Gộp / tách');
        $data = ['restaurant_table_id' => $b->id, 'expected_total' => 195000, 'quantities' => [$item->id => 1], 'reason' => 'Khách tách hóa đơn'];
        $this->post(route('staff.orders.split', $order), $data)->assertSessionHasNoErrors();
        $child = Order::where('split_from_id', $order->id)->firstOrFail();
        $this->assertSame('Dị ứng đậu nành / Soy allergy', $child->note);
        $child->update(['note' => 'No peanuts']);
        $this->assertSame(65000, $child->total);
        $this->assertSame(130000, $order->fresh()->total);
        $this->assertSame(65000, $child->items()->first()->unit_price);
        $this->post(route('staff.orders.split', $order), $data)->assertSessionHasErrors('order');
        $merge = ['target_order_id' => $order->id, 'expected_total' => 65000, 'target_total' => 130000, 'reason' => 'Khách gộp lại hóa đơn'];
        $this->post(route('staff.orders.merge', $child), $merge)->assertSessionHasNoErrors();
        $this->assertSame(195000, $order->fresh()->total);
        $this->assertSame('cancelled', $child->fresh()->status);
        $this->assertStringContainsString('No peanuts', $order->fresh()->note);
        $this->assertStringContainsString('Soy allergy', $order->fresh()->note);
        $this->assertSame($order->id, $child->fresh()->merged_into_id);
        $this->assertFalse($b->activeOrders()->exists());
        $this->assertGreaterThanOrEqual(2, $order->histories()->count());
        $this->post(route('staff.orders.merge', $child), $merge)->assertSessionHasErrors('order');
        $this->assertSame(195000, $order->fresh()->total);
    }

    public function test_split_rejects_all_food_occupied_tables_and_waiter_and_merge_rejects_distinct_customers(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $table = $this->table('A');
        $data = ['restaurant_table_id' => $table->id, 'expected_total' => $order->total, 'quantities' => [$item->id => 3], 'reason' => 'Tách đơn kiểm thử'];
        $this->actingAs(User::factory()->create(['role' => 'waiter']))->postJson(route('staff.orders.split', $order), $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('staff.orders.split', $order), $data)->assertSessionHasErrors('quantities');
        $target = $this->order(['restaurant_table_id' => $table->id, 'user_id' => User::factory()->create(['role' => 'customer'])->id]);
        $this->post(route('staff.orders.split', $order), [...$data, 'quantities' => [$item->id => 1]])->assertSessionHasErrors('restaurant_table_id');
        $this->post(route('staff.orders.merge', $order), ['target_order_id' => $target->id, 'target_total' => $target->total, 'expected_total' => $order->total, 'reason' => 'Gộp đơn kiểm thử'])->assertSessionHasErrors('order');
        $this->assertSame(195000, $order->fresh()->total);
    }

    public function test_delivery_requires_supported_ward_and_staff_verification_before_acceptance(): void
    {
        $dish = Dish::factory()->create();
        $zone = DeliveryZone::factory()->create();
        $data = ['customer_name' => 'Khách', 'customer_email' => 'guest@example.test', 'fulfillment_type' => 'delivery', 'address' => '123 Đường Mẫu, Nha Trang', 'delivery_zone_id' => $zone->id, 'delivery_ward' => 'Đà Nẵng'];
        $this->withSession(['cart' => [$dish->id => 1]])->post(route('checkout.review'), $data)->assertSessionHasErrors('delivery_ward');
        $this->post(route('checkout.review'), [...$data, 'delivery_ward' => 'Tây Nha Trang'])->assertSessionHasNoErrors();
        $order = Order::factory()->create(['fulfillment_type' => 'delivery', 'address' => $data['address'], 'delivery_ward' => 'Tây Nha Trang']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertSessionHasErrors('address');
        $this->post(route('staff.address.verify', $order), ['delivery_ward' => 'Tây Nha Trang', 'confirmed' => 1, 'expected_address' => $order->address])->assertSessionHasNoErrors();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->address_verified_at);
        $this->assertSame(auth()->id(), $order->fresh()->address_verified_by);
    }

    public function test_revenue_csv_enforces_owner_filters_and_escapes_spreadsheet_formulas(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $real = Order::factory()->create(['customer_name' => '=HYPERLINK("bad")', 'status' => 'completed', 'payment_status' => 'paid', 'paid_at' => now(), 'completed_at' => now(), 'total' => 65000]);
        Order::factory()->create(['customer_name' => 'DEMO-EXCLUDED', 'status' => 'completed', 'payment_status' => 'paid', 'paid_at' => now(), 'completed_at' => now(), 'is_demo' => true]);
        Order::factory()->create(['customer_name' => 'CANCELLED-EXCLUDED', 'status' => 'cancelled', 'payment_status' => 'paid', 'paid_at' => now(), 'completed_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->getJson(route('admin.revenue.export'))->assertForbidden();
        $r = $this->actingAs($owner)->get(route('admin.revenue.export', ['dataset' => 'real']));
        $r->assertOk()->assertDownload();
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('EXCLUDED', $csv);
        $this->assertStringContainsString('65000', $csv);
    }

    public function test_shift_export_and_notifications_are_scoped_and_public_updates_do_not_leak_contacts(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $other = User::factory()->create(['role' => 'cashier']);
        foreach ([$cashier, $other] as $u) {
            CashShift::create(['user_id' => $u->id, 'from_at' => now(), 'to_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'stripe_total' => 0, 'demo_total' => 0, 'order_count' => 1, 'note' => $u->id === $cashier->id ? 'OWN-SHIFT' : 'OTHER-SHIFT']);
        }
        $r = $this->actingAs($cashier)->get(route('staff.shifts.export'));
        $r->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString('OWN-SHIFT', $csv);
        $this->assertStringNotContainsString('OTHER-SHIFT', $csv);
        $chef = User::factory()->create(['role' => 'chef']);
        $visible = $this->order();
        $invisible = $this->order();
        $visible->tasks()->create(['kind' => 'cooking', 'assigned_to' => $chef->id, 'assigned_by' => $cashier->id, 'status' => 'pending']);
        $before = $this->actingAs($chef)->getJson(route('staff.orders.alerts'))->assertOk()->json('version');
        $invisible->update(['status' => 'ready']);
        $this->assertSame($before, $this->getJson(route('staff.orders.alerts'))->json('version'));
        $visible->update(['status' => 'preparing']);
        $this->assertNotSame($before, $this->getJson(route('staff.orders.alerts'))->json('version'));
        auth()->logout();
        $this->getJson(route('orders.updates', $visible->code))->assertNotFound();
        $r = $this->withSession(['verified_orders' => [$visible->id]])->getJson(route('orders.updates', $visible->code));
        $r->assertOk()->assertJsonStructure(['version', 'status', 'message']);
        $this->assertStringNotContainsString('0901234567', $r->getContent());
    }
}
