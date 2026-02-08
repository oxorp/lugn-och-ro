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
            'normalization_scope' => ['required', Rule::in(['national', 'urbanity_stratified'])],
            'is_active' => ['required', 'boolean'],
            'is_free_preview' => ['sometimes', 'boolean'],
            'description_short' => ['nullable', 'string', 'max:100'],
            'description_long' => ['nullable', 'string', 'max:500'],
            'methodology_note' => ['nullable', 'string', 'max:300'],
            'national_context' => ['nullable', 'string', 'max:100'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'update_frequency' => ['nullable', 'string', 'max:255'],
        ];
    }
}
