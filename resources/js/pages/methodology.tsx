import { Head, Link } from '@inertiajs/react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

import { faArrowRight, faArrowUpRightFromSquare, faCalendarClock, faChevronDown } from '@/icons';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import MapLayout from '@/layouts/map-layout';
import { map } from '@/routes';

const DATA_SOURCES = [
    {
        title: 'Income & Economic Standing',
        measure:
            'Household income levels and the prevalence of economic hardship across an area.',
        why: 'Income is the single strongest predictor of property values. Areas with rising incomes tend to see rising demand, improving amenities, and increasing real estate prices. Areas with concentrated economic hardship often face the opposite trajectory.',
        source: 'Statistics Sweden (SCB)',
        sourceUrl: 'https://www.scb.se',
        frequency: 'Updated annually',
    },
    {
        title: 'Employment',
        measure:
            'The share of working-age residents who are employed.',
        why: "High employment means stable household finances, which supports mortgage payments, local business activity, and community investment. Declining employment is often a leading indicator of neighborhood decline \u2014 it shows up in the data before it shows up in property prices.",
        source: 'Statistics Sweden (SCB)',
        sourceUrl: 'https://www.scb.se',
        frequency: 'Updated annually',
    },
    {
        title: 'Education \u2014 Demographics',
        measure:
            'The educational attainment of residents \u2014 specifically, what share of the adult population has completed post-secondary education.',
        why: "Education levels shape an area\u2019s long-term economic trajectory. Neighborhoods with highly educated populations tend to attract employers, sustain higher incomes, and maintain demand for housing. This is a slow-moving but powerful signal.",
        source: 'Statistics Sweden (SCB)',
        sourceUrl: 'https://www.scb.se',
        frequency: 'Updated annually',
    },
    {
        title: 'School Quality',
        measure:
            'The academic performance of primary schools (grundskolor) physically located within each area, based on standardized national metrics including final grades and teacher qualifications.',
        why: "In Sweden, school quality is arguably the single biggest driver of where families choose to live. Parents routinely pay significant premiums to live near high-performing schools. A neighborhood\u2019s school quality has a direct, measurable effect on property values \u2014 and it\u2019s one of the factors that changes fastest when an area improves or declines.",
        source: 'Swedish National Agency for Education (Skolverket)',
        sourceUrl: 'https://www.skolverket.se',
        frequency: 'Updated annually',
    },
    {
        title: 'Safety',
        measure:
            'Reported crime rates, how residents perceive their own safety, and whether an area has been classified as vulnerable by Swedish Police.',
        why: "Safety is fundamental. High or rising crime depresses property values, discourages investment, and drives out residents who have the means to move. Perceived safety \u2014 how safe people feel, not just official statistics \u2014 matters just as much, because it drives behavior. We combine official crime data with Sweden\u2019s National Crime Survey, one of Europe\u2019s largest victimization studies.",
        sources: [
            {
                name: 'Swedish National Council for Crime Prevention (BR\u00C5)',
                url: 'https://bra.se',
            },
            {
                name: 'Swedish Police Authority (Polisen)',
                url: 'https://polisen.se',
            },
        ],
        frequency: 'Crime statistics updated quarterly; survey data updated annually',
    },
    {
        title: 'Financial Distress',
        measure:
            "The prevalence of debt enforcement, payment defaults, and evictions \u2014 the share of an area\u2019s residents who have unpaid debts serious enough to reach Sweden\u2019s Enforcement Authority.",
        why: 'Financial distress is both a symptom and a cause. Areas with high rates of debt enforcement tend to see more forced property sales, deferred maintenance, and population turnover. Rising financial distress in an area is one of the clearest warning signs of a downward trajectory. Falling distress, conversely, suggests an area is stabilizing.',
        source: 'Swedish Enforcement Authority (Kronofogden)',
        sourceUrl: 'https://www.kronofogden.se',
        frequency: 'Updated annually',
    },
];

const SCORE_RANGES = [
    {
        range: '80\u2013100',
        label: 'Strong Growth Area',
        color: '#1a7a2e',
        description:
            'Consistently strong across most factors. These areas typically have high demand, rising property values, and positive momentum.',
    },
    {
        range: '60\u201379',
        label: 'Stable / Positive Outlook',
        color: '#27ae60',
        description:
            'Solid fundamentals with some areas of strength. Generally desirable and trending in a positive direction.',
    },
    {
        range: '40\u201359',
        label: 'Mixed Signals',
        color: '#f1c40f',
        description:
            'Some strengths, some concerns. These areas may be transitioning \u2014 either improving or facing early signs of decline. Worth investigating closely.',
    },
    {
        range: '20\u201339',
        label: 'Elevated Risk',
        color: '#e74c3c',
        description:
            'Multiple concerning signals across several factors. These areas may face declining demand or structural challenges.',
    },
    {
        range: '0\u201319',
        label: 'High Risk / Declining',
        color: '#c0392b',
        description:
            'Significant challenges across most measured factors. High uncertainty about the area\u2019s near-term trajectory.',
    },
];

const FAQ_ITEMS = [
    {
        question: 'How often does the score change?',
        answer: "The score updates whenever we receive new data from our government sources. In practice, this means most scores are recalculated at least once per year, with some components (like crime statistics) updating more frequently. Major shifts in a score usually reflect real changes on the ground \u2014 a new school opening, a significant change in employment, or a shift in crime trends.",
    },
    {
        question: 'Why does my area score low even though it feels nice?',
        answer: "The score reflects measurable data, not subjective impressions. An area might feel pleasant to live in but score lower because of, say, below-average school performance or higher-than-average financial distress rates. Conversely, an area might score high on data but have qualities you personally dislike that we don\u2019t measure \u2014 like lack of green space or long commute times. The score is one input to your decision, not the whole picture.",
    },
    {
        question: 'Do you factor in ethnicity or immigration status?',
        answer: 'No. We do not use ethnicity, country of origin, immigration status, religious affiliation, or any demographic characteristic as a factor in the score. Our model measures economic outcomes (income, employment, education, financial stability), institutional quality (schools), and safety. These are the factors that research shows drive property values. Using demographic characteristics would be both legally problematic and methodologically unnecessary \u2014 economic indicators already capture the relevant signals.',
    },
    {
        question: 'How granular is the data?',
        answer: "Sweden is divided into approximately 6,160 DeSO areas (Demografiska statistikomr\u00E5den), each containing roughly 700\u20132,700 residents. These are the finest-grained statistical areas for which the Swedish government publishes data. Each DeSO gets its own score. This is far more granular than municipal or postal code-level analysis \u2014 within a single municipality like Stockholm, scores can range from below 20 to above 90.",
    },
    {
        question: "Can I see exactly which factors affect my area\u2019s score?",
        answer: "Yes. Click any area on the map to see its full factor breakdown. You\u2019ll see each measured dimension with its individual performance (as a percentile) and the actual underlying value (for example, median income in SEK, or the local school\u2019s merit value). This transparency lets you understand not just the score, but why the score is what it is.",
    },
    {
        question: "Why don\u2019t you publish the exact weights?",
        answer: "The weighting model is the core of our intellectual property. We\u2019re transparent about what we measure and where the data comes from, because we believe that\u2019s necessary for trust. But the specific way factors are combined \u2014 which we\u2019ve developed through extensive research into what actually predicts real estate outcomes in Sweden \u2014 is what makes our score uniquely valuable. Publishing exact weights would allow anyone to replicate the model trivially, which wouldn\u2019t serve the users who rely on us to maintain and improve it.",
    },
    {
        question: 'Is the score a property valuation?',
        answer: "No. The Neighborhood Trajectory Score is not an appraisal, a property valuation, or financial advice. It measures area-level conditions and trends \u2014 not individual property characteristics like size, condition, floor, or view. Two apartments in the same DeSO area will have the same neighborhood score but very different market values. Use the score to understand the area; use a m\u00E4klare to understand the property.",
    },
    {
        question: "What\u2019s the difference between you and Booli or Hemnet?",
        answer: "Hemnet is a listings platform \u2014 it shows you what\u2019s for sale. Booli adds pricing history and some analytics. We do something different: we score neighborhoods, not properties, using government data that goes far beyond transaction prices. We\u2019re answering a different question. They answer \u201Cwhat does this apartment cost?\u201D We answer \u201Cis this neighborhood getting better or worse, and why?\u201D",
    },
    {
        question: 'I\u2019m a journalist. Can I cite your scores?',
        // TODO: Replace "[Platform Name]" and "[contact info]" with actual values when decided
        answer: 'Yes. When citing our data, please attribute it to "Neighborhood Trajectory Score" and note that it is based on public data from SCB, Skolverket, BR\u00C5, and Kronofogden. We\u2019re happy to provide additional context for articles \u2014 reach out to us via the contact information on our website. We ask that you do not present the score as a property valuation or financial recommendation, as it is neither.',
    },
    {
        question: 'I\u2019m a researcher. Can I access the underlying data?',
        answer: "The raw data we use is entirely public \u2014 you can access it yourself from the government agencies we cite. What we add is the integration, normalization, and scoring methodology. We don\u2019t currently offer API access or bulk data exports, but if you\u2019re working on academic research involving neighborhood-level analysis in Sweden, we\u2019d love to hear from you.",
    },
    {
        question:
            'Why does the map sometimes show a high-scoring area right next to a low-scoring area?',
        answer: 'Our default hexagonal grid view already smooths scores across neighboring areas, so most transitions are gradual. When you still see a sharp contrast, it usually reflects a genuine geographic divide \u2014 it\u2019s not uncommon in Sweden for a wealthy residential area to be separated from a disadvantaged one by a single street or railway line. You can switch between the hexagonal view (which emphasizes smooth transitions) and the statistical area view (which shows exact administrative boundaries) using the layer toggle. Either way, click both areas to understand which specific factors drive the difference.',
    },
];

const PIPELINE_SOURCES = ['SCB', 'Skolverket', 'BR\u00C5', 'Kronofogden'];

export default function Methodology() {
    return (
        <MapLayout>
            <Head title="Methodology" />
            <div className="flex-1 overflow-y-auto">
                <div className="mx-auto max-w-2xl px-6 py-12 md:py-16">

                    {/* Section 1: Hero */}
                    <section className="mb-16">
                        <h1 className="mb-6 text-3xl font-bold tracking-tight md:text-4xl">
                            How the Neighborhood Trajectory Score Works
                        </h1>
                        <div className="text-muted-foreground space-y-4 text-lg leading-relaxed">
                            <p>
                                Every neighborhood in Sweden receives a score from 0 to 100 that
                                reflects its current conditions and likely trajectory over the
                                coming years. The score combines official government data across
                                multiple dimensions &mdash; economics, education, safety, and
                                financial stability &mdash; into a single number designed to help
                                you understand where an area stands and where it&apos;s heading.
                            </p>
                            <p>
                                We built this because no tool like it exists in Sweden. Hemnet
                                shows you listings. Booli shows you prices. We show you{' '}
                                <em>why</em> prices move.
                            </p>
                        </div>
                    </section>

                    {/* Section 2: The Score at a Glance */}
                    <section className="mb-16">
                        <h2 className="mb-8 text-2xl font-bold tracking-tight">
                            The Score at a Glance
                        </h2>

                        {/* Pipeline diagram */}
                        <div className="mb-8 flex flex-col items-center">
                            <p className="text-muted-foreground mb-4 text-sm font-medium uppercase tracking-wider">
                                Government Data Sources
                            </p>
                            <div className="mb-4 flex flex-wrap justify-center gap-2">
                                {PIPELINE_SOURCES.map((source) => (
                                    <div
                                        key={source}
                                        className="bg-muted rounded-md border px-4 py-2 text-sm font-medium"
                                    >
                                        {source}
                                    </div>
                                ))}
                            </div>

                            <FontAwesomeIcon icon={faChevronDown} className="text-muted-foreground my-2 h-5 w-5" />

                            <div className="bg-muted my-2 rounded-lg border px-6 py-3 text-center text-sm">
                                <p className="font-medium">Normalization & Analysis</p>
                                <p className="text-muted-foreground text-xs">
                                    Percentile ranking across all 6,160 areas in Sweden
                                </p>
                            </div>

                            <FontAwesomeIcon icon={faChevronDown} className="text-muted-foreground my-2 h-5 w-5" />

                            <div className="bg-muted my-2 rounded-lg border px-6 py-3 text-center text-sm">
                                <p className="font-medium">Weighted Composite Score</p>
                            </div>

                            <FontAwesomeIcon icon={faChevronDown} className="text-muted-foreground my-2 h-5 w-5" />

                            <div
                                className="my-2 rounded-xl px-8 py-4 text-center text-white"
                                style={{
                                    background:
                                        'linear-gradient(135deg, #c0392b, #e74c3c, #f39c12, #f1c40f, #27ae60, #1a7a2e)',
                                }}
                            >
                                <p className="text-2xl font-bold">0 &ndash; 100</p>
                                <p className="text-sm opacity-90">
                                    Neighborhood Trajectory Score
                                </p>
                            </div>
                        </div>

                        <p className="text-muted-foreground leading-relaxed">
                            Each area is compared against every other area in Sweden using
                            percentile ranking &mdash; meaning a score of 75 tells you this
                            neighborhood outperforms roughly 75% of all areas nationwide on the
                            factors we measure.
                        </p>
                    </section>

                    {/* Section 3: What We Measure */}
                    <section className="mb-16">
                        <h2 className="mb-8 text-2xl font-bold tracking-tight">
                            What We Measure
                        </h2>
                        <div className="space-y-4">
                            {DATA_SOURCES.map((source) => (
                                <Card key={source.title}>
                                    <CardHeader>
                                        <CardTitle className="text-lg">
                                            {source.title}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <div>
                                            <p className="text-muted-foreground mb-1 text-xs font-medium uppercase tracking-wider">
                                                What we measure
                                            </p>
                                            <p className="text-sm leading-relaxed">
                                                {source.measure}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground mb-1 text-xs font-medium uppercase tracking-wider">
                                                Why it matters
                                            </p>
                                            <p className="text-sm leading-relaxed">
                                                {source.why}
                                            </p>
                                        </div>
                                    </CardContent>
                                    <CardFooter className="flex flex-wrap items-center gap-3">
                                        {'sources' in source && source.sources ? (
                                            source.sources.map((s) => (
                                                <a
                                                    key={s.name}
                                                    href={s.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs transition-colors"
                                                >
                                                    {s.name}
                                                    <FontAwesomeIcon icon={faArrowUpRightFromSquare} className="h-3 w-3" />
                                                </a>
                                            ))
                                        ) : (
                                            <a
                                                href={source.sourceUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs transition-colors"
                                            >
                                                {source.source}
                                                <FontAwesomeIcon icon={faArrowUpRightFromSquare} className="h-3 w-3" />
                                            </a>
                                        )}
                                        <Badge variant="secondary" className="gap-1 text-xs">
                                            <FontAwesomeIcon icon={faCalendarClock} className="h-3 w-3" />
                                            {source.frequency}
                                        </Badge>
                                    </CardFooter>
                                </Card>
                            ))}
                        </div>
                    </section>

                    {/* Section 4: What the Score Means */}
                    <section className="mb-16">
                        <h2 className="mb-8 text-2xl font-bold tracking-tight">
                            What the Score Means
                        </h2>
                        <div className="space-y-3">
                            {SCORE_RANGES.map((range) => (
                                <div
                                    key={range.range}
                                    className="flex gap-4 rounded-lg border p-4"
                                >
                                    <div
                                        className="w-1 shrink-0 rounded-full"
                                        style={{ backgroundColor: range.color }}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-1 flex items-baseline gap-3">
                                            <span className="text-sm font-bold tabular-nums">
                                                {range.range}
                                            </span>
                                            <span className="text-sm font-semibold">
                                                {range.label}
                                            </span>
                                        </div>
                                        <p className="text-muted-foreground text-sm leading-relaxed">
                                            {range.description}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="text-muted-foreground mt-6 leading-relaxed">
                            A score is not a verdict &mdash; it&apos;s a starting point. A
                            score of 35 doesn&apos;t mean you shouldn&apos;t buy there; it
                            means you should understand <em>why</em> it scores that way before
                            you do. The factor breakdown for each area (available when you click
                            on the map) shows exactly which dimensions are strong and which are
                            weak, so you can make your own judgment.
                        </p>
                    </section>

                    {/* Section 5: Our Approach */}
                    <section className="mb-16">
                        <h2 className="mb-8 text-2xl font-bold tracking-tight">
                            How We Build the Score
                        </h2>
                        <div className="space-y-8">
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    Everything is relative, not absolute.
                                </h3>
                                <p className="text-muted-foreground leading-relaxed">
                                    We don&apos;t score areas on an abstract scale. Every area
                                    is ranked against every other area in Sweden. A score of 73
                                    means this area outperforms 73% of all neighborhoods in the
                                    country on the factors we measure. This makes scores
                                    directly comparable &mdash; whether you&apos;re looking at
                                    central Stockholm or rural Norrland.
                                </p>
                            </div>
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    All data is public and official.
                                </h3>
                                <p className="text-muted-foreground leading-relaxed">
                                    Every input to our model comes from Swedish government
                                    agencies: SCB, Skolverket, BR&Aring;, Kronofogden, and
                                    Polisen. We don&apos;t use proprietary data, surveys of our
                                    own, or user-submitted reviews. This means our scores are
                                    reproducible, auditable, and grounded in the same statistics
                                    that policymakers rely on.
                                </p>
                            </div>
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    We measure what matters for real estate.
                                </h3>
                                <p className="text-muted-foreground leading-relaxed">
                                    Not every statistic matters equally for property values. We
                                    focus on the factors that research and market evidence show
                                    actually drive where people want to live and what
                                    they&apos;re willing to pay. The weighting of each factor in
                                    our model is based on its demonstrated relationship with
                                    real estate outcomes in the Swedish market.
                                </p>
                            </div>
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    We never use individual-level data.
                                </h3>
                                <p className="text-muted-foreground leading-relaxed">
                                    All our inputs are aggregate statistics &mdash; averages,
                                    rates, and percentages across entire areas. We do not
                                    access, store, or process any data about individual people.
                                    This is a deliberate design choice, both for legal
                                    compliance and because aggregate patterns are what drive
                                    neighborhood-level trends.
                                </p>
                            </div>
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    The score updates as new data becomes available.
                                </h3>
                                <p className="text-muted-foreground leading-relaxed">
                                    Government agencies publish new data on different schedules
                                    &mdash; some annually, some quarterly. When new data
                                    arrives, we re-run the model and the map updates. The
                                    &ldquo;last updated&rdquo; date for each data source is
                                    visible in the area detail view.
                                </p>
                            </div>
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    Boundaries are not walls.
                                </h3>
                                <p className="text-muted-foreground leading-relaxed">
                                    Government statistics are published for defined geographic
                                    areas &mdash; but reality doesn&apos;t stop at a boundary
                                    line. A street that separates two statistical areas
                                    doesn&apos;t create a wall between them. That&apos;s why our
                                    map defaults to a hexagonal grid rather than showing raw
                                    administrative boundaries. Each hexagon blends data from the
                                    statistical areas it touches, creating smooth, gradual
                                    transitions that reflect how neighborhoods actually work.
                                    You can switch to the traditional boundary view at any time,
                                    but the hexagonal layer gives you a more honest picture of
                                    how conditions shift across the landscape.
                                </p>
                            </div>
                        </div>
                    </section>

                    {/* Section 6: FAQ */}
                    <section className="mb-16">
                        <h2 className="mb-8 text-2xl font-bold tracking-tight">
                            Frequently Asked Questions
                        </h2>
                        <Accordion type="multiple" className="w-full">
                            {FAQ_ITEMS.map((item, index) => (
                                <AccordionItem
                                    key={index}
                                    value={`faq-${index}`}
                                >
                                    <AccordionTrigger>
                                        {item.question}
                                    </AccordionTrigger>
                                    <AccordionContent>
                                        <p className="text-muted-foreground leading-relaxed">
                                            {item.answer}
                                        </p>
                                    </AccordionContent>
                                </AccordionItem>
                            ))}
                        </Accordion>
                    </section>

                    {/* Section 7: Footer CTA */}
                    <section className="mb-12 text-center">
                        <h2 className="mb-3 text-2xl font-bold tracking-tight">
                            Ready to explore?
                        </h2>
                        <p className="text-muted-foreground mb-6 leading-relaxed">
                            Go back to the map and click any area in Sweden to see its score,
                            factor breakdown, and school details.
                        </p>
                        <Button asChild size="lg">
                            <Link href={map().url}>
                                Back to Map
                                <FontAwesomeIcon icon={faArrowRight} className="ml-2 h-4 w-4" />
                            </Link>
                        </Button>
                    </section>
                </div>
            </div>
        </MapLayout>
    );
}
