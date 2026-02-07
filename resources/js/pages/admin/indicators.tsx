import { Head, router } from '@inertiajs/react';
import { Info, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import LocaleSync from '@/components/locale-sync';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';

interface Indicator {
    id: number;
    slug: string;
    name: string;
    source: string;
    category: string;
    direction: 'positive' | 'negative' | 'neutral';
    weight: number;
    normalization: string;
    normalization_scope: 'national' | 'urbanity_stratified';
    is_active: boolean;
    latest_year: number | null;
    coverage: number;
    total_desos: number;
}

interface Props {
    indicators: Indicator[];
    urbanityDistribution: Record<string, number>;
}

const categoryColors: Record<string, string> = {
    income: 'bg-emerald-100 text-emerald-800',
    employment: 'bg-blue-100 text-blue-800',
    education: 'bg-purple-100 text-purple-800',
    demographics: 'bg-amber-100 text-amber-800',
    housing: 'bg-rose-100 text-rose-800',
};

export default function IndicatorsPage({ indicators, urbanityDistribution }: Props) {
    const { t } = useTranslation();
    const [recomputing, setRecomputing] = useState(false);

    const totalWeight = indicators
        .filter((i) => i.is_active && i.direction !== 'neutral')
        .reduce((sum, i) => sum + i.weight, 0);

    const weightByCategory = indicators
        .filter((i) => i.is_active && i.weight > 0)
        .reduce(
            (acc, i) => {
                acc[i.category] = (acc[i.category] || 0) + i.weight;
                return acc;
            },
            {} as Record<string, number>,
        );

    function handleUpdate(
        id: number,
        field: string,
        value: string | number | boolean,
    ) {
        const indicator = indicators.find((i) => i.id === id);
        if (!indicator) return;

        router.put(
            `/admin/indicators/${id}`,
            {
                direction: indicator.direction,
                weight: indicator.weight,
                normalization: indicator.normalization,
                normalization_scope: indicator.normalization_scope,
                is_active: indicator.is_active,
                [field]: value,
            },
            { preserveScroll: true },
        );
    }

    function handleRecompute() {
        setRecomputing(true);
        router.post('/admin/recompute-scores', {}, {
            preserveScroll: true,
            onFinish: () => setRecomputing(false),
        });
    }

    return (
        <div className="mx-auto max-w-7xl p-6">
            <LocaleSync />
            <Head title={t('admin.indicators.head_title')} />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">{t('admin.indicators.title')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t('admin.indicators.subtitle')}
                    </p>
                </div>
                <Button onClick={handleRecompute} disabled={recomputing}>
                    <RefreshCw
                        className={`mr-2 h-4 w-4 ${recomputing ? 'animate-spin' : ''}`}
                    />
                    {recomputing ? t('admin.indicators.recomputing') : t('admin.indicators.recompute')}
                </Button>
            </div>

            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">
                        {t('admin.indicators.weight_allocation')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="mb-2 h-4 w-full overflow-hidden rounded-full bg-gray-100">
                        <div
                            className="h-full rounded-full bg-emerald-500 transition-all"
                            style={{ width: `${Math.min(totalWeight * 100, 100)}%` }}
                        />
                    </div>
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {t('admin.indicators.weight_status', {
                                percent: (totalWeight * 100).toFixed(0),
                                used: totalWeight.toFixed(2),
                                total: '1.00',
                            })}
                        </span>
                        <span className="text-muted-foreground">
                            {Object.entries(weightByCategory)
                                .map(
                                    ([cat, w]) =>
                                        `${cat}: ${(w as number).toFixed(2)}`,
                                )
                                .join('  \u00b7  ')}
                            {totalWeight < 1 &&
                                `  \u00b7  ${t('admin.indicators.unallocated', { value: (1 - totalWeight).toFixed(2) })}`}
                        </span>
                    </div>
                </CardContent>
            </Card>

            {Object.keys(urbanityDistribution).length > 0 && (
                <Card className="mb-6">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">
                            {t('admin.indicators.urbanity_title')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-4 gap-4 text-sm">
                            {(['urban', 'semi_urban', 'rural', 'unclassified'] as const).map((tier) => {
                                const count = urbanityDistribution[tier] ?? 0;
                                const total = Object.values(urbanityDistribution).reduce((s, v) => s + v, 0);
                                const pct = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
                                if (tier === 'unclassified' && count === 0) return null;
                                return (
                                    <div key={tier} className="rounded-lg border p-3 text-center">
                                        <div className="text-muted-foreground text-xs">
                                            {t(`admin.indicators.urbanity_labels.${tier}`)}
                                        </div>
                                        <div className="text-lg font-semibold">{count.toLocaleString()}</div>
                                        <div className="text-muted-foreground text-xs">{pct}%</div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            )}

            <Card>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('admin.indicators.table.name')}</TableHead>
                            <TableHead>{t('admin.indicators.table.slug')}</TableHead>
                            <TableHead>{t('admin.indicators.table.source')}</TableHead>
                            <TableHead>{t('admin.indicators.table.category')}</TableHead>
                            <TableHead>{t('admin.indicators.table.direction')}</TableHead>
                            <TableHead>{t('admin.indicators.table.weight')}</TableHead>
                            <TableHead>{t('admin.indicators.table.normalization')}</TableHead>
                            <TableHead>{t('admin.indicators.table.scope')}</TableHead>
                            <TableHead>{t('admin.indicators.table.active')}</TableHead>
                            <TableHead>{t('admin.indicators.table.year')}</TableHead>
                            <TableHead>{t('admin.indicators.table.coverage')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {indicators.map((indicator) => (
                            <TableRow key={indicator.id}>
                                <TableCell className="font-medium">
                                    {indicator.name}
                                </TableCell>
                                <TableCell>
                                    <code className="text-xs">
                                        {indicator.slug}
                                    </code>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">
                                        {indicator.source}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        className={
                                            categoryColors[indicator.category] ||
                                            ''
                                        }
                                        variant="secondary"
                                    >
                                        {indicator.category}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Select
                                        value={indicator.direction}
                                        onValueChange={(v) =>
                                            handleUpdate(
                                                indicator.id,
                                                'direction',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="w-28">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="positive">
                                                positive
                                            </SelectItem>
                                            <SelectItem value="negative">
                                                negative
                                            </SelectItem>
                                            <SelectItem value="neutral">
                                                neutral
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                                <TableCell>
                                    <Input
                                        type="number"
                                        className="w-20"
                                        min={0}
                                        max={1}
                                        step={0.01}
                                        defaultValue={indicator.weight}
                                        onBlur={(e) =>
                                            handleUpdate(
                                                indicator.id,
                                                'weight',
                                                parseFloat(e.target.value),
                                            )
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <Select
                                        value={indicator.normalization}
                                        onValueChange={(v) =>
                                            handleUpdate(
                                                indicator.id,
                                                'normalization',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="w-36">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="rank_percentile">
                                                rank_percentile
                                            </SelectItem>
                                            <SelectItem value="min_max">
                                                min_max
                                            </SelectItem>
                                            <SelectItem value="z_score">
                                                z_score
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-1">
                                        <Select
                                            value={indicator.normalization_scope}
                                            onValueChange={(v) =>
                                                handleUpdate(
                                                    indicator.id,
                                                    'normalization_scope',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="w-36">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="national">
                                                    national
                                                </SelectItem>
                                                <SelectItem value="urbanity_stratified">
                                                    stratified
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {indicator.normalization_scope === 'urbanity_stratified' && (
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger>
                                                        <Info className="h-3.5 w-3.5 text-blue-500" />
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        <p className="max-w-xs text-xs">
                                                            {t('admin.indicators.urbanity_tooltip')}
                                                        </p>
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Switch
                                        checked={indicator.is_active}
                                        onCheckedChange={(v) =>
                                            handleUpdate(
                                                indicator.id,
                                                'is_active',
                                                v,
                                            )
                                        }
                                    />
                                </TableCell>
                                <TableCell className="text-muted-foreground text-sm">
                                    {indicator.latest_year ?? '\u2014'}
                                </TableCell>
                                <TableCell className="text-muted-foreground text-sm">
                                    {indicator.coverage} / {indicator.total_desos}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </div>
    );
}
