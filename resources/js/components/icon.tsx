import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

import { cn } from '@/lib/utils';

interface IconProps {
    icon: IconDefinition;
    className?: string;
}

export function Icon({
    icon,
    className,
}: IconProps) {
    return <FontAwesomeIcon icon={icon} className={cn('h-4 w-4', className)} />;
}
