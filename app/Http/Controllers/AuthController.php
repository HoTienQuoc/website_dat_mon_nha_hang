<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        if (! Auth::attempt([...$data, 'is_active' => true, 'role' => array_keys(User::STAFF_ROLES)])) {
            return back()->withErrors(['email' => __('Email hoặc mật khẩu không đúng.')])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended(route('staff.orders.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('menu.index');
    }
}
