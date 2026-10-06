<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'name_en', 'address', 'address_en', 'phone', 'email', 'opens_at', 'closes_at', 'accepting_orders', 'enforce_hours', 'minimum_order', 'preparation_minutes'])]
class RestaurantSetting extends Model
{
    protected function casts(): array
    {
        return ['accepting_orders' => 'boolean', 'enforce_hours' => 'boolean', 'minimum_order' => 'integer', 'preparation_minutes' => 'integer'];
    }

    public static function current(): self
    {
        return static::find(1) ?? new self(['name' => 'Bếp Nhà', 'name_en' => 'Bep Nha Kitchen', 'opens_at' => '09:00', 'closes_at' => '22:00', 'accepting_orders' => true, 'enforce_hours' => false, 'minimum_order' => 0, 'preparation_minutes' => 25]);
    }

    public function isOpen(): bool
    {
        if (! $this->accepting_orders) {
            return false;
        }
        if (! $this->enforce_hours || $this->opens_at === $this->closes_at) {
            return true;
        }
        $time = now()->format('H:i');

        return $this->opens_at < $this->closes_at ? ($time >= $this->opens_at && $time < $this->closes_at) : ($time >= $this->opens_at || $time < $this->closes_at);
    }

    public function assertCanOrder(int $subtotal): void
    {
        if (! $this->isOpen()) {
            throw ValidationException::withMessages(['restaurant' => __('Nhà hàng hiện tạm ngừng nhận đơn. Vui lòng quay lại trong giờ phục vụ.')]);
        }
        if ($subtotal < $this->minimum_order) {
            throw ValidationException::withMessages(['cart' => __('Đơn tối thiểu :amount ₫ (chưa tính phí giao).', ['amount' => number_format($this->minimum_order, 0, ',', '.')])]);
        }
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'en' ? ($this->name_en ?: $this->name) : $this->name;
    }

    public function getDisplayAddressAttribute(): ?string
    {
        return app()->getLocale() === 'en' ? ($this->address_en ?: $this->address) : $this->address;
    }
}
