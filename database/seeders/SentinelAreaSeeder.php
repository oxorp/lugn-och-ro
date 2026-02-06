<?php

namespace Database\Seeders;

use App\Models\SentinelArea;
use Illuminate\Database\Seeder;

class SentinelAreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            // Top sentinels — must score high
            [
                'deso_code' => '0162C1200',
                'name' => 'Danderyd centrum',
                'expected_tier' => 'top',
                'expected_score_min' => 65,
                'expected_score_max' => 95,
                'rationale' => "Sweden's wealthiest municipality. Consistently top income, schools, employment.",
            ],
            [
                'deso_code' => '0186C1060',
                'name' => 'Lidingö inner',
                'expected_tier' => 'top',
                'expected_score_min' => 65,
                'expected_score_max' => 95,
                'rationale' => 'Affluent island suburb of Stockholm. High income, excellent schools.',
            ],
            [
                'deso_code' => '1262C1040',
                'name' => 'Lomma centrum',
                'expected_tier' => 'top',
                'expected_score_min' => 70,
                'expected_score_max' => 95,
                'rationale' => 'Wealthy Skåne kommun. Top schools, high income, lowest debt rate in Sweden.',
            ],
            [
                'deso_code' => '1480C3630',
                'name' => 'Hovås/Askim (Göteborg)',
                'expected_tier' => 'top',
                'expected_score_min' => 60,
                'expected_score_max' => 90,
                'rationale' => 'Affluent area in south Gothenburg. High income, good schools.',
            ],

            // Bottom sentinels — must score low
            [
                'deso_code' => '0180C6250',
                'name' => 'Rinkeby (Stockholm)',
                'expected_tier' => 'bottom',
                'expected_score_min' => 2,
                'expected_score_max' => 25,
                'rationale' => "One of Sweden's most disadvantaged areas. Police 'särskilt utsatt.' Low income, employment, school results.",
            ],
            [
                'deso_code' => '1280C1800',
                'name' => 'Rosengård (Malmö)',
                'expected_tier' => 'bottom',
                'expected_score_min' => 2,
                'expected_score_max' => 25,
                'rationale' => 'Known vulnerable area. Low income, low employment, low school results.',
            ],
            [
                'deso_code' => '1480C3600',
                'name' => 'Angered/Hjällbo (Göteborg)',
                'expected_tier' => 'bottom',
                'expected_score_min' => 2,
                'expected_score_max' => 25,
                'rationale' => "Gothenburg's most disadvantaged area. Police 'särskilt utsatt.'",
            ],
            [
                'deso_code' => '1880C1590',
                'name' => 'Vivalla (Örebro)',
                'expected_tier' => 'bottom',
                'expected_score_min' => 2,
                'expected_score_max' => 30,
                'rationale' => 'Smaller city vulnerable area. 99.9% overlap with "särskilt utsatt" police zone.',
            ],

            // Middle sentinels — should be average
            [
                'deso_code' => '0486C1010',
                'name' => 'Strängnäs centrum',
                'expected_tier' => 'middle',
                'expected_score_min' => 35,
                'expected_score_max' => 70,
                'rationale' => 'Typical mid-size Swedish town. Should score mid-range.',
            ],
        ];

        foreach ($areas as $area) {
            SentinelArea::query()->updateOrCreate(
                ['deso_code' => $area['deso_code']],
                $area
            );
        }
    }
}
