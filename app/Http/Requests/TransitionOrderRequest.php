<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(array_keys(Order::STATUSES))], 'expected_status' => ['required', Rule::in(array_keys(Order::STATUSES))], 'reason' => 'nullable|string|max:1000', 'cash_collected' => 'sometimes|boolean', 'paid' => 'sometimes|boolean', 'handed_over' => 'sometimes|boolean', 'approve_exception' => 'sometimes|boolean'];
    }
}
