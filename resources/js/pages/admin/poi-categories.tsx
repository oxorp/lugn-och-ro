import { Head, router } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useMemo, useState } from 'react';

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
import AdminLayout from '@/layouts/admin-layout';

interface PoiCategory {
    id: number;
    slug: string;
    name: string;
    signal: 'positive' | 'negative' | 'neutral';
    safety_sensitivity: number;
    catchment_km: number;
    icon: string | null;
    color: string | null;
    is_active: boolean;
    poi_count: number;
}

interface ExampleRow {
    slug: string;
    name: string;
    physical_m: number;
    effective_m: number;
    decay: number;
}

interface Props {
    categories: PoiCategory[];
    exampleSafe: ExampleRow[];
    exampleUnsafe: ExampleRow[];
}

const signalColors: Record<string, string> = {
    positive: 'bg-green-100 text-green-800',
    negative: 'bg-red-100 text-red-800',
    neutral: 'bg-gray-100 text-gray-800',
};

type PendingEdits = Record<
    number,
    Partial<{ safety_sensitivity: number; signal: string; is_active: boolean }>
>;

export default function PoiCategories({ categories, exampleSafe, exampleUnsafe }: Props) {
    const [pendingEdits, setPendingEdits] = useState<PendingEdits>({});
    const [saving, setSaving] = useState<number | null>(null);

    const hasPending = Object.keys(pendingEdits).length > 0;

    function setEdit(id: number, field: string, value: number | string | boolean) {
        setPendingEdits((prev) => ({
            ...prev,
            [id]: { ...prev[id], [field]: value },
        }));
    }

    function saveCategory(cat: PoiCategory) {
        const edits = pendingEdits[cat.id];
        if (!edits) return;

        setSaving(cat.id);
        router.put(
            `/admin/poi-categories/${cat.id}/safety`,
            {
                safety_sensitivity: edits.safety_sensitivity ?? cat.safety_sensitivity,
                signal: edits.signal ?? cat.signal,
                is_active: edits.is_active ?? cat.is_active,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(null);
                    setPendingEdits((prev) => {
                        const next = { ...prev };
                        delete next[cat.id];
                        return next;
                    });
                },
            },
        );
    }

    // Compute local preview based on pending edits
    const localPreview = useMemo(() => {
        const safetyScore = { safe: 0.9, unsafe: 0.15 };

        return categories
            .filter((c) => (pendingEdits[c.id]?.signal ?? c.signal) === 'positive')
            .map((cat) => {
                const sensitivity = pendingEdits[cat.id]?.safety_sensitivity ?? cat.safety_sensitivity;
                const maxM = cat.catchment_km * 1000;
                const physical = 500;

                const safePenalty = (1.0 - safetyScore.safe) * sensitivity;
                const unsafePenalty = (1.0 - safetyScore.unsafe) * sensitivity;

                const safeEffective = physical * (1.0 + safePenalty);
                const unsafeEffective = physical * (1.0 + unsafePenalty);

                return {
                    name: cat.name,
                    slug: cat.slug,
                    sensitivity,
                    safe: {
                        effective_m: Math.round(safeEffective),
                        decay: Math.max(0, 1 - safeEffective / maxM).toFixed(2),
                    },
                    unsafe: {
                        effective_m: Math.round(unsafeEffective),
                        decay: Math.max(0, 1 - unsafeEffective / maxM).toFixed(2),
                    },
                };
            });
    }, [categories, pendingEdits]);

    return (
        <AdminLayout>
            <Head title="POI Safety Settings" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">POI Category Safety Settings</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Safety sensitivity controls how much the area&apos;s safety score affects the proximity
                        value of each amenity type. Higher values mean the amenity loses more value in unsafe areas.
                    </p>
                </div>

                {/* Sensitivity guide */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">Sensitivity Guide</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div>
                                <span className="font-medium text-green-700">0.0</span>
                                <p className="text-muted-foreground">No effect</p>
                            </div>
                            <div>
                                <span className="font-medium text-yellow-700">0.3&ndash;0.5</span>
                                <p className="text-muted-foreground">Necessities</p>
                            </div>
                            <div>
                                <span className="font-medium text-orange-700">0.8&ndash;1.0</span>
                                <p className="text-muted-foreground">Standard</p>
                            </div>
                            <div>
                                <span className="font-medium text-red-700">1.2&ndash;1.5</span>
                                <p className="text-muted-foreground">Discretionary / Night</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Categories table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Categories</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <TooltipProvider>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-[200px]">Category</TableHead>
                                        <TableHead className="w-[100px]">Signal</TableHead>
                                        <TableHead className="w-[140px]">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="cursor-help underline decoration-dotted">
                                                        Safety Sensitivity
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent className="max-w-xs">
                                                    <p>
                                                        How much the area safety score affects this category.
                                                        0.0 = no effect, 1.5 = maximum penalty in unsafe areas.
                                                    </p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TableHead>
                                        <TableHead className="w-[100px]">Catchment</TableHead>
                                        <TableHead className="w-[80px]">POIs</TableHead>
                                        <TableHead className="w-[80px]">Active</TableHead>
                                        <TableHead className="w-[80px]"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {categories.map((cat) => {
                                        const edits = pendingEdits[cat.id];
                                        const isDirty = !!edits;
                                        const currentSensitivity =
                                            edits?.safety_sensitivity ?? cat.safety_sensitivity;
                                        const currentSignal = edits?.signal ?? cat.signal;
                                        const currentActive = edits?.is_active ?? cat.is_active;

                                        return (
                                            <TableRow
                                                key={cat.id}
                                                className={isDirty ? 'bg-amber-50' : undefined}
                                            >
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        {cat.icon && (
                                                            <span
                                                                className="inline-flex h-6 w-6 items-center justify-center rounded text-xs"
                                                                style={{
                                                                    backgroundColor: cat.color
                                                                        ? `${cat.color}20`
                                                                        : undefined,
                                                                    color: cat.color ?? undefined,
                                                                }}
                                                            >
                                                                {cat.icon.length <= 2
                                                                    ? cat.icon
                                                                    : cat.icon.charAt(0).toUpperCase()}
                                                            </span>
                                                        )}
                                                        <div>
                                                            <span className="font-medium">{cat.name}</span>
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                {cat.slug}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={currentSignal}
                                                        onValueChange={(v) => setEdit(cat.id, 'signal', v)}
                                                    >
                                                        <SelectTrigger className="h-8 w-[100px]">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="positive">positive</SelectItem>
                                                            <SelectItem value="negative">negative</SelectItem>
                                                            <SelectItem value="neutral">neutral</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        type="number"
                                                        step="0.05"
                                                        min="0"
                                                        max="3"
                                                        value={currentSensitivity}
                                                        onChange={(e) =>
                                                            setEdit(
                                                                cat.id,
                                                                'safety_sensitivity',
                                                                parseFloat(e.target.value) || 0,
                                                            )
                                                        }
                                                        className="h-8 w-[90px]"
                                                    />
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {cat.catchment_km} km
                                                </TableCell>
                                                <TableCell className="text-sm tabular-nums">
                                                    {cat.poi_count.toLocaleString()}
                                                </TableCell>
                                                <TableCell>
                                                    <Switch
                                                        checked={currentActive}
                                                        onCheckedChange={(v) =>
                                                            setEdit(cat.id, 'is_active', v)
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {isDirty && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => saveCategory(cat)}
                                                            disabled={saving === cat.id}
                                                        >
                                                            <Save className="mr-1 h-3 w-3" />
                                                            {saving === cat.id ? '...' : 'Save'}
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </TooltipProvider>
                    </CardContent>
                </Card>

                {/* Live preview */}
                <Card>
                    <CardHeader>
                        <CardTitle>Live Preview: 500m to each amenity</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[200px]">Category</TableHead>
                                    <TableHead className="w-[80px]">Sensitivity</TableHead>
                                    <TableHead colSpan={2} className="text-center">
                                        <div className="flex items-center justify-center gap-1">
                                            <Badge variant="outline" className="bg-green-50">
                                                Safe area (0.90)
                                            </Badge>
                                        </div>
                                    </TableHead>
                                    <TableHead colSpan={2} className="text-center">
                                        <div className="flex items-center justify-center gap-1">
                                            <Badge variant="outline" className="bg-red-50">
                                                Unsafe area (0.15)
                                            </Badge>
                                        </div>
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead></TableHead>
                                    <TableHead></TableHead>
                                    <TableHead className="text-right text-xs">Eff. dist</TableHead>
                                    <TableHead className="text-right text-xs">Decay</TableHead>
                                    <TableHead className="text-right text-xs">Eff. dist</TableHead>
                                    <TableHead className="text-right text-xs">Decay</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {localPreview.map((row) => (
                                    <TableRow key={row.slug}>
                                        <TableCell className="font-medium">{row.name}</TableCell>
                                        <TableCell className="tabular-nums">
                                            {row.sensitivity.toFixed(2)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.safe.effective_m}m
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums text-green-700">
                                            {row.safe.decay}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.unsafe.effective_m}m
                                        </TableCell>
                                        <TableCell
                                            className={`text-right tabular-nums ${
                                                parseFloat(row.unsafe.decay) < 0.2
                                                    ? 'text-red-700'
                                                    : 'text-orange-600'
                                            }`}
                                        >
                                            {row.unsafe.decay}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
