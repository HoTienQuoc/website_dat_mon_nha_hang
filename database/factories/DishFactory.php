<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class DishFactory extends Factory
{
    public function definition(): array
    {
        return ['category_id' => Category::factory(), 'name' => fake()->words(3, true), 'slug' => fake()->unique()->slug(), 'price' => 65000, 'is_available' => true];
    }
}
