<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'capacity', 'is_active'])]
class RestaurantTable extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Use a current locking read after locking the table row, not a transaction snapshot. */
    public function hasActiveOrderForUpdate(?int $exceptOrderId = null): bool
    {
        return $this->activeOrders()
            ->when($exceptOrderId, fn ($query) => $query->where('id', '!=', $exceptOrderId))
            ->lockForUpdate()->first(['id']) !== null;
    }

    public function activeOrders(): HasMany
    {
        return $this->orders()->whereNotIn('status', ['completed', 'cancelled']);
    }
}
