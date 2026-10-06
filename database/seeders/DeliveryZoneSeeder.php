<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use App\Models\RestaurantSetting;
use Illuminate\Database\Seeder;

class DeliveryZoneSeeder extends Seeder
{
    public function run(): void
    {
        RestaurantSetting::where('address', '12 Đường Mẫu, Phường Bến Thành, TP.HCM (địa chỉ minh họa)')->update(['address' => '12 Đường Mẫu, Nha Trang (địa chỉ minh họa)', 'address_en' => '12 Sample Street, Nha Trang (demo address)']);
        DeliveryZone::whereNull('service_city')->orWhere('service_city', '!=', DeliveryZone::SERVICE_CITY)->update(['is_active' => false]);
        DeliveryZone::firstOrCreate(['name' => 'Nội thành Nha Trang', 'service_city' => DeliveryZone::SERVICE_CITY], ['name_en' => 'Nha Trang city area', 'fee' => 15000, 'is_active' => true]);
    }
}
