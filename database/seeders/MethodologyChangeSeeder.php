<?php

namespace Database\Seeders;

use App\Models\Indicator;
use App\Models\MethodologyChange;
use Illuminate\Database\Seeder;

class MethodologyChangeSeeder extends Seeder
{
    public function run(): void
    {
        $changes = [
            [
                'source' => 'scb',
                'indicator_slug' => 'employment_rate',
                'year_affected' => 2019,
                'change_type' => 'definition_change',
                'description' => 'SCB introduced a new time series for employment statistics in 2019, using a revised population register and new methodology. Data before and after 2019 are not directly comparable.',
                'breaks_trend' => false, // Our trend window is 2022-2024, so this doesn't affect us
                'source_url' => 'https://www.scb.se/hitta-statistik/statistik-efter-amne/arbetsmarknad/',
            ],
        ];

        foreach ($changes as $change) {
            $indicator = Indicator::query()
                ->where('slug', $change['indicator_slug'])
                ->first();

            MethodologyChange::query()->updateOrCreate(
                [
                    'source' => $change['source'],
                    'indicator_id' => $indicator?->id,
                    'year_affected' => $change['year_affected'],
                ],
                [
                    'change_type' => $change['change_type'],
                    'description' => $change['description'],
                    'breaks_trend' => $change['breaks_trend'],
                    'source_url' => $change['source_url'],
                ]
            );
        }
    }
}
