<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        foreach (range(1, 8) as $number) {
            RestaurantTable::firstOrCreate(['name' => 'B'.str_pad((string) $number, 2, '0', STR_PAD_LEFT)], ['capacity' => $number <= 6 ? 4 : 6, 'is_active' => true]);
        }
        if (! config('restaurant.demo_password')) {
            return;
        }
        foreach (User::STAFF_ROLES as $role => $label) {
            $email = $role === 'manager' ? 'operations@bepnha.test' : $role.'@bepnha.test';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $user = new User;
            $user->name = $label;
            $user->email = $email;
            $user->password = config('restaurant.demo_password');
            $user->role = $role;
            $user->is_active = true;
            $user->save();
        }

        $owner = User::where('role', 'admin')->where('is_active', true)->first();
        $dish = Dish::where('is_available', true)->first();
        $zone = DeliveryZone::where('service_city', DeliveryZone::SERVICE_CITY)->where('is_active', true)->first();
        if (! $owner || ! $dish || ! $zone) {
            return;
        }
        foreach ([['dine_in', 'confirmed'], ['dine_in', 'ready'], ['delivery', 'ready'], ['delivery', 'delivery_failed']] as $index => [$type,$status]) {
            DB::transaction(function () use ($index, $type, $status, $owner, $dish, $zone) {
                $key = 'operations-v1-'.$index;
                if (Order::where('demo_key', $key)->exists()) {
                    return;
                }
                $table = $type === 'dine_in' ? RestaurantTable::where('name', 'B0'.($index + 1))->lockForUpdate()->first() : null;
                if ($table?->activeOrders()->exists()) {
                    $table = null;
                }
                $fee = $type === 'delivery' ? $zone->fee : 0;
                $order = Order::create(['code' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'demo_key' => $key, 'is_demo' => true, 'customer_name' => 'Khách mẫu vận hành '.($index + 1), 'customer_phone' => '090000010'.($index + 1), 'fulfillment_type' => $type, 'status' => $status, 'subtotal' => $dish->price, 'delivery_fee' => $fee, 'total' => $dish->price + $fee, 'payment_method' => 'cash', 'payment_status' => 'unpaid', 'locale' => $index % 2 ? 'en' : 'vi', 'note' => 'Đơn mẫu phân công nhân sự. Không phục vụ thực tế. / Staff training order. Do not fulfil.', 'restaurant_table_id' => $table?->id, 'table_name' => $table?->name, 'delivery_ward' => $type === 'delivery' ? 'Nha Trang' : null, 'address' => $type === 'delivery' ? '12 Đường Mẫu, Nha Trang (minh họa)' : null, 'delivery_zone_id' => $type === 'delivery' ? $zone->id : null, 'delivery_zone_name' => $type === 'delivery' ? $zone->name : null, 'delivery_zone_name_en' => $type === 'delivery' ? $zone->name_en : null]);
                $order->items()->create(['preparation_status' => $status === 'confirmed' ? 'pending' : 'ready', 'dish_id' => $dish->id, 'dish_name' => $dish->name, 'dish_name_en' => $dish->name_en, 'unit_price' => $dish->price, 'quantity' => 1]);
                $roles = ['preparation' => 'kitchen_assistant', 'cooking' => 'chef', ($type === 'dine_in' ? 'service' : 'delivery') => ($type === 'dine_in' ? 'waiter' : 'courier')];
                foreach ($roles as $kind => $role) {
                    $staff = User::where('role', $role)->where('is_active', true)->first();
                    if (! $staff) {
                        continue;
                    }
                    $taskStatus = in_array($kind, ['preparation', 'cooking'], true) && $status !== 'confirmed' ? 'done' : ($kind === 'delivery' && $status === 'delivery_failed' ? 'failed' : 'pending');
                    $order->tasks()->create(['kind' => $kind, 'assigned_to' => $staff->id, 'assigned_by' => $owner->id, 'status' => $taskStatus, 'note' => 'Dữ liệu đào tạo / Training data']);
                }
                $order->histories()->create(['user_id' => $owner->id, 'to_status' => $status, 'reason' => 'Dữ liệu đào tạo / Training data']);
            }, 3);
        }
    }
}
