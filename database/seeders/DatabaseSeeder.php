<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([MenuSeeder::class, DeliveryZoneSeeder::class, StaffSeeder::class, RestaurantDemoSeeder::class, CustomerDemoSeeder::class, OperationsSeeder::class]);
    }
}
