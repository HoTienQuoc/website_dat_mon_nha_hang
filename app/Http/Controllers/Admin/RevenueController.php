<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $data = $request->validate(['dataset' => 'nullable|in:all,real,demo', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => __('Ngày kết thúc phải từ ngày bắt đầu trở đi.')]);
        }
        $query = Order::where('status', 'completed')->whereNotNull('paid_at')->where('payment_status', 'paid')->whereBetween('completed_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()]);
        if (($data['dataset'] ?? 'all') !== 'all') {
            $query->where('is_demo', $data['dataset'] === 'demo');
        }
        if ($request->routeIs('admin.revenue.export')) {
            return app(CsvExport::class)->download('revenue-'.$from.'-'.$to.'.csv',
                [__('Đơn'), __('Khách'), __('Hoàn tất'), __('Tiền món'), __('Phí giao'), __('Tổng đã thu'), __('Phương thức thanh toán'), __('Nguồn dữ liệu')],
                $query->orderBy('id')->lazyById(500)->map(fn ($o) => [$o->id, $o->customer_name, $o->completed_at->format('Y-m-d H:i:s'), $o->subtotal, $o->delivery_fee, $o->total, $o->payment_method, $o->is_demo ? 'demo' : 'real']));
        }
        $summary = (clone $query)->selectRaw('COUNT(*) as order_count, COALESCE(SUM(subtotal),0) as food_total, COALESCE(SUM(delivery_fee),0) as delivery_total, COALESCE(SUM(total),0) as revenue')->first();
        $orders = $query->orderByDesc('completed_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.revenue', compact('from', 'to', 'summary', 'orders'));
    }
}
