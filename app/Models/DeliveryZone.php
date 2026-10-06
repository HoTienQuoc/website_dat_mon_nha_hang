<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_city', 'name_en', 'name', 'fee', 'is_active'])]
class DeliveryZone extends Model
{
    use HasFactory;

    public const WARDS = ['Nha Trang', 'Bắc Nha Trang', 'Tây Nha Trang', 'Nam Nha Trang'];

    public const SERVICE_CITY = 'Nha Trang';

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    protected function casts(): array
    {
        return ['fee' => 'integer', 'is_active' => 'boolean'];
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'en' ? ($this->name_en ?: $this->name) : $this->name;
    }
}
