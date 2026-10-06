<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function update(Request $request, OrderTask $task): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:in_progress,done', 'expected_status' => 'required|in:pending,in_progress']);
        DB::transaction(function () use ($request, $task, $data) {
            $order = Order::whereKey($task->order_id)->lockForUpdate()->firstOrFail();
            $task = OrderTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->kind === 'preparation' && $task->assigned_to === $request->user()->id && $request->user()->role === 'kitchen_assistant', 403);
            if (! in_array($order->status, ['confirmed', 'preparing'], true) || $task->status !== $data['expected_status'] || ($task->status === 'pending' ? $data['status'] !== 'in_progress' : $data['status'] !== 'done')) {
                throw ValidationException::withMessages(['status' => __('Công việc đã thay đổi hoặc không còn được phép cập nhật.')]);
            }
            $task->update(['status' => $data['status']]);
            $order->histories()->create(['user_id' => $request->user()->id, 'from_status' => $order->status, 'to_status' => $order->status, 'reason' => __('Sơ chế').': '.__(OrderTask::STATUSES[$data['status']])]);
        }, 3);

        return back()->with('success', __('Đã cập nhật công việc.'));
    }
}
