<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryZoneFactory extends Factory
{
    public function definition(): array
    {
        return ['service_city' => DeliveryZone::SERVICE_CITY, 'name' => fake()->city(), 'fee' => 15000, 'is_active' => true];
    }
}
