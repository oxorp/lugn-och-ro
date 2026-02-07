import {
    AlertTriangle,
    Bus,
    ChevronDown,
    ChevronRight,
    Coffee,
    Dumbbell,
    Droplets,
    Factory,
    Heart,
    MapPin,
    Mountain,
    ShoppingBag,
    Trees,
    Volume2,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { PoiCategory } from '@/lib/poi-config';
import { POI_GROUPS } from '@/lib/poi-config';

const GROUP_ICONS: Record<string, React.ReactNode> = {
    factory: <Factory className="h-3.5 w-3.5" />,
    droplets: <Droplets className="h-3.5 w-3.5" />,
    'volume-2': <Volume2 className="h-3.5 w-3.5" />,
    'alert-circle': <AlertTriangle className="h-3.5 w-3.5" />,
    zap: <Zap className="h-3.5 w-3.5" />,
    trees: <Trees className="h-3.5 w-3.5" />,
    'heart-pulse': <Heart className="h-3.5 w-3.5" />,
    coffee: <Coffee className="h-3.5 w-3.5" />,
    'shopping-bag': <ShoppingBag className="h-3.5 w-3.5" />,
    dumbbell: <Dumbbell className="h-3.5 w-3.5" />,
    bus: <Bus className="h-3.5 w-3.5" />,
    mountain: <Mountain className="h-3.5 w-3.5" />,
};

interface PoiControlsProps {
    categories: PoiCategory[];
    enabledCategories: Set<string>;
    visibleCount: number;
    onToggleCategory: (slug: string) => void;
    onToggleGroup: (slugs: string[]) => void;
    onEnableAll: () => void;
    onDisableAll: () => void;
    onResetDefaults: () => void;
}

export default function PoiControls({
    categories,
    enabledCategories,
    visibleCount,
    onToggleCategory,
    onToggleGroup,
    onEnableAll,
    onDisableAll,
    onResetDefaults,
}: PoiControlsProps) {
    const [isExpanded, setIsExpanded] = useState(false);
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(
        new Set(['nuisances', 'amenities']),
    );

    // Build a lookup from category slug → PoiCategory
    const catLookup = new Map<string, PoiCategory>();
    for (const cat of categories) {
        catLookup.set(cat.slug, cat);
    }

    const toggleGroupExpanded = (key: string) => {
        setExpandedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const totalEnabled = enabledCategories.size;

    return (
        <div className="absolute top-16 left-4 z-10">
            <Button
                variant="outline"
                size="sm"
                className="border-border bg-background/95 shadow-md backdrop-blur-sm"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <MapPin className="mr-1 h-4 w-4" />
                POI
                {visibleCount > 0 && (
                    <span className="ml-1 text-muted-foreground">
                        ({visibleCount.toLocaleString()})
                    </span>
                )}
                <ChevronDown
                    className={`ml-1 h-3 w-3 transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                />
            </Button>

            {isExpanded && (
                <Card className="mt-2 w-60 border-border shadow-lg">
                    <ScrollArea className="max-h-[55vh]">
                        <CardContent className="space-y-2 p-3">
                            {Object.entries(POI_GROUPS).map(
                                ([groupKey, group]) => {
                                    const isGroupExpanded =
                                        expandedGroups.has(groupKey);
                                    const groupSentiment =
                                        groupKey === 'nuisances'
                                            ? 'text-orange-500'
                                            : groupKey === 'amenities'
                                              ? 'text-green-600'
                                              : 'text-muted-foreground';

                                    return (
                                        <div key={groupKey}>
                                            <button
                                                className={`flex w-full items-center gap-1.5 text-left text-xs font-semibold uppercase tracking-wider ${groupSentiment}`}
                                                onClick={() =>
                                                    toggleGroupExpanded(
                                                        groupKey,
                                                    )
                                                }
                                            >
                                                {isGroupExpanded ? (
                                                    <ChevronDown className="h-3 w-3" />
                                                ) : (
                                                    <ChevronRight className="h-3 w-3" />
                                                )}
                                                {group.label}
                                            </button>

                                            {isGroupExpanded && (
                                                <div className="mt-1 space-y-0.5 pl-1">
                                                    {Object.entries(
                                                        group.subcategories,
                                                    ).map(
                                                        ([
                                                            subKey,
                                                            subGroup,
                                                        ]) => {
                                                            const types =
                                                                subGroup.types as readonly string[];
                                                            const enabledCount =
                                                                types.filter(
                                                                    (t) =>
                                                                        enabledCategories.has(
                                                                            t,
                                                                        ),
                                                                ).length;
                                                            const allEnabled =
                                                                enabledCount ===
                                                                types.length;
                                                            const someEnabled =
                                                                enabledCount >
                                                                    0 &&
                                                                !allEnabled;
                                                            const icon =
                                                                GROUP_ICONS[
                                                                    subGroup
                                                                        .icon
                                                                ];

                                                            return (
                                                                <div
                                                                    key={subKey}
                                                                    className="flex items-center gap-2 rounded px-1.5 py-1 hover:bg-accent"
                                                                >
                                                                    <Checkbox
                                                                        checked={
                                                                            allEnabled
                                                                                ? true
                                                                                : someEnabled
                                                                                  ? 'indeterminate'
                                                                                  : false
                                                                        }
                                                                        onCheckedChange={() =>
                                                                            onToggleGroup(
                                                                                [
                                                                                    ...types,
                                                                                ],
                                                                            )
                                                                        }
                                                                        className="h-3.5 w-3.5"
                                                                    />
                                                                    {icon && (
                                                                        <span className="text-muted-foreground">
                                                                            {
                                                                                icon
                                                                            }
                                                                        </span>
                                                                    )}
                                                                    <span className="text-xs">
                                                                        {
                                                                            subGroup.label
                                                                        }
                                                                    </span>
                                                                    {enabledCount >
                                                                        0 && (
                                                                        <span className="ml-auto text-[10px] text-muted-foreground">
                                                                            {
                                                                                enabledCount
                                                                            }
                                                                            /
                                                                            {
                                                                                types.length
                                                                            }
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                },
                            )}

                            <div className="flex items-center justify-between border-t border-border pt-2">
                                <span className="text-[10px] text-muted-foreground">
                                    {totalEnabled} categories
                                </span>
                                <div className="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 px-2 text-[10px]"
                                        onClick={onResetDefaults}
                                    >
                                        Reset
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 px-2 text-[10px]"
                                        onClick={onEnableAll}
                                    >
                                        All
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 px-2 text-[10px]"
                                        onClick={onDisableAll}
                                    >
                                        None
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </ScrollArea>
                </Card>
            )}
        </div>
    );
}
