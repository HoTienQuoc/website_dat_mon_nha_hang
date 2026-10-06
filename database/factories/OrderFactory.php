<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Khách kiểm thử', 'customer_phone' => '0901234567', 'fulfillment_type' => 'pickup', 'status' => 'pending', 'subtotal' => 65000, 'delivery_fee' => 0, 'total' => 65000];
    }
}
