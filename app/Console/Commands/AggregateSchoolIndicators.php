<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Models\Indicator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateSchoolIndicators extends Command
{
    use LogsIngestion;

    protected $signature = 'aggregate:school-indicators
        {--academic-year=2024/25 : Academic year to aggregate (e.g. 2023/24)}
        {--calendar-year= : Override the calendar year for indicator_values (defaults to second year of academic year)}';

    protected $description = 'Aggregate school statistics to DeSO-level indicators';

    public function handle(): int
    {
        $academicYear = $this->option('academic-year');
        $calendarYear = (int) ($this->option('calendar-year') ?: $this->academicYearToCalendar($academicYear));

        $this->startIngestionLog('skolverket_stats', 'aggregate:school-indicators');

        try {
            $this->info("Aggregating school statistics for academic year {$academicYear} → calendar year {$calendarYear}");
            $this->addStat('academic_year', $academicYear);
            $this->addStat('calendar_year', $calendarYear);

            $slugMap = [
                'school_merit_value_avg' => 'merit_value_17',
                'school_goal_achievement_avg' => 'goal_achievement_pct',
                'school_teacher_certification_avg' => 'teacher_certification_pct',
            ];

            $indicators = Indicator::query()
                ->whereIn('slug', array_keys($slugMap))
                ->get()
                ->keyBy('slug');

            if ($indicators->isEmpty()) {
                $this->error('No school quality indicators found. Run the migration first.');
                $this->failIngestionLog('No school quality indicators found');

                return self::FAILURE;
            }

            $now = now()->toDateTimeString();

            foreach ($slugMap as $slug => $statColumn) {
                $indicator = $indicators->get($slug);
                if (! $indicator) {
                    $this->addWarning("Indicator '{$slug}' not found, skipping.");

                    continue;
                }

                $this->info("Aggregating {$slug} ({$statColumn})...");

                // Compute weighted average per DeSO (weighted by student_count when available)
                $aggregates = DB::select("
                    SELECT
                        s.deso_code,
                        CASE
                            WHEN SUM(CASE WHEN ss.student_count IS NOT NULL AND ss.student_count > 0 THEN ss.student_count ELSE 0 END) > 0
                            THEN SUM(ss.{$statColumn} * COALESCE(ss.student_count, 1)) / SUM(CASE WHEN ss.{$statColumn} IS NOT NULL THEN COALESCE(ss.student_count, 1) ELSE 0 END)
                            ELSE AVG(ss.{$statColumn})
                        END AS avg_value,
                        COUNT(DISTINCT s.school_unit_code) AS school_count
                    FROM schools s
                    INNER JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
                    WHERE s.deso_code IS NOT NULL
                      AND s.status = 'active'
                      AND (s.school_forms::jsonb @> ?::jsonb OR s.type_of_schooling LIKE '%Grundskol%')
                      AND ss.academic_year = ?
                      AND ss.{$statColumn} IS NOT NULL
                    GROUP BY s.deso_code
                ", ['["Grundskola"]', $academicYear]);

                $rows = [];
                foreach ($aggregates as $agg) {
                    $rows[] = [
                        'deso_code' => $agg->deso_code,
                        'indicator_id' => $indicator->id,
                        'year' => $calendarYear,
                        'raw_value' => round($agg->avg_value, 2),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $this->processed++;
                }

                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table('indicator_values')->upsert(
                        $chunk,
                        ['deso_code', 'indicator_id', 'year'],
                        ['raw_value', 'updated_at']
                    );
                }

                $this->updated += count($rows);
                $this->addStat($slug, count($rows));
                $this->info("  → {$slug}: ".count($rows).' DeSOs with data');
            }

            $this->newLine();
            $this->info('Aggregation complete. Run normalize:indicators and compute:scores to update composite scores.');
            $this->completeIngestionLog();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failIngestionLog($e->getMessage());
            $this->error("Aggregation failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function academicYearToCalendar(string $academicYear): int
    {
        // "2023/24" → 2024, "2024/25" → 2025
        $parts = explode('/', $academicYear);

        if (count($parts) === 2) {
            $century = substr($parts[0], 0, 2);

            return (int) ($century.$parts[1]);
        }

        return (int) $academicYear;
    }
}
