import { usePage } from '@inertiajs/react';
import type { ScoreColorConfig } from '@/types/score-colors';
import type { SharedData } from '@/types';

export function useScoreColors(): ScoreColorConfig {
    const { scoreColors } = usePage<SharedData>().props;
    return scoreColors;
}
