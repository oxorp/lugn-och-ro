import { Head, router } from '@inertiajs/react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowsRotate, faChevronDown, faChevronRight, faCircleInfo, faLocationDot, faMagnifyingGlass, faPen, faXmark } from '@/icons';
import { Fragment, useMemo, useState } from 'react';

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
import AdminLayout from '@/layouts/admin-layout';

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
    is_free_preview: boolean;
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

interface PoiCategoryItem {
    id: number;
    slug: string;
    name: string;
    signal: 'positive' | 'negative' | 'neutral';
    icon: string;
    color: string;
    display_tier: number;
    category_group: string;
    indicator_slug: string | null;
    is_active: boolean;
    show_on_map: boolean;
    poi_count: number;
}

interface Props {
    indicators: Indicator[];
    urbanityDistribution: Record<string, number>;
    poiCategories: PoiCategoryItem[];
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

const CATEGORY_ORDER = [
    'income', 'employment', 'education', 'demographics', 'housing',
    'crime', 'safety', 'financial_distress', 'amenities', 'transport',
];

const CATEGORY_COLORS: Record<string, string> = {
    income: 'bg-emerald-100 text-emerald-800',
    employment: 'bg-blue-100 text-blue-800',
    education: 'bg-purple-100 text-purple-800',
    demographics: 'bg-amber-100 text-amber-800',
    housing: 'bg-rose-100 text-rose-800',
    crime: 'bg-red-100 text-red-800',
    safety: 'bg-teal-100 text-teal-800',
    financial_distress: 'bg-orange-100 text-orange-800',
    amenities: 'bg-cyan-100 text-cyan-800',
    transport: 'bg-indigo-100 text-indigo-800',
};

const CATEGORY_LABELS: Record<string, string> = {
    income: 'Income',
    employment: 'Employment',
    education: 'Education',
    demographics: 'Demographics',
    housing: 'Housing',
    crime: 'Crime',
    safety: 'Safety',
    financial_distress: 'Financial Distress',
    amenities: 'Amenities',
    transport: 'Transport',
};

const TIER_LABELS: Record<number, string> = {
    1: 'Zoom 8+ (Major)',
    2: 'Zoom 10+ (Significant)',
    3: 'Zoom 12+ (Local)',
    4: 'Zoom 14+ (Neighborhood)',
    5: 'Zoom 16+ (Street)',
};

function Tip({ label, tip }: { label: string; tip: string }) {
    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger className="flex items-center gap-1">
                    {label}
                    <FontAwesomeIcon icon={faCircleInfo} className="h-3 w-3 text-muted-foreground" />
                </TooltipTrigger>
                <TooltipContent>
                    <p className="max-w-xs text-xs">{tip}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

export default function IndicatorsPage({ indicators, poiCategories }: Props) {
    const [search, setSearch] = useState('');
    const [sourceFilter, setSourceFilter] = useState('all');
    const [collapsedGroups, setCollapsedGroups] = useState<Set<string>>(new Set());
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

    // POI category lookup by indicator slug
    const poiBySlug = useMemo(() => {
        const map = new Map<string, PoiCategoryItem>();
        poiCategories.forEach((p) => {
            if (p.indicator_slug) map.set(p.indicator_slug, p);
        });
        return map;
    }, [poiCategories]);

    // Totals
    const activeIndicators = indicators.filter((i) => i.is_active && i.direction !== 'neutral');
    const totalWeight = activeIndicators.reduce((sum, i) => sum + i.weight, 0);
    const freePreviewCount = indicators.filter((i) => i.is_free_preview).length;

    // Unique sources for filter
    const uniqueSources = useMemo(() => {
        return [...new Set(indicators.map((i) => i.source))].sort();
    }, [indicators]);

    // Filter
    const filtered = useMemo(() => {
        return indicators.filter((i) => {
            if (search) {
                const q = search.toLowerCase();
                if (!i.name.toLowerCase().includes(q) && !i.slug.toLowerCase().includes(q)) return false;
            }
            if (sourceFilter !== 'all' && i.source !== sourceFilter) return false;
            return true;
        });
    }, [indicators, search, sourceFilter]);

    // Group by category
    const grouped = useMemo(() => {
        const groups: Record<string, Indicator[]> = {};
        filtered.forEach((i) => {
            if (!groups[i.category]) groups[i.category] = [];
            groups[i.category].push(i);
        });
        return groups;
    }, [filtered]);

    // Sorted categories
    const sortedCategories = useMemo(() => {
        const ordered = CATEGORY_ORDER.filter((c) => grouped[c]);
        Object.keys(grouped).forEach((c) => {
            if (!ordered.includes(c)) ordered.push(c);
        });
        return ordered;
    }, [grouped]);

    function toggleGroup(category: string) {
        setCollapsedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(category)) next.delete(category);
            else next.add(category);
            return next;
        });
    }

    function handleUpdate(id: number, field: string, value: string | number | boolean) {
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
                is_free_preview: indicator.is_free_preview,
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
                is_free_preview: editingIndicator.is_free_preview,
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

    function handlePoiCategoryUpdate(id: number, field: string, value: boolean | number | string) {
        const cat = poiCategories.find((c) => c.id === id);
        if (!cat) return;

        router.put(
            `/admin/poi-categories/${id}`,
            {
                is_active: cat.is_active,
                show_on_map: cat.show_on_map,
                display_tier: cat.display_tier,
                signal: cat.signal,
                [field]: value,
            },
            { preserveScroll: true },
        );
    }

    const editingPoi = editingIndicator ? poiBySlug.get(editingIndicator.slug) : undefined;

    return (
        <AdminLayout>
            <Head title="Indicators" />

            {/* Header */}
            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">Indicators & Scoring</h1>
                    <p className="text-muted-foreground text-sm">
                        {indicators.length} indicators &middot; {activeIndicators.length} active &middot;
                        Total weight: {(totalWeight * 100).toFixed(0)}% ({totalWeight.toFixed(2)} / 1.00)
                        {' \u00b7 '}Free preview: {freePreviewCount}/8
                    </p>
                </div>
                <Button onClick={handleRecompute} disabled={recomputing}>
                    <FontAwesomeIcon icon={faArrowsRotate} spin={recomputing} className="mr-2 h-4 w-4" />
                    {recomputing ? 'Recomputing...' : 'Recompute Scores'}
                </Button>
            </div>

            {/* Weight allocation bar */}
            <Card className="mb-6">
                <CardContent className="pt-4 pb-4">
                    <div className="mb-2 flex items-center justify-between text-sm">
                        <span className="font-medium">Weight Allocation</span>
                        <span className="text-muted-foreground">
                            {sortedCategories
                                .map((cat) => {
                                    const items = grouped[cat] || [];
                                    const w = items
                                        .filter((i) => i.is_active && i.weight > 0)
                                        .reduce((s, i) => s + i.weight, 0);
                                    if (w === 0) return null;
                                    return `${CATEGORY_LABELS[cat] || cat}: ${(w * 100).toFixed(1)}%`;
                                })
                                .filter(Boolean)
                                .join(' \u00b7 ')}
                            {totalWeight < 1 &&
                                ` \u00b7 Unallocated: ${((1 - totalWeight) * 100).toFixed(1)}%`}
                        </span>
                    </div>
                    <div className="h-3 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-emerald-500 transition-all"
                            style={{ width: `${Math.min(totalWeight * 100, 100)}%` }}
                        />
                    </div>
                </CardContent>
            </Card>

            {/* Search & Filters */}
            <div className="mb-4 flex items-center gap-3">
                <div className="relative max-w-sm flex-1">
                    <FontAwesomeIcon icon={faMagnifyingGlass} className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search by name or slug..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-9"
                    />
                    {search && (
                        <button
                            onClick={() => setSearch('')}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        >
                            <FontAwesomeIcon icon={faXmark} className="h-4 w-4" />
                        </button>
                    )}
                </div>
                <Select value={sourceFilter} onValueChange={setSourceFilter}>
                    <SelectTrigger className="w-40">
                        <SelectValue placeholder="All sources" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All sources</SelectItem>
                        {uniqueSources.map((s) => (
                            <SelectItem key={s} value={s}>
                                {s}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div className="flex gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setCollapsedGroups(new Set())}
                    >
                        Expand All
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setCollapsedGroups(new Set(sortedCategories))}
                    >
                        Collapse All
                    </Button>
                </div>
                <span className="ml-auto text-sm text-muted-foreground">
                    {filtered.length} of {indicators.length} indicators
                </span>
            </div>

            {/* Unified Table */}
            <Card>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead><Tip label="Name" tip="Display name shown to users in the sidebar" /></TableHead>
                            <TableHead><Tip label="Slug" tip="Unique identifier used in code and API" /></TableHead>
                            <TableHead><Tip label="Source" tip="Data provider (SCB, Skolverket, BRÅ, OSM, etc.)" /></TableHead>
                            <TableHead><Tip label="Direction" tip="Whether higher raw values are good (positive) or bad (negative) for livability" /></TableHead>
                            <TableHead><Tip label="Weight" tip="Contribution to composite score (0–1). All active weights should sum to 1.0" /></TableHead>
                            <TableHead><Tip label="Normalization" tip="Method to convert raw values to a 0–1 scale" /></TableHead>
                            <TableHead><Tip label="Scope" tip="Whether percentile ranking is computed nationally or within urbanity tiers" /></TableHead>
                            <TableHead><Tip label="Active" tip="Include this indicator in score computation" /></TableHead>
                            <TableHead><Tip label="Free" tip="Show this indicator value in the free preview (max 2 per category)" /></TableHead>
                            <TableHead><Tip label="Year" tip="Most recent data year available" /></TableHead>
                            <TableHead><Tip label="Coverage" tip="Number of DeSOs with data out of 6,160 total" /></TableHead>
                            <TableHead></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sortedCategories.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={12} className="py-8 text-center text-muted-foreground">
                                    No indicators match your search
                                </TableCell>
                            </TableRow>
                        ) : (
                            sortedCategories.map((category) => {
                                const items = grouped[category];
                                const isCollapsed = collapsedGroups.has(category);
                                const catWeight = items
                                    .filter((i) => i.is_active && i.weight > 0)
                                    .reduce((s, i) => s + i.weight, 0);
                                const activeCount = items.filter((i) => i.is_active).length;

                                return (
                                    <Fragment key={category}>
                                        {/* Category header */}
                                        <TableRow
                                            className="cursor-pointer bg-muted/50 hover:bg-muted"
                                            onClick={() => toggleGroup(category)}
                                        >
                                            <TableCell colSpan={12} className="py-2">
                                                <div className="flex items-center gap-2">
                                                    {isCollapsed ? (
                                                        <FontAwesomeIcon icon={faChevronRight} className="h-4 w-4 text-muted-foreground" />
                                                    ) : (
                                                        <FontAwesomeIcon icon={faChevronDown} className="h-4 w-4 text-muted-foreground" />
                                                    )}
                                                    <Badge
                                                        className={CATEGORY_COLORS[category] || 'bg-gray-100 text-gray-800'}
                                                        variant="secondary"
                                                    >
                                                        {CATEGORY_LABELS[category] || category}
                                                    </Badge>
                                                    <span className="text-sm text-muted-foreground">
                                                        {items.length} indicator{items.length !== 1 ? 's' : ''}
                                                        {' \u00b7 '}{activeCount} active
                                                        {' \u00b7 '}weight: {(catWeight * 100).toFixed(1)}%
                                                    </span>
                                                </div>
                                            </TableCell>
                                        </TableRow>

                                        {/* Indicator rows */}
                                        {!isCollapsed &&
                                            items.map((indicator) => {
                                                const poi = poiBySlug.get(indicator.slug);
                                                return (
                                                    <TableRow
                                                        key={indicator.id}
                                                        className={!indicator.is_active ? 'opacity-50' : ''}
                                                    >
                                                        <TableCell className="font-medium">
                                                            <div className="flex items-center gap-1.5">
                                                                {indicator.name}
                                                                {poi && (
                                                                    <TooltipProvider>
                                                                        <Tooltip>
                                                                            <TooltipTrigger>
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="px-1 py-0 text-[10px]"
                                                                                >
                                                                                    {poi.poi_count.toLocaleString()} POIs
                                                                                </Badge>
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>
                                                                                <p className="text-xs">
                                                                                    {poi.name}
                                                                                    {poi.show_on_map
                                                                                        ? ' \u00b7 Visible on map'
                                                                                        : ' \u00b7 Hidden on map'}
                                                                                    {' \u00b7 '}
                                                                                    {TIER_LABELS[poi.display_tier]}
                                                                                </p>
                                                                            </TooltipContent>
                                                                        </Tooltip>
                                                                    </TooltipProvider>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            <code className="text-xs">{indicator.slug}</code>
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant="outline">{indicator.source}</Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            <Select
                                                                value={indicator.direction}
                                                                onValueChange={(v) =>
                                                                    handleUpdate(indicator.id, 'direction', v)
                                                                }
                                                            >
                                                                <SelectTrigger className="w-28">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="positive">
                                                                        <div>
                                                                            <div>positive</div>
                                                                            <div className="text-xs text-muted-foreground">Higher values improve score</div>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="negative">
                                                                        <div>
                                                                            <div>negative</div>
                                                                            <div className="text-xs text-muted-foreground">Higher values reduce score</div>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="neutral">
                                                                        <div>
                                                                            <div>neutral</div>
                                                                            <div className="text-xs text-muted-foreground">Informational only, not scored</div>
                                                                        </div>
                                                                    </SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                key={`w-${indicator.id}-${indicator.weight}`}
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
                                                                    handleUpdate(indicator.id, 'normalization', v)
                                                                }
                                                            >
                                                                <SelectTrigger className="w-36">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="rank_percentile">
                                                                        <div>
                                                                            <div>rank_percentile</div>
                                                                            <div className="text-xs text-muted-foreground">Percentile rank. Robust to outliers</div>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="min_max">
                                                                        <div>
                                                                            <div>min_max</div>
                                                                            <div className="text-xs text-muted-foreground">Linear min–max scale. Sensitive to outliers</div>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="z_score">
                                                                        <div>
                                                                            <div>z_score</div>
                                                                            <div className="text-xs text-muted-foreground">Std deviations from mean. For normal distributions</div>
                                                                        </div>
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
                                                                    <SelectTrigger className="w-32">
                                                                        <SelectValue />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="national">
                                                                            <div>
                                                                                <div>national</div>
                                                                                <div className="text-xs text-muted-foreground">Rank among all 6,160 DeSOs</div>
                                                                            </div>
                                                                        </SelectItem>
                                                                        <SelectItem value="urbanity_stratified">
                                                                            <div>
                                                                                <div>stratified</div>
                                                                                <div className="text-xs text-muted-foreground">Rank within urbanity tier. Use for access metrics</div>
                                                                            </div>
                                                                        </SelectItem>
                                                                    </SelectContent>
                                                                </Select>
                                                                {indicator.normalization_scope ===
                                                                    'urbanity_stratified' && (
                                                                    <TooltipProvider>
                                                                        <Tooltip>
                                                                            <TooltipTrigger>
                                                                                <FontAwesomeIcon icon={faCircleInfo} className="h-3.5 w-3.5 text-blue-500" />
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>
                                                                                <p className="max-w-xs text-xs">
                                                                                    Normalized within urbanity tier
                                                                                    (urban, semi-urban, rural) instead
                                                                                    of nationally
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
                                                                    handleUpdate(indicator.id, 'is_active', v)
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <Switch
                                                                checked={indicator.is_free_preview}
                                                                onCheckedChange={(v) =>
                                                                    handleUpdate(indicator.id, 'is_free_preview', v)
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {indicator.latest_year ?? '\u2014'}
                                                        </TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {indicator.coverage} / {indicator.total_desos}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(indicator)}
                                                            >
                                                                <FontAwesomeIcon icon={faPen} className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                    </Fragment>
                                );
                            })
                        )}
                    </TableBody>
                </Table>
            </Card>

            {/* POI Categories */}
            <Card className="mt-6">
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-sm font-medium">
                        <FontAwesomeIcon icon={faLocationDot} className="h-4 w-4" />
                        POI Categories
                        <span className="font-normal text-muted-foreground">
                            ({poiCategories.length} categories,{' '}
                            {poiCategories.reduce((s, c) => s + c.poi_count, 0).toLocaleString()} total POIs)
                        </span>
                    </CardTitle>
                </CardHeader>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Slug</TableHead>
                            <TableHead><Tip label="Signal" tip="Whether this POI type is positive or negative for livability" /></TableHead>
                            <TableHead><Tip label="Group" tip="Functional category for grouping related POI types" /></TableHead>
                            <TableHead><Tip label="Tier" tip="Minimum zoom level at which POIs appear on the map" /></TableHead>
                            <TableHead><Tip label="POIs" tip="Number of points of interest in this category" /></TableHead>
                            <TableHead><Tip label="Linked Indicator" tip="The scoring indicator this category feeds into" /></TableHead>
                            <TableHead><Tip label="Scoring" tip="Include in data ingestion pipeline and score computation" /></TableHead>
                            <TableHead><Tip label="Map" tip="Show these POIs on the interactive map" /></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {poiCategories.map((cat) => (
                            <TableRow
                                key={cat.id}
                                className={!cat.is_active && !cat.show_on_map ? 'opacity-50' : ''}
                            >
                                <TableCell className="font-medium">
                                    <div className="flex items-center gap-2">
                                        <span
                                            className="inline-block h-3 w-3 rounded-full"
                                            style={{ backgroundColor: cat.color }}
                                        />
                                        {cat.name}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <code className="text-xs">{cat.slug}</code>
                                </TableCell>
                                <TableCell>
                                    <Select
                                        value={cat.signal}
                                        onValueChange={(v) =>
                                            handlePoiCategoryUpdate(cat.id, 'signal', v)
                                        }
                                    >
                                        <SelectTrigger className="w-28">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="positive">
                                                <div>
                                                    <div>positive</div>
                                                    <div className="text-xs text-muted-foreground">Nearby presence improves score</div>
                                                </div>
                                            </SelectItem>
                                            <SelectItem value="negative">
                                                <div>
                                                    <div>negative</div>
                                                    <div className="text-xs text-muted-foreground">Nearby presence reduces score</div>
                                                </div>
                                            </SelectItem>
                                            <SelectItem value="neutral">
                                                <div>
                                                    <div>neutral</div>
                                                    <div className="text-xs text-muted-foreground">No effect on score</div>
                                                </div>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {cat.category_group}
                                </TableCell>
                                <TableCell>
                                    <Select
                                        value={String(cat.display_tier)}
                                        onValueChange={(v) =>
                                            handlePoiCategoryUpdate(cat.id, 'display_tier', parseInt(v))
                                        }
                                    >
                                        <SelectTrigger className="w-44">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[1, 2, 3, 4, 5].map((tier) => (
                                                <SelectItem key={tier} value={String(tier)}>
                                                    {TIER_LABELS[tier]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                                <TableCell className="tabular-nums text-sm text-muted-foreground">
                                    {cat.poi_count.toLocaleString()}
                                </TableCell>
                                <TableCell>
                                    {cat.indicator_slug ? (
                                        <code className="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700">
                                            {cat.indicator_slug}
                                        </code>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">{'\u2014'}</span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Switch
                                        checked={cat.is_active}
                                        onCheckedChange={(v) =>
                                            handlePoiCategoryUpdate(cat.id, 'is_active', v)
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <Switch
                                        checked={cat.show_on_map}
                                        onCheckedChange={(v) =>
                                            handlePoiCategoryUpdate(cat.id, 'show_on_map', v)
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {/* Edit Dialog */}
            <Dialog
                open={!!editingIndicator}
                onOpenChange={(open) => {
                    if (!open) setEditingIndicator(null);
                }}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit: {editingIndicator?.name}</DialogTitle>
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

                        {/* POI Category Settings */}
                        {editingPoi && (
                            <div className="space-y-3 rounded-lg border p-4">
                                <h4 className="text-sm font-medium">POI Category Settings</h4>
                                <p className="text-xs text-muted-foreground">
                                    {editingPoi.name} &middot; {editingPoi.poi_count.toLocaleString()} POIs &middot;{' '}
                                    {editingPoi.category_group}
                                </p>
                                <div className="flex items-center justify-between">
                                    <Label>Show on Map</Label>
                                    <Switch
                                        checked={editingPoi.show_on_map}
                                        onCheckedChange={(v) =>
                                            handlePoiCategoryUpdate(editingPoi.id, 'show_on_map', v)
                                        }
                                    />
                                </div>
                                <div className="flex items-center justify-between">
                                    <Label>Display Tier</Label>
                                    <Select
                                        value={String(editingPoi.display_tier)}
                                        onValueChange={(v) =>
                                            handlePoiCategoryUpdate(editingPoi.id, 'display_tier', parseInt(v))
                                        }
                                    >
                                        <SelectTrigger className="w-52">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[1, 2, 3, 4, 5].map((tier) => (
                                                <SelectItem key={tier} value={String(tier)}>
                                                    {TIER_LABELS[tier]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditingIndicator(null)}>
                            Cancel
                        </Button>
                        <Button onClick={handleSaveExplanations} disabled={saving}>
                            {saving ? 'Saving...' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
