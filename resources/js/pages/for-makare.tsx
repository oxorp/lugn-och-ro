import { Head, Link } from '@inertiajs/react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

import {
    faArrowRight,
    faBinoculars,
    faCheck,
    faCircleCheck,
    faEnvelope,
    faFileLines,
    faShieldCheck,
    faSparkles,
} from '@/icons';

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
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import MapLayout from '@/layouts/map-layout';
import { map } from '@/routes';

interface ForMakareProps {
    translations: {
        hero: {
            badge: string;
            title: string;
            subtitle: string;
            cta_primary: string;
            cta_secondary: string;
        };
        use_cases: {
            title: string;
            prospecting: { title: string; description: string; benefit: string };
            presentation: { title: string; description: string; benefit: string };
            objection: { title: string; description: string; benefit: string };
        };
        social_proof: {
            title: string;
            testimonial_1: { quote: string; author: string; company: string; role: string };
            testimonial_2: { quote: string; author: string; company: string; role: string };
            testimonial_3: { quote: string; author: string; company: string; role: string };
        };
        pricing: {
            title: string;
            subtitle: string;
            recommended_label: string;
            individual: {
                title: string;
                description: string;
                price: string;
                price_period: string;
                cta: string;
                features: string[];
                recommended: boolean;
            };
            enterprise: {
                title: string;
                description: string;
                price: string;
                price_period: string;
                cta: string;
                features: string[];
                recommended: boolean;
            };
            api: {
                title: string;
                description: string;
                price: string;
                price_period: string;
                cta: string;
                features: string[];
                recommended: boolean;
            };
        };
        faq: {
            title: string;
            questions: Array<{ question: string; answer: string }>;
        };
        cta_bottom: {
            title: string;
            subtitle: string;
            button: string;
        };
        meta: {
            title: string;
            description: string;
        };
    };
}

export default function ForMakare({ translations: t }: ForMakareProps) {
    const USE_CASES = [
        {
            title: t.use_cases.prospecting.title,
            icon: faBinoculars,
            description: t.use_cases.prospecting.description,
            benefit: t.use_cases.prospecting.benefit,
        },
        {
            title: t.use_cases.presentation.title,
            icon: faFileLines,
            description: t.use_cases.presentation.description,
            benefit: t.use_cases.presentation.benefit,
        },
        {
            title: t.use_cases.objection.title,
            icon: faShieldCheck,
            description: t.use_cases.objection.description,
            benefit: t.use_cases.objection.benefit,
        },
    ];

    const TESTIMONIALS = [
        {
            quote: t.social_proof.testimonial_1.quote,
            author: t.social_proof.testimonial_1.author,
            company: t.social_proof.testimonial_1.company,
        },
        {
            quote: t.social_proof.testimonial_2.quote,
            author: t.social_proof.testimonial_2.author,
            company: t.social_proof.testimonial_2.company,
        },
        {
            quote: t.social_proof.testimonial_3.quote,
            author: t.social_proof.testimonial_3.author,
            company: t.social_proof.testimonial_3.company,
        },
    ];

    const PRICING_TIERS = [
        {
            name: t.pricing.individual.title,
            price: t.pricing.individual.price,
            period: t.pricing.individual.price_period,
            description: t.pricing.individual.description,
            features: t.pricing.individual.features,
            cta: t.pricing.individual.cta,
            ctaVariant: 'default' as const,
            recommended: t.pricing.individual.recommended,
        },
        {
            name: t.pricing.enterprise.title,
            price: t.pricing.enterprise.price,
            period: t.pricing.enterprise.price_period,
            description: t.pricing.enterprise.description,
            features: t.pricing.enterprise.features,
            cta: t.pricing.enterprise.cta,
            ctaVariant: 'outline' as const,
            recommended: t.pricing.enterprise.recommended,
        },
        {
            name: t.pricing.api.title,
            price: t.pricing.api.price,
            period: t.pricing.api.price_period,
            description: t.pricing.api.description,
            features: t.pricing.api.features,
            cta: t.pricing.api.cta,
            ctaVariant: 'outline' as const,
            recommended: t.pricing.api.recommended,
        },
    ];

    return (
        <MapLayout>
            <Head title={t.meta.title} />
            <div className="flex-1 overflow-y-auto">
                <div className="mx-auto max-w-4xl px-6 py-12 md:py-16">
                    {/* Hero Section */}
                    <section className="mb-20 text-center">
                        <Badge variant="secondary" className="mb-4 gap-1.5 text-sm">
                            <FontAwesomeIcon icon={faSparkles} className="h-3.5 w-3.5" />
                            {t.hero.badge}
                        </Badge>
                        <h1 className="mb-6 text-4xl font-bold tracking-tight md:text-5xl lg:text-6xl">
                            {t.hero.title}
                        </h1>
                        <p className="text-muted-foreground mx-auto mb-8 max-w-2xl text-lg leading-relaxed md:text-xl">
                            {t.hero.subtitle}
                        </p>
                        <div className="flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Button asChild size="lg">
                                <Link href="#pricing">
                                    {t.hero.cta_primary}
                                    <FontAwesomeIcon
                                        icon={faArrowRight}
                                        className="ml-2 h-4 w-4"
                                    />
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="lg">
                                <Link href={map().url}>
                                    {t.hero.cta_secondary}
                                    <FontAwesomeIcon
                                        icon={faArrowRight}
                                        className="ml-2 h-4 w-4"
                                    />
                                </Link>
                            </Button>
                        </div>
                    </section>

                    {/* Use Cases Section */}
                    <section className="mb-20">
                        <div className="mb-12 text-center">
                            <h2 className="mb-4 text-3xl font-bold tracking-tight md:text-4xl">
                                {t.use_cases.title}
                            </h2>
                        </div>
                        <div className="grid gap-8 md:grid-cols-3">
                            {USE_CASES.map((useCase) => (
                                <Card key={useCase.title} className="flex flex-col">
                                    <CardHeader>
                                        <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10">
                                            <FontAwesomeIcon
                                                icon={useCase.icon}
                                                className="h-6 w-6 text-primary"
                                            />
                                        </div>
                                        <CardTitle className="text-xl">
                                            {useCase.title}
                                        </CardTitle>
                                        <CardDescription className="leading-relaxed">
                                            {useCase.description}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="flex-1">
                                        <div className="rounded-lg bg-muted/50 p-4">
                                            <p className="text-sm font-medium">
                                                <FontAwesomeIcon
                                                    icon={faCircleCheck}
                                                    className="mr-2 h-4 w-4 text-green-600"
                                                />
                                                {useCase.benefit}
                                            </p>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </section>

                    {/* Social Proof Section */}
                    <section className="mb-20">
                        <div className="mb-12 text-center">
                            <h2 className="mb-4 text-3xl font-bold tracking-tight md:text-4xl">
                                {t.social_proof.title}
                            </h2>
                        </div>
                        <div className="grid gap-8 md:grid-cols-3">
                            {TESTIMONIALS.map((testimonial, idx) => (
                                <Card key={idx}>
                                    <CardContent className="pt-6">
                                        <p className="text-muted-foreground mb-6 leading-relaxed">
                                            &ldquo;{testimonial.quote}&rdquo;
                                        </p>
                                        <div className="border-t border-border pt-4">
                                            <p className="font-semibold">
                                                {testimonial.author}
                                            </p>
                                            <p className="text-muted-foreground text-sm">
                                                {testimonial.company}
                                            </p>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </section>

                    {/* Pricing Section */}
                    <section id="pricing" className="mb-20">
                        <div className="mb-12 text-center">
                            <h2 className="mb-4 text-3xl font-bold tracking-tight md:text-4xl">
                                {t.pricing.title}
                            </h2>
                            <p className="text-muted-foreground mx-auto max-w-2xl text-lg leading-relaxed">
                                {t.pricing.subtitle}
                            </p>
                        </div>
                        <div className="grid gap-8 lg:grid-cols-3">
                            {PRICING_TIERS.map((tier) => (
                                <Card
                                    key={tier.name}
                                    className={
                                        tier.recommended
                                            ? 'border-primary shadow-lg'
                                            : ''
                                    }
                                >
                                    <CardHeader>
                                        {tier.recommended && (
                                            <Badge className="mb-2 w-fit">
                                                {t.pricing.recommended_label}
                                            </Badge>
                                        )}
                                        <CardTitle className="text-2xl">
                                            {tier.name}
                                        </CardTitle>
                                        <CardDescription className="leading-relaxed">
                                            {tier.description}
                                        </CardDescription>
                                        <div className="mt-4">
                                            <div className="text-3xl font-bold">
                                                {tier.price}
                                            </div>
                                            {tier.period && (
                                                <div className="text-muted-foreground text-sm">
                                                    /{tier.period}
                                                </div>
                                            )}
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <ul className="space-y-3">
                                            {tier.features.map((feature, idx) => (
                                                <li
                                                    key={idx}
                                                    className="flex items-start gap-2 text-sm"
                                                >
                                                    <FontAwesomeIcon
                                                        icon={faCheck}
                                                        className="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                                    />
                                                    <span>{feature}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </CardContent>
                                    <CardFooter>
                                        <Button
                                            className="w-full"
                                            variant={tier.ctaVariant}
                                            size="lg"
                                            asChild={tier.name === t.pricing.individual.title}
                                        >
                                            {tier.name === t.pricing.individual.title ? (
                                                <Link href="#trial">{tier.cta}</Link>
                                            ) : (
                                                <button>
                                                    <FontAwesomeIcon
                                                        icon={faEnvelope}
                                                        className="mr-2 h-4 w-4"
                                                    />
                                                    {tier.cta}
                                                </button>
                                            )}
                                        </Button>
                                    </CardFooter>
                                </Card>
                            ))}
                        </div>
                    </section>

                    {/* FAQ Section */}
                    <section className="mb-20">
                        <div className="mb-12 text-center">
                            <h2 className="mb-4 text-3xl font-bold tracking-tight md:text-4xl">
                                {t.faq.title}
                            </h2>
                        </div>
                        <div className="mx-auto max-w-3xl">
                            <Accordion type="multiple" className="w-full">
                                {t.faq.questions.map((item, index) => (
                                    <AccordionItem key={index} value={`faq-${index}`}>
                                        <AccordionTrigger className="text-left">
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
                        </div>
                    </section>

                    {/* Final CTA Section */}
                    <section className="mb-8">
                        <Card className="border-primary bg-primary/5">
                            <CardContent className="pt-12 pb-12 text-center">
                                <h2 className="mb-4 text-3xl font-bold tracking-tight">
                                    {t.cta_bottom.title}
                                </h2>
                                <p className="text-muted-foreground mx-auto mb-8 max-w-2xl text-lg leading-relaxed">
                                    {t.cta_bottom.subtitle}
                                </p>
                                <div className="flex flex-col items-center justify-center gap-4 sm:flex-row">
                                    <Button size="lg" asChild>
                                        <Link href="#pricing">
                                            {t.cta_bottom.button}
                                            <FontAwesomeIcon
                                                icon={faArrowRight}
                                                className="ml-2 h-4 w-4"
                                            />
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="lg" asChild>
                                        <Link href={map().url}>{t.hero.cta_secondary}</Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </section>
                </div>
            </div>
        </MapLayout>
    );
}
