<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['preparation_status', 'dish_name_en', 'dish_id', 'dish_name', 'unit_price', 'quantity'])]
class OrderItem extends Model
{
    public const PREPARATION = ['cancelled' => 'Đã hủy món', 'pending' => 'Chờ làm', 'preparing' => 'Đang làm', 'ready' => 'Món sẵn sàng', 'served' => 'Đã bàn giao'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['unit_price' => 'integer', 'quantity' => 'integer'];
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'en' ? ($this->dish_name_en ?: $this->dish_name) : $this->dish_name;
    }
}
