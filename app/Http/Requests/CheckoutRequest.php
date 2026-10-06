<?php

namespace App\Http\Requests;

use App\Models\DeliveryZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->customer_email)) {
            $this->merge(['customer_email' => strtolower(trim($this->customer_email))]);
        }
        if (is_string($this->customer_phone)) {
            $this->merge(['customer_phone' => preg_replace('/[\s.()-]/', '', $this->customer_phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'payment_method' => 'sometimes|required|in:cash,stripe,demo_card', 'customer_email' => 'nullable|required_without:customer_phone|email|max:255',
            'customer_name' => 'required|string|max:100', 'customer_phone' => ['nullable', 'required_without:customer_email', 'regex:/^0[0-9]{9,10}$/'],
            'fulfillment_type' => ['required', Rule::in(['dine_in', 'pickup', 'delivery'])],
            'delivery_ward' => ['exclude_unless:fulfillment_type,delivery', 'required', Rule::in(DeliveryZone::WARDS)],
            'address' => 'exclude_unless:fulfillment_type,delivery|required|string|min:8|max:500',
            'delivery_zone_id' => ['exclude_unless:fulfillment_type,delivery', 'required', 'integer', Rule::exists('delivery_zones', 'id')->where('is_active', true)->where('service_city', DeliveryZone::SERVICE_CITY)],
            'delivery_city' => ['exclude_unless:fulfillment_type,delivery', 'sometimes', Rule::in([DeliveryZone::SERVICE_CITY])],
            'note' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return ['delivery_ward.required' => __('Vui lòng chọn phường giao hàng tại Nha Trang.'), 'delivery_ward.in' => __('Vui lòng chọn phường giao hàng tại Nha Trang.'), 'delivery_city.in' => __('Nhà hàng chỉ giao hàng trong khu vực Nha Trang.'), 'customer_phone.required_without' => __('Vui lòng nhập email hoặc số điện thoại để nhà hàng liên hệ.'), 'customer_email.required_without' => __('Vui lòng nhập email hoặc số điện thoại để nhà hàng liên hệ.'), 'customer_name.required' => __('Vui lòng nhập tên người nhận.'), 'customer_phone.required' => __('Vui lòng nhập số điện thoại.'), 'customer_phone.regex' => __('Số điện thoại phải gồm 10–11 chữ số, bắt đầu bằng 0.'), 'fulfillment_type.required' => __('Vui lòng chọn hình thức nhận món.'), 'address.required' => __('Vui lòng nhập địa chỉ giao hàng.'), 'address.min' => __('Địa chỉ cần ít nhất 8 ký tự.'), 'delivery_zone_id.required' => __('Vui lòng chọn vùng giao hàng.'), 'delivery_zone_id.exists' => __('Nhà hàng chưa phục vụ địa chỉ ngoài vùng giao hàng.')];
    }
}
