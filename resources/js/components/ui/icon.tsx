import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

interface IconProps {
    iconNode?: IconDefinition | null;
    className?: string;
}

export function Icon({ iconNode, className }: IconProps) {
    if (!iconNode) {
        return null;
    }

    return <FontAwesomeIcon icon={iconNode} className={className} />;
}
