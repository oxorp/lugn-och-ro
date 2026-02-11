const SOURCES = [
    { slug: 'scb', name: 'SCB', logo: '/images/sources/scb.svg' },
    { slug: 'skolverket', name: 'Skolverket', logo: '/images/sources/skolverket.svg' },
    { slug: 'bra', name: 'BRÅ', logo: '/images/sources/bra.svg' },
    { slug: 'polisen', name: 'Polisen', logo: '/images/sources/polisen.svg' },
    { slug: 'kolada', name: 'Kolada', logo: '/images/sources/kolada.svg' },
    { slug: 'kronofogden', name: 'Kronofogden', logo: '/images/sources/kronofogden.svg' },
    { slug: 'osm', name: 'OpenStreetMap', logo: '/images/sources/osm.svg' },
    { slug: 'trafiklab', name: 'Trafiklab', logo: '/images/sources/trafiklab.svg' },
];

export function SourceMarquee() {
    const items = [...SOURCES, ...SOURCES];

    return (
        <div className="overflow-hidden border-t border-border bg-muted/30 py-3">
            <div className="flex items-center gap-2 px-4">
                <span className="shrink-0 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/70">
                    Data från
                </span>
                <div className="relative h-8 flex-1 overflow-hidden">
                    {/* Fade edges */}
                    <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-8 bg-gradient-to-r from-muted/30 to-transparent" />
                    <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-8 bg-gradient-to-l from-muted/30 to-transparent" />

                    {/* Absolutely positioned strip — never affects parent width */}
                    <div className="marquee-strip absolute left-0 top-0 flex items-center gap-8 hover:[animation-play-state:paused]">
                        {items.map((source, i) => (
                            <img
                                key={`${source.slug}-${i}`}
                                src={source.logo}
                                alt={source.name}
                                title={source.name}
                                className="h-8 w-auto shrink-0 object-contain opacity-50 transition-all hover:opacity-100"
                                loading="lazy"
                            />
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
