<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
        if (is_string($this->phone)) {
            $this->merge(['phone' => preg_replace('/[\s.()-]/', '', $this->phone)]);
        }
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email', 'phone' => ['nullable', 'regex:/^0[0-9]{9,10}$/'], 'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()]];
    }
}
