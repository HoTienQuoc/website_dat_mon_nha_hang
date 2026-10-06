<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderTask;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function task(Order $order, User $user, string $kind, User $manager): OrderTask
    {
        return $order->tasks()->create(['kind' => $kind, 'assigned_to' => $user->id, 'assigned_by' => $manager->id, 'status' => 'pending']);
    }

    public function test_manager_cannot_promote_to_owner_edit_owner_or_access_owner_finance(): void
    {
        $owner = $this->staff('admin');
        $manager = $this->staff('manager');
        $data = ['name' => 'Staff', 'email' => 'staff@example.test', 'role' => 'admin', 'is_active' => 1, 'password' => 'Password-for-tests123', 'password_confirmation' => 'Password-for-tests123'];
        $this->actingAs($manager)->post(route('admin.staff.store'), $data)->assertSessionHasErrors('role');
        $this->putJson(route('admin.staff.update', $owner), $data)->assertForbidden();
        foreach (['admin.revenue', 'admin.settings.edit'] as $route) {
            $this->getJson(route($route))->assertForbidden();
        }
        $order = Order::factory()->create();
        $this->postJson(route('payments.refund', $order), ['confirm' => 1])->assertForbidden();
        $this->actingAs($owner)->get(route('admin.staff.index'))->assertOk()->assertSee('Chủ nhà hàng');
        $this->post(route('admin.staff.store'), [...$data, 'role' => 'courier'])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'staff@example.test', 'role' => 'courier']);
    }

    public function test_all_new_roles_can_sign_in_and_render_their_worklist(): void
    {
        foreach (array_keys(User::STAFF_ROLES) as $role) {
            $user = User::factory()->create(['role' => $role, 'password' => 'Password-for-tests123']);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password-for-tests123'])->assertRedirect(route('staff.orders.index'));
            $this->get(route('staff.orders.index'))->assertOk();
            $this->post(route('logout'));
        }
    }

    public function test_assigned_chef_and_assistant_complete_kitchen_work_without_customer_contacts(): void
    {
        $head = $this->staff('head_chef');
        $chef = $this->staff('chef');
        $assistant = $this->staff('kitchen_assistant');
        $order = Order::factory()->create(['status' => 'confirmed']);
        $this->actingAs($head)->post(route('staff.assignments.store', $order), ['kind' => 'cooking', 'assigned_to' => $chef->id])->assertRedirect();
        $this->post(route('staff.assignments.store', $order), ['kind' => 'preparation', 'assigned_to' => $assistant->id])->assertRedirect();
        $this->postJson(route('staff.assignments.store', $order), ['kind' => 'delivery', 'assigned_to' => $chef->id])->assertForbidden();
        $this->actingAs($chef)->get(route('staff.orders.show', $order))->assertOk()->assertDontSee($order->customer_phone);
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'confirmed', 'status' => 'preparing'])->assertSessionHasNoErrors();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'preparing', 'status' => 'ready'])->assertSessionHasErrors('status');
        $task = $order->tasks()->where('kind', 'preparation')->firstOrFail();
        $this->actingAs($assistant)->patch(route('staff.tasks.update', $task), ['expected_status' => 'pending', 'status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->patch(route('staff.tasks.update', $task), ['expected_status' => 'in_progress', 'status' => 'done'])->assertSessionHasNoErrors();
        $this->actingAs($chef)->patch(route('staff.orders.update', $order), ['expected_status' => 'preparing', 'status' => 'ready'])->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('done', $order->tasks()->where('kind', 'cooking')->first()->status);
    }

    public function test_unassigned_staff_cannot_view_update_or_take_over_orders(): void
    {
        $owner = $this->staff('admin');
        $order = Order::factory()->create(['status' => 'confirmed']);
        foreach (['chef', 'kitchen_assistant', 'waiter', 'courier'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get(route('staff.orders.index'))->assertOk()->assertDontSee($order->code);
            $this->getJson(route('staff.orders.show', $order))->assertForbidden();
            $this->patchJson(route('staff.orders.update', $order), ['expected_status' => 'confirmed', 'status' => 'preparing'])->assertForbidden();
            $this->postJson(route('staff.assignments.store', $order), ['kind' => 'cooking', 'assigned_to' => $user->id])->assertForbidden();
        }
        $chef = $this->staff('chef');
        $other = $this->staff('chef');
        $this->actingAs($owner)->post(route('staff.assignments.store', $order), ['kind' => 'cooking', 'assigned_to' => $chef->id])->assertRedirect();
        $this->post(route('staff.assignments.store', $order), ['kind' => 'cooking', 'assigned_to' => $other->id])->assertRedirect();
        $this->actingAs($chef)->getJson(route('staff.orders.show', $order))->assertForbidden();
    }

    public function test_tables_prevent_double_seating_and_waiter_serves_before_cashier_closes(): void
    {
        $owner = $this->staff('admin');
        $waiter = $this->staff('waiter');
        $cashier = $this->staff('cashier');
        $table = RestaurantTable::create(['name' => 'B01', 'capacity' => 4, 'is_active' => true]);
        $order = Order::factory()->create(['fulfillment_type' => 'dine_in', 'status' => 'ready']);
        $other = Order::factory()->create(['fulfillment_type' => 'dine_in']);
        $this->actingAs($owner)->put(route('staff.tables.assign', $order), ['restaurant_table_id' => $table->id])->assertSessionHasNoErrors();
        $this->put(route('staff.tables.assign', $other), ['restaurant_table_id' => $table->id])->assertSessionHasErrors('restaurant_table_id');
        $this->put(route('staff.tables.update', $table), ['name' => 'B01', 'capacity' => 4, 'is_active' => 0])->assertSessionHasErrors('table');
        $this->post(route('staff.assignments.store', $order), ['kind' => 'service', 'assigned_to' => $waiter->id])->assertRedirect();
        $this->actingAs($waiter)->get(route('staff.tables.index'))->assertOk()->assertSee('B01');
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'served'])->assertSessionHasNoErrors();
        $this->patchJson(route('staff.orders.update', $order), ['expected_status' => 'served', 'status' => 'completed', 'paid' => 1, 'handed_over' => 1])->assertForbidden();
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'served', 'status' => 'completed', 'paid' => 1, 'handed_over' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, $table->activeOrders()->count());
        $this->assertSame('B01', $order->fresh()->table_name);
    }

    public function test_delivery_requires_assigned_courier_and_cash_handover_before_revenue(): void
    {
        $owner = $this->staff('admin');
        $courier = $this->staff('courier');
        $cashier = $this->staff('cashier');
        $order = Order::factory()->create(['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address_verified_at' => now(), 'status' => 'ready']);
        $this->actingAs($owner)->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'delivering'])->assertSessionHasErrors('status');
        $this->post(route('staff.assignments.store', $order), ['kind' => 'delivery', 'assigned_to' => $courier->id])->assertRedirect();
        $this->actingAs($courier)->get(route('staff.orders.show', $order))->assertOk();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'delivering'])->assertSessionHasNoErrors();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'delivering', 'status' => 'delivered'])->assertSessionHasErrors('cash_collected');
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'delivering', 'status' => 'delivered', 'cash_collected' => 1])->assertSessionHasNoErrors();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->completed_at);
        $this->patchJson(route('staff.orders.update', $order), ['expected_status' => 'delivered', 'status' => 'completed', 'paid' => 1, 'handed_over' => 1])->assertForbidden();
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'delivered', 'status' => 'completed', 'paid' => 1, 'handed_over' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->cod_settled_at);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_failed_delivery_requires_reason_and_manager_decides_retry(): void
    {
        $owner = $this->staff('admin');
        $courier = $this->staff('courier');
        $order = Order::factory()->create(['fulfillment_type' => 'delivery', 'delivery_ward' => 'Nha Trang', 'address_verified_at' => now(), 'status' => 'delivering']);
        $this->task($order, $courier, 'delivery', $owner);
        $this->actingAs($courier)->patch(route('staff.orders.update', $order), ['expected_status' => 'delivering', 'status' => 'delivery_failed'])->assertSessionHasErrors('reason');
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'delivering', 'status' => 'delivery_failed', 'reason' => 'Không liên lạc được'])->assertSessionHasNoErrors();
        $this->patchJson(route('staff.orders.update', $order), ['expected_status' => 'delivery_failed', 'status' => 'delivering'])->assertForbidden();
        $this->actingAs($owner)->patch(route('staff.orders.update', $order), ['expected_status' => 'delivery_failed', 'status' => 'delivering'])->assertSessionHasNoErrors();
        $this->assertSame('delivering', $order->fresh()->status);
    }

    public function test_invalid_assignment_and_disabled_account_are_rejected(): void
    {
        $owner = $this->staff('admin');
        $chef = $this->staff('chef');
        $order = Order::factory()->create(['status' => 'confirmed']);
        $this->actingAs($owner)->post(route('staff.assignments.store', $order), ['kind' => 'delivery', 'assigned_to' => $chef->id])->assertSessionHasErrors('assigned_to');
        $chef->is_active = false;
        $chef->save();
        $this->post(route('staff.assignments.store', $order), ['kind' => 'cooking', 'assigned_to' => $chef->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($chef->fresh())->getJson(route('staff.orders.index'))->assertForbidden();
    }
}
