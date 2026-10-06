<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()?->role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return ['name_en' => 'nullable|string|max:150', 'description_en' => 'nullable|string|max:2000', 'allergens' => 'nullable|string|max:255', 'allergens_en' => 'nullable|string|max:255', 'is_vegetarian' => 'sometimes|boolean', 'is_spicy' => 'sometimes|boolean', 'name' => 'required|string|max:150', 'category_id' => 'required|integer|exists:categories,id', 'description' => 'nullable|string|max:2000', 'price' => 'required|integer|min:1000|max:10000000', 'is_available' => 'required|boolean', 'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048', 'remove_image' => 'sometimes|boolean'];
    }

    public function messages(): array
    {
        return ['name.required' => __('Vui lòng nhập tên món.'), 'price.min' => __('Giá phải từ 1.000 đồng.'), 'price.integer' => __('Giá phải là số nguyên đồng.'), 'category_id.exists' => __('Nhóm món không tồn tại.'), 'image.image' => __('Chỉ chấp nhận tệp ảnh.'), 'image.mimes' => __('Ảnh phải là JPG, PNG hoặc WebP.'), 'image.max' => __('Ảnh tối đa 2 MB.')];
    }
}
