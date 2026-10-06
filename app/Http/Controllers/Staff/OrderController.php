<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransitionOrderRequest;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\TransitionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Order::STATUSES))], 'code' => 'nullable|string|max:50']);
        $query = Order::query()->with('tasks.assignee');
        if ($request->user()->role === 'head_chef') {
            $query->whereIn('status', ['confirmed', 'preparing', 'ready']);
        }
        if (! $request->user()->coordinatesOrders() && $request->user()->role !== 'head_chef') {
            $query->whereHas('tasks', fn ($q) => $q->where('assigned_to', $request->user()->id));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['code'])) {
            $query->where('code', $filters['code']);
        }

        return view('staff.orders.index', ['orders' => $query->latest('id')->paginate(20)->withQueryString()]);
    }

    public function show(Request $request, Order $order, TransitionOrder $workflow): View
    {
        Gate::authorize('manage', $order);

        return view('staff.orders.show', ['order' => $order->load(['items', 'histories.user', 'payments', 'tasks.assignee', 'restaurantTable']), 'staffMembers' => User::where('is_active', true)->whereIn('role', array_keys(User::STAFF_ROLES))->orderBy('name')->get(), 'tables' => RestaurantTable::withCount('activeOrders')->where('is_active', true)->orderBy('name')->get(), 'mergeTargets' => $request->user()->coordinatesOrders() ? Order::where('fulfillment_type', 'dine_in')->where('payment_method', 'cash')->where('payment_status', 'unpaid')->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready', 'served'])->where('id', '!=', $order->id)->where('is_demo', $order->is_demo)->where('user_id', $order->user_id)->orderBy('id')->get() : collect(), 'allowed' => $workflow->allowed($order, $request->user())]);
    }

    public function update(TransitionOrderRequest $request, Order $order, TransitionOrder $workflow): RedirectResponse
    {
        Gate::authorize('manage', $order);
        $workflow->handle($order, $request->user(), $request->validated());

        return back()->with('success', __('Đã cập nhật đơn hàng.'));
    }
}
