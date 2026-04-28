import i18n from 'i18next';
import LanguageDetector from 'i18next-browser-languagedetector';
import { initReactI18next } from 'react-i18next';

import en from './locales/en.json';
import ro from './locales/ro.json';
import ru from './locales/ru.json';

export const SUPPORTED_LOCALES = ['ro', 'ru', 'en'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      ro: { translation: ro },
      ru: { translation: ru },
      en: { translation: en },
    },
    fallbackLng: 'ro',
    supportedLngs: SUPPORTED_LOCALES as unknown as string[],
    interpolation: { escapeValue: false },
    detection: {
      order: ['localStorage', 'navigator'],
      caches: ['localStorage'],
      lookupLocalStorage: 'admin_locale',
    },
  });

export default i18n;
