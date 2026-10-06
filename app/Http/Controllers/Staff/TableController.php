<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TableController extends Controller
{
    public function index(Request $request): View
    {
        return view('staff.tables.index', ['tables' => RestaurantTable::with(['activeOrders' => fn ($q) => $q->when($request->user()->role === 'waiter', fn ($q) => $q->whereHas('tasks', fn ($q) => $q->where('assigned_to', $request->user()->id)))])->withCount('activeOrders')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        RestaurantTable::create($this->validated($request));

        return back()->with('success', __('Đã lưu bàn ăn.'));
    }

    public function update(Request $request, RestaurantTable $table): RedirectResponse
    {
        $data = $this->validated($request, $table);
        DB::transaction(function () use ($table, $data) {
            $table = RestaurantTable::whereKey($table->id)->lockForUpdate()->firstOrFail();
            if ($table->hasActiveOrderForUpdate()) {
                throw ValidationException::withMessages(['table' => __('Bàn đang có khách, chỉ sửa thông tin sau khi hoàn tất đơn.')]);
            }
            $table->update($data);
        }, 3);

        return back()->with('success', __('Đã lưu bàn ăn.'));
    }

    public function assign(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['restaurant_table_id' => 'required|integer']);
        DB::transaction(function () use ($request, $order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $table = RestaurantTable::whereKey($data['restaurant_table_id'])->where('is_active', true)->lockForUpdate()->first();
            if ($order->fulfillment_type !== 'dine_in' || ! in_array($order->status, ['pending', 'confirmed', 'preparing', 'ready', 'served'], true) || ! $table || $table->hasActiveOrderForUpdate($order->id)) {
                throw ValidationException::withMessages(['restaurant_table_id' => __('Bàn không khả dụng hoặc đơn không thể xếp bàn.')]);
            }
            $previousTable = $order->table_name;
            $order->update(['restaurant_table_id' => $table->id, 'table_name' => $table->name]);
            $order->histories()->create(['user_id' => $request->user()->id, 'from_status' => $order->status, 'to_status' => $order->status, 'reason' => __('Xếp bàn').': '.($previousTable ? $previousTable.' → ' : '').$table->name]);
        }, 3);

        return back()->with('success', __('Đã xếp bàn cho đơn.'));
    }

    private function validated(Request $request, ?RestaurantTable $table = null): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:50', Rule::unique('restaurant_tables')->ignore($table)], 'capacity' => 'required|integer|min:1|max:30', 'is_active' => 'required|boolean']);
    }
}
