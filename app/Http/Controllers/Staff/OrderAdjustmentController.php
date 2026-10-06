<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderAdjustments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAdjustmentController extends Controller
{
    public function update(Request $request, OrderItem $item, OrderAdjustments $adjustments): RedirectResponse
    {
        $data = $request->validate(['quantity' => 'required|integer|min:0|max:20', 'expected_quantity' => 'required|integer|min:1|max:100', 'expected_status' => 'required|string|max:30', 'reason' => 'required|string|min:5|max:500', 'approve_prepared' => 'sometimes|accepted']);
        DB::transaction(function () use ($request, $item, $data, $adjustments) {
            $order = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $adjustments->authorize($request->user(), $order);
            $adjustments->assertEditable($order);
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->quantity !== (int) $data['expected_quantity'] || $item->preparation_status !== $data['expected_status']) {
                throw ValidationException::withMessages(['quantity' => __('Món đã được cập nhật. Vui lòng tải lại màn hình.')]);
            }
            if ($item->preparation_status !== 'pending') {
                abort_unless(in_array($request->user()->role, ['admin', 'manager'], true), 403);
                if (empty($data['approve_prepared'])) {
                    throw ValidationException::withMessages(['approve_prepared' => __('Cần quản lý duyệt sửa món đã chế biến.')]);
                }
                if ($data['quantity'] > $item->quantity) {
                    throw ValidationException::withMessages(['quantity' => __('Món đã làm chỉ được giảm hoặc hủy; dùng gọi thêm món để tăng số lượng.')]);
                }
            }
            $before = $item->quantity;
            $item->update(['quantity' => $data['quantity'], 'preparation_status' => $data['quantity'] == 0 ? 'cancelled' : $item->preparation_status]);
            $adjustments->recalculate($order, $request->user(), $item->display_name.' · '.$before.' → '.$data['quantity'].' · '.$data['reason']);
        }, 3);

        return back()->with('success', __('Đã điều chỉnh món và tổng tiền.'));
    }
}
