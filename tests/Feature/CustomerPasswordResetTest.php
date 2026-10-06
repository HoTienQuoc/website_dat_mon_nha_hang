<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class CustomerPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_email_only_targets_customers_and_link_uses_configured_host(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => 'customer']);
        $staff = User::factory()->create(['role' => 'admin']);
        $this->post(route('customer.password.email'), ['email' => $customer->email])->assertRedirect()->assertSessionHas('success');
        Notification::assertSentTo($customer, ResetPassword::class, function ($notification) use ($customer) {
            $url = $notification->toMail($customer)->actionUrl;
            $this->assertStringStartsWith(config('app.url').'/tai-khoan/dat-lai-mat-khau/', $url);

            return true;
        });
        $this->post(route('customer.password.email'), ['email' => $staff->email])->assertSessionHas('success');
        Notification::assertNotSentTo($staff, ResetPassword::class);
    }

    public function test_reset_token_is_single_use_and_cannot_reset_staff(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $token = Password::createToken($customer);
        $data = ['email' => $customer->email, 'token' => $token, 'password' => 'Reset-password-123', 'password_confirmation' => 'Reset-password-123'];
        $this->post(route('customer.password.reset.update'), $data)->assertRedirect(route('customer.login'));
        $this->assertTrue(Hash::check('Reset-password-123', $customer->fresh()->password));
        $this->post(route('customer.password.reset.update'), $data)->assertSessionHasErrors('email');
        $staff = User::factory()->create(['role' => 'admin']);
        $token = Password::createToken($staff);
        $this->post(route('customer.password.reset.update'), [...$data, 'email' => $staff->email, 'token' => $token])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post(route('customer.password.reset.update'), ['email' => $user->email, 'token' => $token, 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'])->assertSessionHasErrors('email');
    }
}
