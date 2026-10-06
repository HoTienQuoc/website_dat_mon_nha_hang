<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('restaurant.demo_password')) {
            return;
        }
        foreach (['admin' => 'Chủ nhà hàng', 'cashier' => 'Thu ngân', 'head_chef' => 'Bếp trưởng'] as $role => $name) {
            $user = User::firstOrNew(['email' => $role.'@bepnha.test']);
            if ($user->exists) {
                continue;
            }
            $user->name = $name;
            $user->password = config('restaurant.demo_password');
            $user->role = $role;
            $user->is_active = true;
            $user->save();
        }
    }
}
