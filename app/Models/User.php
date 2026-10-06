<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'default_address'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STAFF_ROLES = [
        'admin' => 'Chủ nhà hàng', 'manager' => 'Quản lý vận hành', 'cashier' => 'Thu ngân',
        'head_chef' => 'Bếp trưởng', 'chef' => 'Đầu bếp', 'kitchen_assistant' => 'Phụ bếp',
        'waiter' => 'Phục vụ', 'courier' => 'Giao hàng',
    ];

    public function isKitchen(): bool
    {
        return in_array($this->role, ['head_chef', 'chef', 'kitchen_assistant'], true);
    }

    public function coordinatesOrders(): bool
    {
        return $this->isStaff() && in_array($this->role, ['admin', 'manager', 'cashier'], true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isStaff(): bool
    {
        return $this->is_active && in_array($this->role, array_keys(self::STAFF_ROLES), true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
