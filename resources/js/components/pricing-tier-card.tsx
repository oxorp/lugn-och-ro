import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Link } from '@inertiajs/react';
import * as React from 'react';

import { faCheck } from '@/icons';
import { cn } from '@/lib/utils';

import { Badge } from './ui/badge';
import { Button } from './ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from './ui/card';

interface PricingTierCardProps extends React.ComponentProps<typeof Card> {
    title: string;
    price: string;
    description?: string;
    features: string[];
    ctaText: string;
    ctaHref?: string;
    isRecommended?: boolean;
}

export default function PricingTierCard({
    title,
    price,
    description,
    features,
    ctaText,
    ctaHref,
    isRecommended = false,
    className,
    ...props
}: PricingTierCardProps) {
    return (
        <Card
            className={cn(
                'relative flex flex-col',
                isRecommended &&
                    'border-primary shadow-md ring-1 ring-primary/20',
                className,
            )}
            {...props}
        >
            {isRecommended && (
                <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                    <Badge variant="default" className="px-3 py-1">
                        Recommended
                    </Badge>
                </div>
            )}

            <CardHeader>
                <CardTitle className="text-2xl">{title}</CardTitle>
                {description && (
                    <CardDescription className="text-base">
                        {description}
                    </CardDescription>
                )}
            </CardHeader>

            <CardContent className="flex-1 space-y-6">
                <div>
                    <div className="text-4xl font-bold tracking-tight">
                        {price}
                    </div>
                </div>

                <ul className="space-y-3">
                    {features.map((feature, index) => (
                        <li
                            key={index}
                            className="flex items-start gap-3 text-sm"
                        >
                            <FontAwesomeIcon
                                icon={faCheck}
                                className="text-primary mt-0.5 h-4 w-4 shrink-0"
                            />
                            <span className="leading-relaxed">{feature}</span>
                        </li>
                    ))}
                </ul>
            </CardContent>

            <CardFooter>
                {ctaHref ? (
                    <Button asChild size="lg" className="w-full">
                        <Link href={ctaHref}>{ctaText}</Link>
                    </Button>
                ) : (
                    <Button size="lg" className="w-full" disabled>
                        {ctaText}
                    </Button>
                )}
            </CardFooter>
        </Card>
    );
}
