<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderLookupController extends Controller
{
    public function create(): View
    {
        return view('orders.lookup');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|uuid', 'contact' => 'nullable|required_without:phone|string|max:255', 'phone' => 'nullable|required_without:contact|string|max:255']);
        $contact = trim($data['contact'] ?? $data['phone']);
        $query = Order::where('code', $data['code']);
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $query->where('customer_email', strtolower($contact));
        } else {
            $query->where('customer_phone', preg_replace('/[\s.()-]/', '', $contact));
        }
        $order = $query->first();
        if (! $order || ($order->user_id && ! $request->user()?->can('view', $order))) {
            return back()->withErrors(['lookup' => __('Không tìm thấy đơn khớp. Kiểm tra mã và liên hệ; đơn có tài khoản cần đăng nhập đúng tài khoản.')]);
        }
        $ids = $request->session()->get('verified_orders', []);
        $ids[] = $order->id;
        $request->session()->put('verified_orders', array_slice(array_unique($ids), -30));

        return redirect()->route('orders.show', $order->code);
    }
}
