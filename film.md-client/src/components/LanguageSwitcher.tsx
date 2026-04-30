import { useTranslation } from 'react-i18next';
import { SUPPORTED_LOCALES, type Locale } from '../i18n';

const LABELS: Record<Locale, string> = {
  ro: 'RO',
  ru: 'RU',
  en: 'EN',
};

export function LanguageSwitcher() {
  const { i18n } = useTranslation();
  const current = (i18n.resolvedLanguage ?? 'ro') as Locale;

  return (
    <div className="flex items-center gap-1 text-xs">
      {SUPPORTED_LOCALES.map((loc) => (
        <button
          key={loc}
          onClick={() => {
            void i18n.changeLanguage(loc);
          }}
          className={`px-2 py-1 rounded transition-colors ${
            loc === current
              ? 'bg-white/15 text-white font-semibold'
              : 'text-white/50 hover:text-white/80'
          }`}
          aria-label={`Switch to ${LABELS[loc]}`}
        >
          {LABELS[loc]}
        </button>
      ))}
    </div>
  );
}
