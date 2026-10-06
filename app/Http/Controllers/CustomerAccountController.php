<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class CustomerAccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('customer.account', ['customer' => $request->user(), 'orders' => $request->user()->orders()->latest('id')->paginate(10)]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => preg_replace('/[\s.()-]/', '', $request->input('phone'))]);
        }
        $data = $request->validate(['name' => 'required|string|max:100', 'phone' => ['nullable', 'regex:/^0[0-9]{9,10}$/'], 'default_address' => 'nullable|string|max:500']);
        $request->user()->update($data);

        return back()->with('success', __('Đã lưu thông tin cá nhân.'));
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => 'required|current_password:web', 'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $user = $request->user();
        $user->password = $data['password'];
        $user->remember_token = Str::random(60);
        $user->save();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login')->with('success', __('Đã đổi mật khẩu. Vui lòng đăng nhập lại.'));
    }
}
