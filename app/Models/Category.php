<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name_en', 'name', 'slug'])]
class Category extends Model
{
    use HasFactory;

    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'en' ? ($this->name_en ?: $this->name) : $this->name;
    }
}
