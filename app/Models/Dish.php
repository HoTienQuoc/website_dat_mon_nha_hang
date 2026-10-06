<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name_en', 'description_en', 'allergens', 'allergens_en', 'is_vegetarian', 'is_spicy', 'category_id', 'name', 'slug', 'description', 'price', 'image_path', 'is_available'])]
class Dish extends Model
{
    use HasFactory;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    protected function casts(): array
    {
        return ['price' => 'integer', 'is_available' => 'boolean'];
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'en' ? ($this->name_en ?: $this->name) : $this->name;
    }

    public function getDisplayDescriptionAttribute(): ?string
    {
        return app()->getLocale() === 'en' ? ($this->description_en ?: $this->description) : $this->description;
    }

    public function getDisplayAllergensAttribute(): ?string
    {
        return app()->getLocale() === 'en' ? ($this->allergens_en ?: $this->allergens) : $this->allergens;
    }
}
