<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('restaurant.demo_password')) {
            return;
        }
        foreach (['vi' => 'Khách hàng mẫu Việt', 'en' => 'Demo English customer'] as $locale => $name) {
            $user = User::firstOrNew(['email' => 'customer.'.$locale.'@bepnha.test']);
            if (! $user->exists) {
                $user->name = $name;
                $user->role = 'customer';
                $user->is_active = true;
                $user->password = config('restaurant.demo_password');
                $user->save();
            }
            if ($user->role !== 'customer') {
                continue;
            }
            $orders = Order::where('is_demo', true)->where('locale', $locale)->orderBy('id')->take(4)->get();
            foreach ($orders as $order) {
                if (! $order->user_id) {
                    $order->update(['user_id' => $user->id, 'customer_email' => $user->email]);
                }
            }
        }
    }
}
