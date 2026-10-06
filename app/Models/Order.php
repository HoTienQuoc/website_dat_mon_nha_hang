<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['merged_into_id', 'split_from_id', 'delivery_ward', 'address_verified_at', 'address_verified_by', 'completed_by', 'cash_shift_id', 'restaurant_table_id', 'table_name', 'cod_collected_at', 'cod_collected_by', 'cod_settled_at', 'user_id', 'payment_method', 'payment_status', 'locale', 'customer_email', 'is_demo', 'demo_key', 'cancellation_requested_at', 'cancellation_request_reason', 'code', 'idempotency_key', 'checkout_owner', 'request_fingerprint', 'customer_name', 'customer_phone', 'fulfillment_type', 'address', 'delivery_zone_id', 'delivery_zone_name_en', 'delivery_zone_name', 'status', 'subtotal', 'delivery_fee', 'total', 'note', 'cancellation_reason', 'paid_at', 'completed_at'])]
#[Hidden(['checkout_owner', 'request_fingerprint', 'idempotency_key'])]
class Order extends Model
{
    use HasFactory;

    public const STATUSES = ['pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'preparing' => 'Đang chuẩn bị', 'ready' => 'Sẵn sàng', 'served' => 'Đã phục vụ', 'delivering' => 'Đang giao', 'delivered' => 'Đã giao', 'delivery_failed' => 'Giao thất bại', 'completed' => 'Hoàn tất', 'cancelled' => 'Đã hủy'];

    public function tasks(): HasMany
    {
        return $this->hasMany(OrderTask::class);
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function assignedTo(User $user, ?string $kind = null): bool
    {
        return $this->tasks()->where('assigned_to', $user->id)->when($kind, fn ($q) => $q->where('kind', $kind))->exists();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getContactAttribute(): string
    {
        return $this->customer_phone ?: (string) $this->customer_email;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return ['address_verified_at' => 'datetime', 'cod_collected_at' => 'datetime', 'cod_settled_at' => 'datetime', 'cancellation_requested_at' => 'datetime', 'is_demo' => 'boolean', 'paid_at' => 'datetime', 'completed_at' => 'datetime', 'subtotal' => 'integer', 'delivery_fee' => 'integer', 'total' => 'integer'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status]);
    }

    public function getDisplayDeliveryZoneAttribute(): ?string
    {
        return app()->getLocale() === 'en' ? ($this->delivery_zone_name_en ?: $this->delivery_zone_name) : $this->delivery_zone_name;
    }

    public function paymentLabel(): string
    {
        return __(match ($this->payment_status) {
            'paid' => 'Đã thanh toán','pending' => 'Chờ thanh toán','refund_pending' => 'Chờ hoàn tiền','partially_refunded' => 'Đã hoàn tiền một phần','refunded' => 'Đã hoàn tiền','refund_failed' => 'Hoàn tiền thất bại',default => 'Chưa thanh toán'
        });
    }
}
