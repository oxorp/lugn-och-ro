<?php

namespace App\Services;

use App\DataTransferObjects\DriftReport;
use App\Models\ScoreVersion;
use Illuminate\Support\Facades\DB;

class ScoreDriftDetector
{
    public function detect(ScoreVersion $newVersion, ScoreVersion $previousVersion, float $driftThreshold = 20.0): DriftReport
    {
        $drifts = DB::select('
            SELECT
                new_cs.deso_code,
                old_cs.score AS old_score,
                new_cs.score AS new_score,
                new_cs.score - old_cs.score AS drift,
                ABS(new_cs.score - old_cs.score) AS abs_drift
            FROM composite_scores new_cs
            JOIN composite_scores old_cs
                ON old_cs.deso_code = new_cs.deso_code
            WHERE new_cs.score_version_id = ?
              AND old_cs.score_version_id = ?
            ORDER BY abs_drift DESC
        ', [$newVersion->id, $previousVersion->id]);

        if (empty($drifts)) {
            return new DriftReport(
                totalAreas: 0,
                meanDrift: 0,
                maxDrift: 0,
                meanScoreNew: (float) ($newVersion->mean_score ?? 0),
                meanScoreOld: (float) ($previousVersion->mean_score ?? 0),
                stddevNew: (float) ($newVersion->stddev_score ?? 0),
                stddevOld: (float) ($previousVersion->stddev_score ?? 0),
            );
        }

        $absDrifts = array_map(fn ($d) => (float) $d->abs_drift, $drifts);
        $meanDrift = array_sum($absDrifts) / count($absDrifts);
        $maxDrift = max($absDrifts);

        $largeDrifts = array_filter($drifts, fn ($d) => (float) $d->abs_drift > $driftThreshold);

        return new DriftReport(
            totalAreas: count($drifts),
            meanDrift: $meanDrift,
            maxDrift: $maxDrift,
            meanScoreNew: (float) ($newVersion->mean_score ?? 0),
            meanScoreOld: (float) ($previousVersion->mean_score ?? 0),
            stddevNew: (float) ($newVersion->stddev_score ?? 0),
            stddevOld: (float) ($previousVersion->stddev_score ?? 0),
            areasWithLargeDrift: array_values($largeDrifts),
        );
    }
}
