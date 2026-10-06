<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'active' => 'nullable|in:0,1']);
        $query = User::where('role', 'customer')->withCount('orders');
        if (! empty($filters['q'])) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%')->orWhere('phone', 'like', '%'.$filters['q'].'%'));
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return view('admin.customers.index', ['customers' => $query->latest('id')->paginate(20)->withQueryString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = new User($data);
        $user->role = 'customer';
        $user->is_active = true;
        $user->save();

        return redirect()->route('admin.customers.edit', $user)->with('success', __('Đã tạo tài khoản khách hàng.'));
    }

    public function edit(User $customer): View
    {
        abort_unless($customer->role === 'customer', 404);

        return view('admin.customers.edit', ['customer' => $customer, 'orders' => $customer->orders()->latest('id')->paginate(10)]);
    }

    public function update(Request $request, User $customer): RedirectResponse
    {
        abort_unless($customer->role === 'customer', 404);
        $data = $this->validated($request, $customer);
        DB::transaction(function () use ($customer, $data) {
            $customer = User::whereKey($customer->id)->where('role', 'customer')->lockForUpdate()->firstOrFail();
            $changedEmail = $customer->email !== $data['email'];
            $customer->fill(collect($data)->except('is_active')->all());
            $customer->is_active = (bool) $data['is_active'];
            if ($changedEmail) {
                $customer->email_verified_at = null;
            }
            if (! $customer->is_active || $changedEmail) {
                $customer->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $customer->id)->delete();
            }
            $customer->save();
        });

        return back()->with('success', __('Đã cập nhật tài khoản khách hàng. Lịch sử đơn được giữ nguyên.'));
    }

    private function validated(Request $request, ?User $customer = null): array
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => preg_replace('/[\s.()-]/', '', $request->input('phone'))]);
        }
        $rules = ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($customer)], 'phone' => ['nullable', 'regex:/^0[0-9]{9,10}$/'], 'default_address' => 'nullable|string|max:500'];
        if ($customer) {
            $rules['is_active'] = 'required|boolean';
        } else {
            $rules['password'] = 'required|string|min:12|max:255|confirmed';
        }

        return $request->validate($rules);
    }
}
