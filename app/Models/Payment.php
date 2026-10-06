<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider', 'order_id', 'attempt_key', 'stripe_session_id', 'stripe_payment_intent', 'amount', 'currency', 'status', 'refund_id', 'refund_key', 'refunded_amount', 'paid_at', 'expires_at'])]
class Payment extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'refunded_amount' => 'integer', 'paid_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
