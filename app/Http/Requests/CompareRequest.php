<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'point_a' => ['required', 'array'],
            'point_a.lat' => ['required', 'numeric', 'between:55,70'],
            'point_a.lng' => ['required', 'numeric', 'between:10,25'],
            'point_b' => ['required', 'array'],
            'point_b.lat' => ['required', 'numeric', 'between:55,70'],
            'point_b.lng' => ['required', 'numeric', 'between:10,25'],
        ];
    }
}
