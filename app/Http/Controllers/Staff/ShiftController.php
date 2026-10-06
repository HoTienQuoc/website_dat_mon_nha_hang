<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\User;
use App\Services\CsvExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShiftController extends Controller
{
    public function index(Request $request): View
    {
        return view('staff.shifts.index', ['orders' => Order::where('completed_by', $request->user()->id)->whereNull('cash_shift_id')->where('status', 'completed')->where('payment_status', 'paid')->orderBy('completed_at')->get(), 'shifts' => CashShift::with('cashier')->when($request->user()->role !== 'admin', fn ($q) => $q->where('user_id', $request->user()->id))->latest()->paginate(20)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = CashShift::with('cashier')->when($request->user()->role !== 'admin', fn ($q) => $q->where('user_id', $request->user()->id));

        return app(CsvExport::class)->download('cash-shifts-'.now()->format('Y-m-d').'.csv',
            [__('Đối soát ca'), __('Thu ngân'), __('Từ ngày'), __('Đến ngày'), __('Tiền mặt dự kiến'), __('Tiền mặt thực đếm'), __('Chênh lệch'), 'Stripe Test', __('Dữ liệu mẫu'), __('Đơn'), __('Ghi chú')],
            $query->lazyById(500)->map(fn ($s) => [$s->id, $s->cashier->name, $s->from_at->format('Y-m-d H:i:s'), $s->to_at->format('Y-m-d H:i:s'), $s->expected_cash, $s->counted_cash, $s->counted_cash - $s->expected_cash, $s->stripe_total, $s->demo_total, $s->order_count, $s->note]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['counted_cash' => 'required|integer|min:0|max:1000000000', 'note' => 'nullable|string|max:1000', 'expected_order_ids' => 'required|string|max:20000']);
        DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $orders = Order::where('completed_by', $request->user()->id)->whereNull('cash_shift_id')->where('status', 'completed')->where('payment_status', 'paid')->orderBy('id')->lockForUpdate()->get();
            if ($orders->isEmpty() || $orders->pluck('id')->implode(',') !== $data['expected_order_ids']) {
                throw ValidationException::withMessages(['shift' => __('Danh sách thu tiền đã thay đổi hoặc đã chốt ca. Vui lòng tải lại.')]);
            }
            $cash = $orders->where('payment_method', 'cash')->where('is_demo', false)->sum('total');
            if ($cash !== (int) $data['counted_cash'] && empty(trim($data['note'] ?? ''))) {
                throw ValidationException::withMessages(['note' => __('Vui lòng giải thích chênh lệch tiền mặt.')]);
            }
            $shift = CashShift::create(['user_id' => $request->user()->id, 'from_at' => $orders->min('completed_at'), 'to_at' => now(), 'expected_cash' => $cash, 'counted_cash' => $data['counted_cash'], 'stripe_total' => $orders->where('payment_method', 'stripe')->where('is_demo', false)->sum('total'), 'demo_total' => $orders->where('is_demo', true)->sum('total'), 'order_count' => $orders->count(), 'note' => $data['note'] ?? null]);
            Order::whereIn('id', $orders->pluck('id'))->update(['cash_shift_id' => $shift->id]);
        }, 3);

        return back()->with('success', __('Đã chốt đối soát ca.'));
    }
}
