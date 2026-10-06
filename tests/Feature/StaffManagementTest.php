<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_assign_role_and_deactivate_staff(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $data = ['name' => 'Bếp mới', 'email' => 'new@bepnha.test', 'password' => 'A-long-password-123', 'password_confirmation' => 'A-long-password-123', 'role' => 'head_chef', 'is_active' => 1];
        $this->actingAs($manager)->post(route('admin.staff.store'), $data)->assertRedirect();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->put(route('admin.staff.update', $user), [...$data, 'password' => null, 'password_confirmation' => null, 'role' => 'cashier', 'is_active' => 0])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'cashier', 'is_active' => 0]);
        $this->post(route('logout'));
        $this->actingAs($user->fresh())->getJson(route('staff.orders.index'))->assertForbidden();
    }

    public function test_manager_cannot_remove_own_management_access(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $this->actingAs($manager)->put(route('admin.staff.update', $manager), ['name' => $manager->name, 'email' => $manager->email, 'role' => 'head_chef', 'is_active' => 1])->assertSessionHasErrors('role');
        $this->assertSame('admin', $manager->fresh()->role);
    }

    public function test_staff_management_is_not_accessible_to_cashier(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->post(route('logout'));
        $this->actingAs($user->fresh())->getJson(route('admin.staff.index'))->assertForbidden();
        $this->postJson(route('admin.staff.store'), [])->assertForbidden();
    }

    public function test_staff_page_renders_for_manager(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.staff.index'))->assertOk()->assertSee('Tài khoản & phân quyền');
    }
}
