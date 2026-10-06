<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'from_at', 'to_at', 'expected_cash', 'counted_cash', 'stripe_total', 'demo_total', 'order_count', 'note'])]
class CashShift extends Model
{
    protected function casts(): array
    {
        return ['from_at' => 'datetime', 'to_at' => 'datetime', 'expected_cash' => 'integer', 'counted_cash' => 'integer', 'stripe_total' => 'integer', 'demo_total' => 'integer'];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
