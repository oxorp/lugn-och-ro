import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import type { SharedData } from '@/types';

/**
 * Syncs the backend locale (shared via Inertia) with i18next on the frontend.
 * URL → Laravel middleware → Inertia share → this component → i18next.
 */
export default function LocaleSync() {
    const { locale } = usePage<SharedData>().props;
    const { i18n } = useTranslation();

    useEffect(() => {
        if (locale && i18n.language !== locale) {
            i18n.changeLanguage(locale);
        }
    }, [locale, i18n]);

    return null;
}
