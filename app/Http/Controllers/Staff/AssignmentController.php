<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignmentController extends Controller
{
    public function store(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->coordinatesOrders() || $request->user()->role === 'head_chef', 403);
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(OrderTask::KINDS))], 'assigned_to' => 'required|integer', 'note' => 'nullable|string|max:500']);
        abort_if($request->user()->role === 'head_chef' && ! in_array($data['kind'], ['preparation', 'cooking'], true), 403);
        DB::transaction(function () use ($request, $order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $assignee = User::whereKey($data['assigned_to'])->where('is_active', true)->whereIn('role', OrderTask::ROLES[$data['kind']])->lockForUpdate()->first();
            if (! $assignee) {
                throw ValidationException::withMessages(['assigned_to' => __('Nhân viên không hoạt động hoặc không đúng vai trò.')]);
            }
            $validStates = match ($data['kind']) {
                'preparation', 'cooking' => ['confirmed', 'preparing'],
                'service' => ['pending', 'confirmed', 'preparing', 'ready'],
                'delivery' => ['pending', 'confirmed', 'preparing', 'ready', 'delivery_failed'],
            };
            if (! in_array($order->status, $validStates, true) || ($data['kind'] === 'service' && $order->fulfillment_type !== 'dine_in') || ($data['kind'] === 'delivery' && $order->fulfillment_type !== 'delivery')) {
                throw ValidationException::withMessages(['kind' => __('Công việc không phù hợp với hình thức hoặc trạng thái đơn.')]);
            }
            $existing = $order->tasks()->where('kind', $data['kind'])->first();
            if ($existing?->status === 'done') {
                throw ValidationException::withMessages(['kind' => __('Công việc đã hoàn tất không thể phân công lại.')]);
            }
            $order->tasks()->updateOrCreate(['kind' => $data['kind']], ['assigned_to' => $assignee->id, 'assigned_by' => $request->user()->id, 'note' => $data['note'] ?? null, 'status' => $data['kind'] === 'cooking' && $order->status === 'preparing' ? 'in_progress' : 'pending']);
            $order->histories()->create(['user_id' => $request->user()->id, 'from_status' => $order->status, 'to_status' => $order->status, 'reason' => __('Phân công: :task — :name', ['task' => __(OrderTask::KINDS[$data['kind']]), 'name' => $assignee->name])]);
        }, 3);

        return back()->with('success', __('Đã phân công công việc.'));
    }
}
