<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Services\Cart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(Request $request, Cart $cart): View
    {
        $lines = $cart->lines($request->session()->get('cart', []));

        return view('cart.index', ['lines' => $lines, 'subtotal' => $lines->sum('line_total')]);
    }

    public function store(Request $request, Dish $dish): RedirectResponse
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:99']);
        if (! $dish->is_available) {
            return back()->withErrors(['cart' => __('Món này đang tạm hết.')]);
        }
        $cart = $request->session()->get('cart', []);
        $quantity = ($cart[$dish->id] ?? 0) + (int) $data['quantity'];
        if ($quantity > 99) {
            return back()->withErrors(['quantity' => __('Mỗi món tối đa 99 phần.')]);
        }
        $cart[$dish->id] = $quantity;
        $request->session()->put('cart', $cart);

        return back()->with('success', __('Đã thêm món vào giỏ hàng.'));
    }

    public function update(Request $request, int $dish): RedirectResponse
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:99']);
        $cart = $request->session()->get('cart', []);
        abort_unless(isset($cart[$dish]), 404);
        $cart[$dish] = (int) $data['quantity'];
        $request->session()->put('cart', $cart);

        return back()->with('success', __('Đã cập nhật số lượng.'));
    }

    public function destroy(Request $request, int $dish): RedirectResponse
    {
        $cart = $request->session()->get('cart', []);
        unset($cart[$dish]);
        $request->session()->put('cart', $cart);

        return back()->with('success', __('Đã xóa món khỏi giỏ.'));
    }
}
