import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
    faBus,
    faCar,
    faCartShopping,
    faCheck,
    faCircleQuestion,
    faGraduationCap,
    faHeartPulse,
    faPersonWalking,
    faShieldHalved,
    faTree,
    faUtensils,
    faVolumeOff,
} from '@/icons';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface PriorityOption {
    key: string;
    label_sv: string;
    icon: string;
}

interface QuestionnaireConfig {
    priority_options: PriorityOption[];
    max_priorities: number;
    walking_distances: Record<number, string>;
    default_walking_distance: number;
    labels: {
        question_1_title: string;
        question_1_subtitle: string;
        question_2_title: string;
        question_2_subtitle: string;
        question_3_title: string;
        question_3_subtitle: string;
        yes: string;
        no: string;
    };
}

export interface UserPreferences {
    priorities: string[];
    walking_distance_minutes: number;
    has_car: boolean | null;
}

interface QuestionnaireStepProps {
    urbanityTier: 'urban' | 'semi_urban' | 'rural';
    config: QuestionnaireConfig;
    onComplete: (preferences: UserPreferences) => void;
}

/**
 * Maps icon name string from config to FontAwesome icon definition.
 */
function getIcon(iconName: string): IconDefinition {
    const iconMap: Record<string, IconDefinition> = {
        'graduation-cap': faGraduationCap,
        'shield-halved': faShieldHalved,
        'tree': faTree,
        'cart-shopping': faCartShopping,
        'bus': faBus,
        'heart-pulse': faHeartPulse,
        'utensils': faUtensils,
        'volume-off': faVolumeOff,
    };
    return iconMap[iconName] ?? faCircleQuestion;
}

function PriorityCard({
    option,
    selected,
    disabled,
    onToggle,
}: {
    option: PriorityOption;
    selected: boolean;
    disabled: boolean;
    onToggle: () => void;
}) {
    const icon = getIcon(option.icon);

    return (
        <button
            type="button"
            onClick={onToggle}
            disabled={disabled && !selected}
            className={cn(
                'relative flex flex-col items-center gap-2 rounded-lg border-2 p-4 text-center transition-colors',
                selected
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/50',
                disabled && !selected && 'cursor-not-allowed opacity-50',
            )}
        >
            {selected && (
                <div className="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    <FontAwesomeIcon icon={faCheck} className="h-3 w-3" />
                </div>
            )}
            <FontAwesomeIcon
                icon={icon}
                className={cn(
                    'h-6 w-6',
                    selected ? 'text-primary' : 'text-muted-foreground',
                )}
            />
            <span className={cn('text-sm font-medium', selected && 'text-primary')}>
                {option.label_sv}
            </span>
        </button>
    );
}

function WalkingDistanceButton({
    minutes,
    label,
    selected,
    onSelect,
}: {
    minutes: number;
    label: string;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            className={cn(
                'flex items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-colors',
                selected
                    ? 'border-primary bg-primary/5 text-primary'
                    : 'border-border hover:border-primary/50',
            )}
        >
            <FontAwesomeIcon
                icon={faPersonWalking}
                className={cn('h-4 w-4', selected ? 'text-primary' : 'text-muted-foreground')}
            />
            {label}
        </button>
    );
}

function CarOwnershipButtons({
    value,
    labels,
    onSelect,
}: {
    value: boolean | null;
    labels: { yes: string; no: string };
    onSelect: (hasCar: boolean) => void;
}) {
    return (
        <div className="flex gap-3">
            <button
                type="button"
                onClick={() => onSelect(true)}
                className={cn(
                    'flex flex-1 items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-colors',
                    value === true
                        ? 'border-primary bg-primary/5 text-primary'
                        : 'border-border hover:border-primary/50',
                )}
            >
                <FontAwesomeIcon
                    icon={faCar}
                    className={cn('h-4 w-4', value === true ? 'text-primary' : 'text-muted-foreground')}
                />
                {labels.yes}
            </button>
            <button
                type="button"
                onClick={() => onSelect(false)}
                className={cn(
                    'flex flex-1 items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-colors',
                    value === false
                        ? 'border-primary bg-primary/5 text-primary'
                        : 'border-border hover:border-primary/50',
                )}
            >
                <FontAwesomeIcon
                    icon={faPersonWalking}
                    className={cn('h-4 w-4', value === false ? 'text-primary' : 'text-muted-foreground')}
                />
                {labels.no}
            </button>
        </div>
    );
}

export default function QuestionnaireStep({
    urbanityTier,
    config,
    onComplete,
}: QuestionnaireStepProps) {
    const [priorities, setPriorities] = useState<string[]>([]);
    const [walkingDistance, setWalkingDistance] = useState<number>(
        config.default_walking_distance,
    );
    const [hasCar, setHasCar] = useState<boolean | null>(null);

    const isRural = urbanityTier === 'rural';
    const maxPriorities = config.max_priorities;
    const atMaxPriorities = priorities.length >= maxPriorities;

    // For rural areas, car question is required
    const canContinue = !isRural || hasCar !== null;

    const togglePriority = (key: string) => {
        setPriorities((prev) => {
            if (prev.includes(key)) {
                return prev.filter((k) => k !== key);
            }
            if (prev.length < maxPriorities) {
                return [...prev, key];
            }
            return prev;
        });
    };

    const handleContinue = () => {
        onComplete({
            priorities,
            walking_distance_minutes: walkingDistance,
            has_car: isRural ? hasCar : null,
        });
    };

    // Get walking distances as sorted entries
    const walkingDistanceEntries = Object.entries(config.walking_distances)
        .map(([minutes, label]) => ({ minutes: parseInt(minutes, 10), label }))
        .sort((a, b) => a.minutes - b.minutes);

    return (
        <div className="space-y-8">
            {/* Question 1: Priorities */}
            <section>
                <h2 className="mb-1 text-xl font-semibold">
                    {config.labels.question_1_title}
                </h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    {config.labels.question_1_subtitle}
                </p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {config.priority_options.map((option) => (
                        <PriorityCard
                            key={option.key}
                            option={option}
                            selected={priorities.includes(option.key)}
                            disabled={atMaxPriorities}
                            onToggle={() => togglePriority(option.key)}
                        />
                    ))}
                </div>
                {priorities.length > 0 && (
                    <p className="mt-3 text-sm text-muted-foreground">
                        {priorities.length} av {maxPriorities} valda
                    </p>
                )}
            </section>

            {/* Question 2: Walking Distance */}
            <section>
                <h2 className="mb-1 text-xl font-semibold">
                    {config.labels.question_2_title}
                </h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    {config.labels.question_2_subtitle}
                </p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {walkingDistanceEntries.map(({ minutes, label }) => (
                        <WalkingDistanceButton
                            key={minutes}
                            minutes={minutes}
                            label={label}
                            selected={walkingDistance === minutes}
                            onSelect={() => setWalkingDistance(minutes)}
                        />
                    ))}
                </div>
            </section>

            {/* Question 3: Car Ownership (rural only) */}
            {isRural && (
                <section>
                    <h2 className="mb-1 text-xl font-semibold">
                        {config.labels.question_3_title}
                    </h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {config.labels.question_3_subtitle}
                    </p>
                    <CarOwnershipButtons
                        value={hasCar}
                        labels={{
                            yes: config.labels.yes,
                            no: config.labels.no,
                        }}
                        onSelect={setHasCar}
                    />
                </section>
            )}

            {/* Continue Button */}
            <Button
                onClick={handleContinue}
                disabled={!canContinue}
                className="w-full"
                size="lg"
            >
                Fortsätt →
            </Button>
        </div>
    );
}
