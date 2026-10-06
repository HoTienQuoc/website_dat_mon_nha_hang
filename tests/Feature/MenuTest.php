<?php

namespace Tests\Feature;

use App\Models\Dish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_filters_by_name_and_category(): void
    {
        $dish = Dish::factory()->create(['name' => 'Cơm gà']);
        Dish::factory()->create(['name' => 'Bún bò']);
        $this->get('/?'.http_build_query(['q' => 'Cơm', 'category' => $dish->category_id]))->assertOk()->assertSee('Cơm gà')->assertDontSee('Bún bò');
    }

    public function test_menu_shows_price_and_unavailable_status(): void
    {
        Dish::factory()->create(['name' => 'Cá kho', 'price' => 75000, 'is_available' => false]);
        $this->get('/')->assertOk()->assertSee('75.000')->assertSee('Tạm hết');
    }

    public function test_menu_shows_empty_state(): void
    {
        $this->get('/')->assertOk()->assertSee('Không tìm thấy món phù hợp.');
    }

    public function test_invalid_search_is_rejected(): void
    {
        $this->get('/?q[]=bad')->assertSessionHasErrors('q');
    }
}
