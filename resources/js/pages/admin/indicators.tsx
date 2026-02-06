import { Head, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';

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

interface Indicator {
    id: number;
    slug: string;
    name: string;
    source: string;
    category: string;
    direction: 'positive' | 'negative' | 'neutral';
    weight: number;
    normalization: string;
    is_active: boolean;
    latest_year: number | null;
    coverage: number;
    total_desos: number;
}

interface Props {
    indicators: Indicator[];
}

const categoryColors: Record<string, string> = {
    income: 'bg-emerald-100 text-emerald-800',
    employment: 'bg-blue-100 text-blue-800',
    education: 'bg-purple-100 text-purple-800',
    demographics: 'bg-amber-100 text-amber-800',
    housing: 'bg-rose-100 text-rose-800',
};

export default function IndicatorsPage({ indicators }: Props) {
    const [recomputing, setRecomputing] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

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
            <Head title="Admin - Indicators" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">Indicator Management</h1>
                    <p className="text-muted-foreground text-sm">
                        Configure indicator weights, directions, and normalization
                        methods.
                    </p>
                </div>
                <Button onClick={handleRecompute} disabled={recomputing}>
                    <RefreshCw
                        className={`mr-2 h-4 w-4 ${recomputing ? 'animate-spin' : ''}`}
                    />
                    {recomputing ? 'Recomputing...' : 'Recompute All Scores'}
                </Button>
            </div>

            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">
                        Weight Allocation
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
                            {(totalWeight * 100).toFixed(0)}% allocated (
                            {totalWeight.toFixed(2)} / 1.00)
                        </span>
                        <span className="text-muted-foreground">
                            {Object.entries(weightByCategory)
                                .map(
                                    ([cat, w]) =>
                                        `${cat}: ${(w as number).toFixed(2)}`,
                                )
                                .join('  ·  ')}
                            {totalWeight < 1 &&
                                `  ·  unallocated: ${(1 - totalWeight).toFixed(2)}`}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Slug</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>Direction</TableHead>
                            <TableHead>Weight</TableHead>
                            <TableHead>Normalization</TableHead>
                            <TableHead>Active</TableHead>
                            <TableHead>Year</TableHead>
                            <TableHead>Coverage</TableHead>
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
                                    {indicator.latest_year ?? '—'}
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
