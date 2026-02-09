<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePenaltyRequest extends FormRequest
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
            'penalty_value' => ['required', 'numeric', 'min:-50', 'max:0'],
            'penalty_type' => ['required', Rule::in(['absolute', 'percentage'])],
            'is_active' => ['required', 'boolean'],
            'color' => ['nullable', 'string', 'max:7'],
            'border_color' => ['nullable', 'string', 'max:7'],
            'opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
