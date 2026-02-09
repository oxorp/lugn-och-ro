<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScorePenaltySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('score_penalties')->upsert([
            [
                'slug' => 'vuln_sarskilt_utsatt',
                'name' => 'Särskilt utsatt område',
                'description' => 'Område med parallella samhällsstrukturer, systematisk ovilja att medverka i rättsprocessen, och extremism som påverkar lokalsamhället. Klassificerat av Polismyndigheten.',
                'category' => 'vulnerability',
                'penalty_type' => 'absolute',
                'penalty_value' => -15.00,
                'is_active' => true,
                'applies_to' => 'composite_score',
                'display_order' => 1,
                'color' => '#dc2626',
                'border_color' => '#991b1b',
                'opacity' => 0.20,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'vuln_utsatt',
                'name' => 'Utsatt område',
                'description' => 'Område med låg socioekonomisk status, kriminell påverkan på lokalsamhället, och invånare som upplever otrygghet. Klassificerat av Polismyndigheten.',
                'category' => 'vulnerability',
                'penalty_type' => 'absolute',
                'penalty_value' => -8.00,
                'is_active' => true,
                'applies_to' => 'composite_score',
                'display_order' => 2,
                'color' => '#f97316',
                'border_color' => '#c2410c',
                'opacity' => 0.15,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'category', 'penalty_type', 'penalty_value', 'is_active', 'display_order', 'color', 'border_color', 'opacity', 'updated_at']);
    }
}
