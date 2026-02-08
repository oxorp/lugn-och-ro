<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePoiCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'safety_sensitivity' => ['required', 'numeric', 'min:0', 'max:3'],
            'signal' => ['required', Rule::in(['positive', 'negative', 'neutral'])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
