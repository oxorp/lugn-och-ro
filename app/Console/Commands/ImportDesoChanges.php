<?php

namespace App\Console\Commands;

use App\Models\DesoArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportDesoChanges extends Command
{
    protected $signature = 'import:deso-changes
        {--dry-run : Show what would be imported without writing to database}';

    protected $description = 'Detect DeSO 2018→2025 boundary changes by comparing code lists from SCB API';

    /**
     * Fetch DeSO 2018 codes from a historical SCB table (population 2023).
     *
     * @return string[]
     */
    private function fetchDeso2018Codes(): array
    {
        $this->info('Fetching DeSO 2018 codes from SCB population table (year 2023)...');

        $url = 'https://api.scb.se/OV0104/v1/doris/en/ssd/BE/BE0101/BE0101Y/FolkmDesoAldKon';

        $body = [
            'query' => [
                ['code' => 'Region', 'selection' => ['filter' => 'all', 'values' => ['*']]],
                ['code' => 'ContentsCode', 'selection' => ['filter' => 'item', 'values' => ['000007Y7']]],
                ['code' => 'Alder', 'selection' => ['filter' => 'item', 'values' => ['totalt']]],
                ['code' => 'Kon', 'selection' => ['filter' => 'item', 'values' => ['1+2']]],
                ['code' => 'Tid', 'selection' => ['filter' => 'item', 'values' => ['2023']]],
            ],
            'response' => ['format' => 'json-stat2'],
        ];

        $response = Http::timeout(120)->post($url, $body);

        if (! $response->successful()) {
            throw new \RuntimeException('SCB API returned '.$response->status());
        }

        $data = $response->json();
        $regionIndex = $data['dimension']['Region']['category']['index'] ?? [];

        $codes2018 = [];
        foreach (array_keys($regionIndex) as $regionCode) {
            // Strip any suffix
            $code = preg_replace('/_(?:DeSO|RegSO)\d+$/', '', $regionCode);

            // Only DeSO codes (9 chars: 4-digit kommun + letter + 4 digits)
            if (preg_match('/^\d{4}[A-Z]\d{4}$/', $code)) {
                // Skip codes that have _DeSO2025 suffix (they're 2025 codes in mixed tables)
                if (str_contains($regionCode, '_DeSO2025')) {
                    continue;
                }
                $codes2018[$code] = true;
            }
        }

        $this->info('  Found '.count($codes2018).' DeSO 2018 codes');

        return array_keys($codes2018);
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Get DeSO 2025 codes from our database
        $codes2025 = DesoArea::query()->pluck('deso_code')->toArray();
        $codes2025Set = array_flip($codes2025);
        $this->info('DeSO 2025 codes in database: '.count($codes2025));

        // Fetch DeSO 2018 codes from SCB API
        $codes2018 = $this->fetchDeso2018Codes();
        $codes2018Set = array_flip($codes2018);

        // Compare code sets
        $inBoth = [];
        $onlyIn2018 = [];
        $onlyIn2025 = [];

        foreach ($codes2018 as $code) {
            if (isset($codes2025Set[$code])) {
                $inBoth[] = $code;
            } else {
                $onlyIn2018[] = $code;
            }
        }

        foreach ($codes2025 as $code) {
            if (! isset($codes2018Set[$code])) {
                $onlyIn2025[] = $code;
            }
        }

        $this->info('Codes in both 2018 and 2025: '.count($inBoth).' (unchanged)');
        $this->info('Codes only in 2018 (retired): '.count($onlyIn2018));
        $this->info('Codes only in 2025 (new/result of change): '.count($onlyIn2025));

        // Classify the changes using kommun-based heuristics
        $boundaryChanges = [];
        $codeMappings = [];

        // Codes in both sets: unchanged (same code, same or cosmetically adjusted boundary)
        foreach ($inBoth as $code) {
            $boundaryChanges[] = [
                'deso_2018_code' => $code,
                'deso_2025_code' => $code,
                'change_type' => 'unchanged',
                'notes' => null,
            ];
            $codeMappings[] = [
                'old_code' => $code,
                'new_code' => $code,
                'mapping_type' => 'identical',
            ];
        }

        // Group retired (2018-only) and new (2025-only) by kommun+letter prefix
        $retired2018ByPrefix = $this->groupByPrefix($onlyIn2018);
        $new2025ByPrefix = $this->groupByPrefix($onlyIn2025);

        // All prefixes involved in changes
        $allPrefixes = array_unique(array_merge(
            array_keys($retired2018ByPrefix),
            array_keys($new2025ByPrefix)
        ));

        foreach ($allPrefixes as $prefix) {
            $oldCodes = $retired2018ByPrefix[$prefix] ?? [];
            $newCodes = $new2025ByPrefix[$prefix] ?? [];

            if (count($oldCodes) === 1 && count($newCodes) === 1) {
                // 1:1 mapping — recoded (same area, different code)
                $boundaryChanges[] = [
                    'deso_2018_code' => $oldCodes[0],
                    'deso_2025_code' => $newCodes[0],
                    'change_type' => 'recoded',
                    'notes' => 'One-to-one mapping within prefix '.$prefix,
                ];
                $codeMappings[] = [
                    'old_code' => $oldCodes[0],
                    'new_code' => $newCodes[0],
                    'mapping_type' => 'recoded',
                ];
            } elseif (count($oldCodes) === 1 && count($newCodes) > 1) {
                // 1:N — split
                foreach ($newCodes as $newCode) {
                    $boundaryChanges[] = [
                        'deso_2018_code' => $oldCodes[0],
                        'deso_2025_code' => $newCode,
                        'change_type' => 'split',
                        'notes' => 'One 2018 area split into '.count($newCodes).' areas',
                    ];
                }
            } elseif (count($oldCodes) > 1 && count($newCodes) === 1) {
                // N:1 — merged
                foreach ($oldCodes as $oldCode) {
                    $boundaryChanges[] = [
                        'deso_2018_code' => $oldCode,
                        'deso_2025_code' => $newCodes[0],
                        'change_type' => 'merged',
                        'notes' => count($oldCodes).' areas merged into one',
                    ];
                }
            } elseif (count($oldCodes) > 0 && count($newCodes) > 0) {
                // N:M — complex reorganization (treat as splits)
                foreach ($oldCodes as $oldCode) {
                    foreach ($newCodes as $newCode) {
                        $boundaryChanges[] = [
                            'deso_2018_code' => $oldCode,
                            'deso_2025_code' => $newCode,
                            'change_type' => 'split',
                            'notes' => 'Complex reorganization: '.count($oldCodes).' old → '.count($newCodes).' new within prefix '.$prefix,
                        ];
                    }
                }
            } elseif (count($oldCodes) > 0 && count($newCodes) === 0) {
                // Retired with no replacement in same prefix — might be cross-prefix merge
                foreach ($oldCodes as $oldCode) {
                    $boundaryChanges[] = [
                        'deso_2018_code' => $oldCode,
                        'deso_2025_code' => $oldCode,
                        'change_type' => 'merged',
                        'notes' => 'Retired with no same-prefix replacement',
                    ];
                }
            } else {
                // New with no predecessor — genuinely new area
                foreach ($newCodes as $newCode) {
                    $boundaryChanges[] = [
                        'deso_2018_code' => $newCode,
                        'deso_2025_code' => $newCode,
                        'change_type' => 'new',
                        'notes' => 'New area with no 2018 predecessor in prefix '.$prefix,
                    ];
                }
            }
        }

        // Summarize
        $changeCounts = collect($boundaryChanges)->countBy('change_type');
        $this->newLine();
        $this->info('Change classification:');
        foreach ($changeCounts as $type => $count) {
            $this->info("  {$type}: {$count}");
        }

        $this->newLine();
        $this->info('Code mappings (for historical data translation): '.count($codeMappings));

        if ($dryRun) {
            $this->warn('Dry run — no changes written to database.');

            return self::SUCCESS;
        }

        // Write to database
        $this->info('Writing to database...');
        $now = now()->toDateTimeString();

        DB::table('deso_boundary_changes')->truncate();
        foreach (array_chunk($boundaryChanges, 1000) as $chunk) {
            $rows = array_map(fn ($row) => array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]), $chunk);
            DB::table('deso_boundary_changes')->insert($rows);
        }
        $this->info('  Inserted '.count($boundaryChanges).' boundary change records');

        DB::table('deso_code_mappings')->truncate();
        foreach (array_chunk($codeMappings, 1000) as $chunk) {
            $rows = array_map(fn ($row) => array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]), $chunk);
            DB::table('deso_code_mappings')->insert($rows);
        }
        $this->info('  Inserted '.count($codeMappings).' code mappings');

        // Update trend_eligible on deso_areas
        $this->updateTrendEligibility();

        return self::SUCCESS;
    }

    /**
     * Group codes by their kommun+letter prefix (first 5 characters).
     *
     * @param  string[]  $codes
     * @return array<string, string[]>
     */
    private function groupByPrefix(array $codes): array
    {
        $groups = [];
        foreach ($codes as $code) {
            $prefix = substr($code, 0, 5); // e.g., "0114A"
            $groups[$prefix][] = $code;
        }

        return $groups;
    }

    private function updateTrendEligibility(): void
    {
        $this->info('Updating trend eligibility...');

        // Default: all eligible
        DesoArea::query()->update(['trend_eligible' => true]);

        // Mark ineligible: DeSOs that are new, split, or merged
        $ineligibleCodes = DB::table('deso_boundary_changes')
            ->whereIn('change_type', ['new', 'split', 'merged'])
            ->pluck('deso_2025_code')
            ->unique()
            ->toArray();

        if (count($ineligibleCodes) > 0) {
            DesoArea::query()
                ->whereIn('deso_code', $ineligibleCodes)
                ->update(['trend_eligible' => false]);
        }

        $eligible = DesoArea::query()->where('trend_eligible', true)->count();
        $ineligible = DesoArea::query()->where('trend_eligible', false)->count();

        $this->info("  Trend eligible: {$eligible}");
        $this->info("  Trend ineligible: {$ineligible}");

        Log::info('DeSO trend eligibility updated', [
            'eligible' => $eligible,
            'ineligible' => $ineligible,
        ]);
    }
}
