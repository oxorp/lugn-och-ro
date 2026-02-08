import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

import { faCircleInfo } from '@/icons';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export interface IndicatorMeta {
    name: string;
    description_short: string | null;
    description_long: string | null;
    methodology_note: string | null;
    national_context: string | null;
    source_name: string | null;
    source_url: string | null;
    update_frequency: string | null;
    data_vintage: string | null;
    data_last_ingested_at: string | null;
    unit: string | null;
    direction: 'positive' | 'negative' | 'neutral';
    category: string | null;
}

interface InfoTooltipProps {
    indicator: IndicatorMeta;
}

function formatRelativeDate(isoDate: string): string {
    const date = new Date(isoDate);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 30) return `${diffDays} days ago`;
    if (diffDays < 365) return `${Math.floor(diffDays / 30)} months ago`;
    return date.toLocaleDateString();
}

export function InfoTooltip({ indicator }: InfoTooltipProps) {
    if (!indicator.description_long && !indicator.description_short) {
        return null;
    }

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        className="text-muted-foreground hover:text-foreground ml-1 inline-flex items-center transition-colors"
                        aria-label={`More info about ${indicator.name}`}
                    >
                        <FontAwesomeIcon icon={faCircleInfo} className="h-3.5 w-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    className="max-w-80 text-sm"
                    side="left"
                    align="start"
                    collisionPadding={16}
                >
                    <div className="space-y-2">
                        <p className="font-medium">{indicator.name}</p>
                        <p className="text-muted-foreground">
                            {indicator.description_long ?? indicator.description_short}
                        </p>

                        {indicator.methodology_note && (
                            <p className="text-muted-foreground border-muted border-l-2 pl-2 text-xs italic">
                                {indicator.methodology_note}
                            </p>
                        )}

                        {indicator.national_context && (
                            <p className="text-muted-foreground border-muted border-l-2 pl-2 text-xs">
                                {indicator.national_context}
                            </p>
                        )}

                        <div className="border-t pt-1">
                            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                                {indicator.source_name && (
                                    <span>
                                        Source:{' '}
                                        {indicator.source_url ? (
                                            <a
                                                href={indicator.source_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="underline hover:text-foreground"
                                            >
                                                {indicator.source_name}
                                            </a>
                                        ) : (
                                            indicator.source_name
                                        )}
                                    </span>
                                )}
                                {indicator.data_vintage && (
                                    <span>Data from: {indicator.data_vintage}</span>
                                )}
                            </div>
                            {indicator.update_frequency && (
                                <p className="text-muted-foreground mt-0.5 text-xs">
                                    {indicator.update_frequency}
                                </p>
                            )}
                            {indicator.data_last_ingested_at && (
                                <p className="text-muted-foreground mt-0.5 text-xs">
                                    Last updated: {formatRelativeDate(indicator.data_last_ingested_at)}
                                </p>
                            )}
                        </div>
                    </div>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface ScoreTooltipProps {
    score: number;
    scoreLabel: string;
    computedAt?: string | null;
}

export function ScoreTooltip({ score, scoreLabel, computedAt }: ScoreTooltipProps) {
    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        className="text-muted-foreground hover:text-foreground ml-1 inline-flex items-center transition-colors"
                        aria-label="How the score works"
                    >
                        <FontAwesomeIcon icon={faCircleInfo} className="h-4 w-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    className="max-w-80 text-sm"
                    side="left"
                    align="start"
                    collisionPadding={16}
                >
                    <div className="space-y-2">
                        <p className="font-medium">Neighborhood Trajectory Score</p>
                        <p className="text-muted-foreground">
                            A composite score from 0 to 100 that combines multiple indicators — income,
                            employment, education, school quality, safety, and more — into a single number.
                            A score of {score.toFixed(0)} means this area outperforms roughly {score.toFixed(0)}%
                            of all ~6,160 areas nationwide.
                        </p>
                        <div className="text-muted-foreground space-y-0.5 text-xs">
                            <div className="font-medium">Score ranges:</div>
                            <div className="grid grid-cols-[auto_1fr] gap-x-2">
                                <span>80-100:</span><span>Strong Growth Area</span>
                                <span>60-79:</span><span>Positive Indicators</span>
                                <span>40-59:</span><span>Mixed Signals</span>
                                <span>20-39:</span><span>Challenging Area</span>
                                <span>0-19:</span><span>High Risk Area</span>
                            </div>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Current assessment: <span className="font-medium">{scoreLabel}</span>
                        </p>
                        {computedAt && (
                            <p className="text-muted-foreground border-t pt-1 text-xs">
                                Last computed: {new Date(computedAt).toLocaleDateString()}
                            </p>
                        )}
                    </div>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface SchoolStatTooltipProps {
    stat: 'merit_value' | 'goal_achievement' | 'teacher_certification';
}

const schoolStatInfo: Record<string, { title: string; description: string; context: string }> = {
    merit_value: {
        title: 'Meritvärde (Merit Value)',
        description: 'The sum of a student\'s 16 best subject grades plus an optional 17th (moderna språk). This is the primary measure of school quality in Sweden and determines upper secondary school placement.',
        context: 'National average: ~228 points. Top schools: 270+. Maximum possible: 340.',
    },
    goal_achievement: {
        title: 'Goal Achievement',
        description: 'The percentage of year-9 students who achieved at least grade E in all subjects. Measures how well the school serves its entire student population.',
        context: 'National average: ~76%.',
    },
    teacher_certification: {
        title: 'Teacher Certification (Lärarbehörighet)',
        description: 'The share of teachers certified to teach their assigned subjects. Higher certification correlates with better student outcomes.',
        context: 'National average: ~72%.',
    },
};

export function SchoolStatTooltip({ stat }: SchoolStatTooltipProps) {
    const info = schoolStatInfo[stat];
    if (!info) return null;

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        className="text-muted-foreground hover:text-foreground ml-1 inline-flex items-center transition-colors"
                        aria-label={`More info about ${info.title}`}
                    >
                        <FontAwesomeIcon icon={faCircleInfo} className="h-3 w-3" />
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    className="max-w-72 text-sm"
                    side="left"
                    align="start"
                    collisionPadding={16}
                >
                    <div className="space-y-2">
                        <p className="font-medium">{info.title}</p>
                        <p className="text-muted-foreground">{info.description}</p>
                        <p className="text-muted-foreground border-muted border-l-2 pl-2 text-xs">
                            {info.context}
                        </p>
                        <p className="text-muted-foreground border-t pt-1 text-xs">
                            Source: <a href="https://www.skolverket.se" target="_blank" rel="noopener noreferrer" className="underline hover:text-foreground">Skolverket</a>
                        </p>
                    </div>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface TrendTooltipProps {
    trend: number | null;
}

export function TrendTooltip({ trend }: TrendTooltipProps) {
    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        className="text-muted-foreground hover:text-foreground ml-0.5 inline-flex items-center transition-colors"
                        aria-label="About this trend"
                    >
                        <FontAwesomeIcon icon={faCircleInfo} className="h-3 w-3" />
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    className="max-w-64 text-sm"
                    side="left"
                    align="start"
                    collisionPadding={16}
                >
                    <div className="space-y-1.5">
                        <p className="font-medium">Score Trend</p>
                        {trend !== null ? (
                            <p className="text-muted-foreground">
                                The composite score changed by {trend > 0 ? '+' : ''}{trend.toFixed(1)} points
                                over the past year. {trend > 1 ? 'A green arrow means improvement.' : trend < -1 ? 'A red arrow means decline.' : 'Minimal change.'}
                            </p>
                        ) : (
                            <p className="text-muted-foreground">
                                Trend data not available for this area. This can happen when the area's
                                boundaries changed between measurement periods or when only one year of data exists.
                            </p>
                        )}
                    </div>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface NoDataTooltipProps {
    reason?: 'no_schools' | 'no_data' | 'suppressed';
}

export function NoDataTooltip({ reason = 'no_data' }: NoDataTooltipProps) {
    const messages: Record<string, string> = {
        no_schools: 'No schools are located within this area. School quality data is based on grundskolor physically within the area boundary.',
        no_data: 'Data for this indicator is not yet available. This can happen for newly created statistical areas or areas where the source agency suppresses data for privacy reasons.',
        suppressed: 'This value has been suppressed by the source agency to protect individual privacy (too few observations).',
    };

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        className="text-muted-foreground hover:text-foreground ml-1 inline-flex items-center transition-colors"
                        aria-label="Why is data missing?"
                    >
                        <FontAwesomeIcon icon={faCircleInfo} className="h-3 w-3" />
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    className="max-w-64 text-sm"
                    side="left"
                    align="start"
                    collisionPadding={16}
                >
                    <p className="text-muted-foreground">{messages[reason]}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
