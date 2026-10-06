<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.staff.index', ['staff' => User::whereIn('role', array_keys(User::STAFF_ROLES))->when($request->user()->role !== 'admin', fn ($q) => $q->where('role', '!=', 'admin'))->orderBy('name')->paginate(20)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->role = $data['role'];
        $user->is_active = $data['is_active'];
        $user->save();

        return back()->with('success', __('Đã tạo tài khoản nhân viên.'));
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        abort_unless(in_array($staff->role, array_keys(User::STAFF_ROLES), true), 404);
        abort_if($staff->role === 'admin' && $request->user()->role !== 'admin', 403);
        $data = $this->validated($request, $staff);
        if ($staff->id === $request->user()->id && ($data['role'] !== $staff->role || ! $data['is_active'])) {
            return back()->withErrors(['role' => __('Không thể tự khóa hoặc hạ quyền tài khoản quản lý đang đăng nhập.')]);
        }
        if ($staff->role !== $data['role'] && OrderTask::where('assigned_to', $staff->id)->whereHas('order', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))->exists()) {
            return back()->withErrors(['role' => __('Hãy hoàn tất hoặc phân công lại đơn trước khi đổi vai trò.')]);
        }
        $staff->name = $data['name'];
        $staff->email = $data['email'];
        $staff->role = $data['role'];
        $staff->is_active = $data['is_active'];
        if (! empty($data['password'])) {
            $staff->password = $data['password'];
        }
        $staff->remember_token = Str::random(60);
        $staff->save();
        DB::table('sessions')->where('user_id', $staff->id)->delete();

        return back()->with('success', __('Đã cập nhật tài khoản và quyền truy cập.'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)], 'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:255', 'confirmed'], 'role' => ['required', Rule::in(array_keys($request->user()->role === 'admin' ? User::STAFF_ROLES : array_diff_key(User::STAFF_ROLES, ['admin' => true])))], 'is_active' => 'required|boolean']);
    }
}
