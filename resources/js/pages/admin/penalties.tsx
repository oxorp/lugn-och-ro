import { Head, router } from '@inertiajs/react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowsRotate } from '@/icons';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AdminLayout from '@/layouts/admin-layout';

interface Penalty {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    category: string;
    penalty_type: 'absolute' | 'percentage';
    penalty_value: number;
    is_active: boolean;
    display_order: number;
    color: string | null;
    border_color: string | null;
    opacity: number;
    affected_desos: number;
    affected_population: number;
}

interface PenaltyPageProps {
    penalties: Penalty[];
}

function formatPopulation(pop: number): string {
    if (pop >= 1_000_000) return `${(pop / 1_000_000).toFixed(1)}M`;
    if (pop >= 1_000) return `${Math.round(pop / 1_000)} 000`;
    return String(pop);
}

function PenaltyCard({ penalty }: { penalty: Penalty }) {
    const [penaltyValue, setPenaltyValue] = useState(String(penalty.penalty_value));
    const [penaltyType, setPenaltyType] = useState(penalty.penalty_type);
    const [isActive, setIsActive] = useState(penalty.is_active);
    const [color, setColor] = useState(penalty.color ?? '#dc2626');
    const [borderColor, setBorderColor] = useState(penalty.border_color ?? '#991b1b');
    const [opacity, setOpacity] = useState(String(penalty.opacity));
    const [saving, setSaving] = useState(false);

    const numericValue = parseFloat(penaltyValue) || 0;

    const simulations = [50, 30, 10].map((score) => {
        const amount =
            penaltyType === 'absolute'
                ? numericValue
                : score * (numericValue / 100);
        return {
            before: score,
            after: Math.max(0, Math.round((score + amount) * 100) / 100),
        };
    });

    function handleSave() {
        setSaving(true);
        router.put(
            `/admin/penalties/${penalty.id}`,
            {
                penalty_value: parseFloat(penaltyValue),
                penalty_type: penaltyType,
                is_active: isActive,
                color,
                border_color: borderColor,
                opacity: parseFloat(opacity),
            },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    }

    const isDirty =
        parseFloat(penaltyValue) !== penalty.penalty_value ||
        penaltyType !== penalty.penalty_type ||
        isActive !== penalty.is_active ||
        color !== (penalty.color ?? '#dc2626') ||
        borderColor !== (penalty.border_color ?? '#991b1b') ||
        parseFloat(opacity) !== penalty.opacity;

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <span
                            className="inline-block h-3 w-3 rounded-full"
                            style={{ backgroundColor: color }}
                        />
                        <CardTitle className="text-base">{penalty.name}</CardTitle>
                    </div>
                    <div className="flex items-center gap-2">
                        <Label htmlFor={`active-${penalty.id}`} className="text-xs text-muted-foreground">
                            Aktiv
                        </Label>
                        <Switch
                            id={`active-${penalty.id}`}
                            checked={isActive}
                            onCheckedChange={setIsActive}
                        />
                    </div>
                </div>
                {penalty.description && (
                    <p className="text-xs text-muted-foreground">{penalty.description}</p>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Penalty value + type */}
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <Label htmlFor={`value-${penalty.id}`} className="text-xs">
                            Avdrag
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id={`value-${penalty.id}`}
                                type="number"
                                value={penaltyValue}
                                onChange={(e) => setPenaltyValue(e.target.value)}
                                step="0.5"
                                max="0"
                                min="-50"
                                className="w-24"
                            />
                            <span className="text-xs text-muted-foreground">
                                {penaltyType === 'absolute' ? 'poäng' : '%'}
                            </span>
                        </div>
                    </div>
                    <div>
                        <Label className="text-xs">Typ</Label>
                        <div className="mt-1 flex gap-3">
                            <label className="flex items-center gap-1.5 text-xs">
                                <input
                                    type="radio"
                                    name={`type-${penalty.id}`}
                                    value="absolute"
                                    checked={penaltyType === 'absolute'}
                                    onChange={() => setPenaltyType('absolute')}
                                    className="accent-primary"
                                />
                                Absolut
                            </label>
                            <label className="flex items-center gap-1.5 text-xs">
                                <input
                                    type="radio"
                                    name={`type-${penalty.id}`}
                                    value="percentage"
                                    checked={penaltyType === 'percentage'}
                                    onChange={() => setPenaltyType('percentage')}
                                    className="accent-primary"
                                />
                                Procent
                            </label>
                        </div>
                    </div>
                </div>

                {/* Impact stats */}
                <div className="rounded-md bg-muted/50 p-3">
                    <div className="text-xs font-medium text-muted-foreground">Påverkar</div>
                    <div className="mt-1 text-sm">
                        {penalty.affected_desos} DeSO-områden &middot; ~{formatPopulation(penalty.affected_population)} invånare
                    </div>
                </div>

                {/* Simulation */}
                <div>
                    <div className="mb-1 text-xs font-medium text-muted-foreground">Simulering</div>
                    <div className="space-y-1">
                        {simulations.map((sim) => (
                            <div key={sim.before} className="flex items-center gap-2 text-xs tabular-nums">
                                <span className="w-16 text-muted-foreground">Poäng {sim.before}</span>
                                <span className="text-muted-foreground">&rarr;</span>
                                <span className="font-medium">{sim.after}</span>
                                <span className="text-muted-foreground">
                                    ({sim.after === 0 ? 'golv' : `${Math.round(sim.after - sim.before)} p`})
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Map styling */}
                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <Label htmlFor={`color-${penalty.id}`} className="text-xs">Kartfärg</Label>
                        <div className="mt-1 flex items-center gap-1.5">
                            <input
                                type="color"
                                id={`color-${penalty.id}`}
                                value={color}
                                onChange={(e) => setColor(e.target.value)}
                                className="h-7 w-7 cursor-pointer rounded border border-border"
                            />
                            <span className="text-[10px] text-muted-foreground">{color}</span>
                        </div>
                    </div>
                    <div>
                        <Label htmlFor={`border-${penalty.id}`} className="text-xs">Kant</Label>
                        <div className="mt-1 flex items-center gap-1.5">
                            <input
                                type="color"
                                id={`border-${penalty.id}`}
                                value={borderColor}
                                onChange={(e) => setBorderColor(e.target.value)}
                                className="h-7 w-7 cursor-pointer rounded border border-border"
                            />
                            <span className="text-[10px] text-muted-foreground">{borderColor}</span>
                        </div>
                    </div>
                    <div>
                        <Label htmlFor={`opacity-${penalty.id}`} className="text-xs">Opacitet</Label>
                        <Input
                            id={`opacity-${penalty.id}`}
                            type="number"
                            value={opacity}
                            onChange={(e) => setOpacity(e.target.value)}
                            step="0.05"
                            min="0"
                            max="1"
                            className="mt-1"
                        />
                    </div>
                </div>

                {/* Save button */}
                {isDirty && (
                    <Button onClick={handleSave} disabled={saving} size="sm">
                        {saving ? 'Sparar...' : 'Spara ändringar'}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

export default function PenaltiesPage({ penalties }: PenaltyPageProps) {
    const [recomputing, setRecomputing] = useState(false);

    function handleRecompute() {
        setRecomputing(true);
        router.post('/admin/recompute-scores', {}, {
            onFinish: () => setRecomputing(false),
        });
    }

    return (
        <AdminLayout>
            <Head title="Penalties" />

            <div className="mb-6 flex items-start justify-between">
                <div>
                    <h1 className="text-xl font-bold">Poängavdrag & straffsystem</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Dessa avdrag appliceras EFTER den viktade poängberäkningen. De påverkar den slutliga kompositspoängen direkt.
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={handleRecompute}
                    disabled={recomputing}
                >
                    <FontAwesomeIcon icon={faArrowsRotate} className={`mr-1.5 h-3.5 w-3.5 ${recomputing ? 'animate-spin' : ''}`} />
                    {recomputing ? 'Beräknar...' : 'Beräkna om poäng'}
                </Button>
            </div>

            {/* Vulnerability section */}
            <div className="mb-4">
                <h2 className="mb-3 text-sm font-semibold text-muted-foreground uppercase tracking-wider">
                    Polisens utsatta områden
                </h2>
                <div className="grid gap-4 lg:grid-cols-2">
                    {penalties
                        .filter((p) => p.category === 'vulnerability')
                        .map((penalty) => (
                            <PenaltyCard key={penalty.id} penalty={penalty} />
                        ))}
                </div>
            </div>

            <p className="mt-6 text-xs text-muted-foreground">
                Källa: Polismyndigheten, &ldquo;Lägesbild utsatta områden 2025&rdquo; &middot; OBS: Ändring av avdrag kräver omberäkning av poäng.
            </p>
        </AdminLayout>
    );
}
