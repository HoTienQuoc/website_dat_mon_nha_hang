<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterCustomerRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CustomerAuthController extends Controller
{
    public function login(): View
    {
        return view('customer.login');
    }

    public function register(): View
    {
        return view('customer.register');
    }

    public function store(RegisterCustomerRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->except('password_confirmation'));
        $user->role = 'customer';
        $user->is_active = true;
        $user->save();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('customer.account')->with('success', __('Đã tạo tài khoản khách hàng.'));
    }

    public function authenticate(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255', 'remember' => 'sometimes|boolean']);
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'role' => 'customer', 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => __('Email hoặc mật khẩu không đúng.')])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->route('customer.account');
    }
}
