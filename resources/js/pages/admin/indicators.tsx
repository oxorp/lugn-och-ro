import { Head, router } from '@inertiajs/react';
import { Info, Pencil, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import LocaleSync from '@/components/locale-sync';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    description_short: string | null;
    description_long: string | null;
    methodology_note: string | null;
    national_context: string | null;
    source_name: string | null;
    source_url: string | null;
    update_frequency: string | null;
}

interface Props {
    indicators: Indicator[];
    urbanityDistribution: Record<string, number>;
}

interface ExplanationForm {
    description_short: string;
    description_long: string;
    methodology_note: string;
    national_context: string;
    source_name: string;
    source_url: string;
    update_frequency: string;
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
    const [editingIndicator, setEditingIndicator] = useState<Indicator | null>(null);
    const [form, setForm] = useState<ExplanationForm>({
        description_short: '',
        description_long: '',
        methodology_note: '',
        national_context: '',
        source_name: '',
        source_url: '',
        update_frequency: '',
    });
    const [saving, setSaving] = useState(false);

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

    function openEditDialog(indicator: Indicator) {
        setEditingIndicator(indicator);
        setForm({
            description_short: indicator.description_short ?? '',
            description_long: indicator.description_long ?? '',
            methodology_note: indicator.methodology_note ?? '',
            national_context: indicator.national_context ?? '',
            source_name: indicator.source_name ?? '',
            source_url: indicator.source_url ?? '',
            update_frequency: indicator.update_frequency ?? '',
        });
    }

    function handleSaveExplanations() {
        if (!editingIndicator) return;
        setSaving(true);

        router.put(
            `/admin/indicators/${editingIndicator.id}`,
            {
                direction: editingIndicator.direction,
                weight: editingIndicator.weight,
                normalization: editingIndicator.normalization,
                normalization_scope: editingIndicator.normalization_scope,
                is_active: editingIndicator.is_active,
                ...form,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(false);
                    setEditingIndicator(null);
                },
            },
        );
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
                            <TableHead></TableHead>
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
                                <TableCell>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => openEditDialog(indicator)}
                                    >
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            <Dialog open={!!editingIndicator} onOpenChange={(open) => { if (!open) setEditingIndicator(null); }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            Edit Explanations: {editingIndicator?.name}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="description_short">Short description (max 100 chars)</Label>
                            <Input
                                id="description_short"
                                maxLength={100}
                                value={form.description_short}
                                onChange={(e) => setForm({ ...form, description_short: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="description_long">Long description (max 500 chars)</Label>
                            <textarea
                                id="description_long"
                                maxLength={500}
                                rows={3}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                value={form.description_long}
                                onChange={(e) => setForm({ ...form, description_long: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="methodology_note">Methodology note (max 300 chars)</Label>
                            <textarea
                                id="methodology_note"
                                maxLength={300}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                value={form.methodology_note}
                                onChange={(e) => setForm({ ...form, methodology_note: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="national_context">National context (max 100 chars)</Label>
                            <Input
                                id="national_context"
                                maxLength={100}
                                value={form.national_context}
                                onChange={(e) => setForm({ ...form, national_context: e.target.value })}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label htmlFor="source_name">Source name</Label>
                                <Input
                                    id="source_name"
                                    value={form.source_name}
                                    onChange={(e) => setForm({ ...form, source_name: e.target.value })}
                                />
                            </div>
                            <div>
                                <Label htmlFor="source_url">Source URL</Label>
                                <Input
                                    id="source_url"
                                    type="url"
                                    value={form.source_url}
                                    onChange={(e) => setForm({ ...form, source_url: e.target.value })}
                                />
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="update_frequency">Update frequency</Label>
                            <Input
                                id="update_frequency"
                                value={form.update_frequency}
                                onChange={(e) => setForm({ ...form, update_frequency: e.target.value })}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditingIndicator(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button onClick={handleSaveExplanations} disabled={saving}>
                            {saving ? t('common.loading') : t('common.save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
