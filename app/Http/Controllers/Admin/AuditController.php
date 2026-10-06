<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['action' => 'nullable|in:created,updated,deleted', 'subject_type' => 'nullable|in:User,Dish,Order,Payment,RestaurantSetting,DeliveryZone,CashShift']);

        return view('admin.audit', ['logs' => AuditLog::with('actor')->when($data['action'] ?? null, fn ($q, $v) => $q->where('action', $v))->when($data['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))->latest('id')->paginate(30)->withQueryString()]);
    }
}
