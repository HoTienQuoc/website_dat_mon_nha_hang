<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', ['settings' => RestaurantSetting::current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'name_en' => 'required|string|max:100', 'address' => 'required|string|max:255', 'address_en' => 'required|string|max:255', 'phone' => 'required|string|max:30', 'email' => 'nullable|email|max:255', 'opens_at' => 'required|date_format:H:i', 'closes_at' => 'required|date_format:H:i', 'accepting_orders' => 'required|boolean', 'enforce_hours' => 'required|boolean', 'minimum_order' => 'required|integer|min:0|max:1000000', 'preparation_minutes' => 'required|integer|min:5|max:180']);
        $settings = RestaurantSetting::firstOrNew(['id' => 1]);
        $settings->fill($data);
        $settings->save();

        return back()->with('success', __('Đã lưu cấu hình nhà hàng.'));
    }
}
