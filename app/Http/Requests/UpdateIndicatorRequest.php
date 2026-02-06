<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIndicatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No auth for now — future task
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['positive', 'negative', 'neutral'])],
            'weight' => ['required', 'numeric', 'min:0', 'max:1'],
            'normalization' => ['required', Rule::in(['rank_percentile', 'min_max', 'z_score'])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
