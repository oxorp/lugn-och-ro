import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import en from './locales/en.json';
import sv from './locales/sv.json';

i18n
    .use(initReactI18next)
    .init({
        resources: {
            en: { translation: en },
            sv: { translation: sv },
        },
        fallbackLng: 'sv',
        supportedLngs: ['en', 'sv'],
        interpolation: {
            escapeValue: false,
        },
        saveMissing: true,
        missingKeyHandler: (_lngs, _ns, key) => {
            if (import.meta.env.DEV) {
                console.warn(`[i18n] Missing translation: ${key}`);
            }
        },
        returnNull: false,
        returnEmptyString: false,
    });

export default i18n;
