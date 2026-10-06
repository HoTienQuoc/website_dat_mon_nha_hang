<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryZoneController extends Controller
{
    public function index(): View
    {
        return view('admin.zones.index', ['zones' => DeliveryZone::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        DeliveryZone::create($this->validated($request));

        return back()->with('success', __('Đã thêm vùng giao.'));
    }

    public function update(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $data = $this->validated($request);
        if ($zone->service_city !== DeliveryZone::SERVICE_CITY) {
            return back()->withErrors(['zone' => __('Vùng cũ ngoài Nha Trang không thể kích hoạt. Hãy tạo vùng mới trong Nha Trang.')]);
        }
        $zone->update($data);

        return back()->with('success', __('Đã cập nhật vùng giao.'));
    }

    public function destroy(DeliveryZone $zone): RedirectResponse
    {
        if ($zone->orders()->exists()) {
            return back()->withErrors(['zone' => __('Vùng đã có đơn hàng. Hãy tắt phục vụ thay vì xóa.')]);
        }
        try {
            $zone->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true)) {
                return back()->withErrors(['zone' => __('Vùng đã được sử dụng. Hãy tắt phục vụ.')]);
            } throw $e;
        }

        return back()->with('success', __('Đã xóa vùng giao.'));
    }

    private function validated(Request $request): array
    {
        return ['service_city' => DeliveryZone::SERVICE_CITY] + $request->validate(['name' => 'required|string|max:150', 'name_en' => 'nullable|string|max:150', 'fee' => 'required|integer|min:0|max:1000000', 'is_active' => 'required|boolean']);
    }
}
