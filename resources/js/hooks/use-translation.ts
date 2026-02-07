import { useTranslation as useI18nTranslation } from 'react-i18next';

export function useTranslation(ns?: string) {
    const { t: originalT, i18n, ready } = useI18nTranslation(ns);

    const t = (key: string, options?: Record<string, unknown>): string => {
        const currentLang = i18n.language;
        const result = originalT(key, { ...options, lng: currentLang });

        // If we're in Swedish and the key doesn't exist in sv.json,
        // show a visible marker instead of silently falling back to English.
        if (currentLang === 'sv' && !i18n.exists(key, { lng: 'sv' })) {
            if (import.meta.env.DEV) {
                return `\u{1F511} ${key}`;
            }
            return `[${key}]`;
        }

        return result as string;
    };

    return { t, i18n, ready };
}
