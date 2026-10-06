<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddressVerificationController extends Controller
{
    public function store(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['delivery_ward' => ['required', Rule::in(DeliveryZone::WARDS)], 'confirmed' => 'required|accepted', 'expected_address' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->fulfillment_type !== 'delivery' || ! in_array($order->status, ['pending', 'confirmed', 'preparing', 'ready', 'delivery_failed'], true) || $order->address !== $data['expected_address']) {
                throw ValidationException::withMessages(['address' => __('Đơn đã thay đổi hoặc không thể xác minh địa chỉ.')]);
            }
            $order->update(['delivery_ward' => $data['delivery_ward'], 'address_verified_at' => now(), 'address_verified_by' => $request->user()->id]);
            $order->histories()->create(['user_id' => $request->user()->id, 'from_status' => $order->status, 'to_status' => $order->status, 'reason' => __('Đã đối chiếu địa chỉ giao hàng tại Nha Trang').' · '.$data['delivery_ward']]);
        }, 3);

        return back()->with('success', __('Đã xác minh địa chỉ giao hàng.'));
    }
}
