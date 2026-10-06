<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\TransitionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_login_and_logout_use_database_credentials(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'password' => Hash::make('Test-password-123')]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Test-password-123'])->assertRedirect(route('staff.orders.index'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_full_order_lifecycle_requires_handover_and_cash_collection(): void
    {
        $order = Order::factory()->create();
        $cashier = $this->staff('cashier');
        $kitchen = $this->staff('head_chef');
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->actingAs($kitchen)->patch(route('staff.orders.update', $order), ['expected_status' => 'confirmed', 'status' => 'preparing'])->assertSessionHasNoErrors();
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'preparing', 'status' => 'ready'])->assertSessionHasNoErrors();
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'completed'])->assertSessionHasErrors('paid');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'ready', 'paid_at' => null]);
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'ready', 'status' => 'completed', 'paid' => 1, 'handed_over' => 1])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertDatabaseCount('order_status_histories', 4);
    }

    public function test_cashier_cannot_prepare_and_kitchen_cannot_confirm_or_cancel(): void
    {
        $order = Order::factory()->create(['status' => 'confirmed']);
        $this->actingAs($this->staff('cashier'))->patchJson(route('staff.orders.update', $order), ['expected_status' => 'confirmed', 'status' => 'preparing'])->assertForbidden();
        $this->actingAs($this->staff('head_chef'))->patchJson(route('staff.orders.update', $order), ['expected_status' => 'confirmed', 'status' => 'cancelled', 'reason' => 'Hết món'])->assertForbidden();
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_cancellation_requires_reason_and_manager_exception_after_preparation(): void
    {
        $order = Order::factory()->create();
        $cashier = $this->staff('cashier');
        $this->actingAs($cashier)->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'cancelled'])->assertSessionHasErrors('reason');
        $this->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Khách đổi kế hoạch'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'cancellation_reason' => 'Khách đổi kế hoạch']);
        $prepared = Order::factory()->create(['status' => 'preparing']);
        $this->patchJson(route('staff.orders.update', $prepared), ['expected_status' => 'preparing', 'status' => 'cancelled', 'reason' => 'Sự cố'])->assertForbidden();
        $this->actingAs($this->staff('manager'))->patch(route('staff.orders.update', $prepared), ['expected_status' => 'preparing', 'status' => 'cancelled', 'reason' => 'Sự cố'])->assertSessionHasErrors('approve_exception');
        $this->patch(route('staff.orders.update', $prepared), ['expected_status' => 'preparing', 'status' => 'cancelled', 'reason' => 'Sự cố', 'approve_exception' => 1])->assertRedirect();
        $this->assertSame('cancelled', $prepared->fresh()->status);
    }

    public function test_stale_update_and_terminal_state_are_rejected(): void
    {
        $order = Order::factory()->create(['status' => 'confirmed']);
        $this->actingAs($this->staff('manager'))->patch(route('staff.orders.update', $order), ['expected_status' => 'pending', 'status' => 'confirmed'])->assertSessionHasErrors('status');
        $order->update(['status' => 'completed']);
        $this->patchJson(route('staff.orders.update', $order), ['expected_status' => 'completed', 'status' => 'cancelled', 'reason' => 'test', 'approve_exception' => 1])->assertForbidden();
    }

    public function test_role_matrix_for_every_order_status(): void
    {
        $workflow = app(TransitionOrder::class);
        $expected = [
            'cashier' => ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['cancelled'], 'preparing' => [], 'ready' => ['completed']],
            'head_chef' => ['pending' => [], 'confirmed' => ['preparing'], 'preparing' => ['ready'], 'ready' => []],
            'manager' => ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['preparing', 'cancelled'], 'preparing' => ['ready', 'cancelled'], 'ready' => ['completed', 'cancelled']],
        ];
        foreach ($expected as $role => $states) {
            $user = $this->staff($role);
            foreach ($states as $status => $allowed) {
                $this->assertSame($allowed, $workflow->allowed(new Order(['status' => $status]), $user));
            } foreach (['completed', 'cancelled'] as $status) {
                $this->assertSame([], $workflow->allowed(new Order(['status' => $status]), $user));
            }
        }
    }

    public function test_staff_pages_render_and_kitchen_hides_customer_contact(): void
    {
        $order = Order::factory()->create(['status' => 'confirmed', 'address' => 'Địa chỉ riêng']);
        $this->actingAs($this->staff('head_chef'))->get(route('staff.orders.index'))->assertOk()->assertSee('Đơn #'.$order->id);
        $this->get(route('staff.orders.show', $order))->assertOk()->assertDontSee($order->customer_phone)->assertDontSee('Địa chỉ riêng');
    }

    public function test_guests_and_wrong_roles_cannot_access_admin_routes(): void
    {
        $this->get(route('admin.dishes.index'))->assertRedirect(route('login'));
        $this->getJson(route('admin.dishes.index'))->assertUnauthorized();
        foreach (['cashier', 'head_chef'] as $role) {
            $this->actingAs($this->staff($role))->getJson(route('admin.dishes.index'))->assertForbidden();
            $this->getJson(route('admin.revenue'))->assertForbidden();
        }
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => 'test@example.com', 'password' => 'wrong']);
        }
        $this->post(route('login.store'), ['email' => 'test@example.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
