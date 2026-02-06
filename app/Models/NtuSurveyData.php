<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NtuSurveyData extends Model
{
    protected $table = 'ntu_survey_data';

    protected $fillable = [
        'area_code',
        'area_type',
        'area_name',
        'survey_year',
        'reference_year',
        'indicator_slug',
        'value',
        'confidence_lower',
        'confidence_upper',
        'respondent_count',
        'data_source',
    ];

    /**
     * @return array{value: 'decimal:2', confidence_lower: 'decimal:2', confidence_upper: 'decimal:2'}
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'confidence_lower' => 'decimal:2',
            'confidence_upper' => 'decimal:2',
        ];
    }
}
