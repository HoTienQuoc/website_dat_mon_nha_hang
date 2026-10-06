<?php

namespace App\Services;

use App\Models\Dish;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Cart
{
    public function lines(array $cart, bool $strict = false, bool $lock = false): Collection
    {
        if ($strict && empty($cart)) {
            throw ValidationException::withMessages(['cart' => __('Giỏ hàng đang trống.')]);
        }
        $query = Dish::whereIn('id', array_keys($cart))->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $dishes = $query->get()->keyBy('id');

        return collect($cart)->map(function ($quantity, $id) use ($dishes, $strict) {
            $dish = $dishes->get($id);
            if ($strict && (! $dish || ! $dish->is_available || ! is_int($quantity) || $quantity < 1 || $quantity > 99)) {
                throw ValidationException::withMessages(['cart' => __('Một món đã ngừng bán hoặc số lượng không hợp lệ. Vui lòng cập nhật giỏ hàng.')]);
            }

            return ['id' => $id, 'dish' => $dish, 'quantity' => $quantity, 'line_total' => ($dish?->price ?? 0) * $quantity];
        })->values();
    }
}
