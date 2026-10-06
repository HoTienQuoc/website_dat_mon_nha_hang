<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class CustomerPasswordResetController extends Controller
{
    public function request(): View
    {
        return view('customer.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : $request->input('email')]);
        $data = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink([...$data, 'role' => 'customer', 'is_active' => true]);

        return back()->with('success', __('Nếu email thuộc tài khoản khách hàng đang hoạt động, hướng dẫn đặt lại mật khẩu sẽ được gửi.'));
    }

    public function reset(Request $request, string $token): View
    {
        return view('customer.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => 'required|string|max:255', 'email' => 'required|email|max:255', 'password' => ['required', 'string', 'confirmed', 'max:255', PasswordRule::min(12)->letters()->numbers()]]);
        $data['email'] = strtolower($data['email']);
        $status = Password::reset([...$data, 'role' => 'customer', 'is_active' => true], function (User $user, string $password) {
            $user->password = $password;
            $user->remember_token = Str::random(60);
            $user->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            return back()->withErrors(['email' => __('Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.')]);
        }

        return redirect()->route('customer.login')->with('success', __('Đã đổi mật khẩu. Vui lòng đăng nhập lại.'));
    }
}
