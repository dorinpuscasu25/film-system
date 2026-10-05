import { useTranslation } from 'react-i18next';
import { SUPPORTED_LOCALES, type Locale } from '../i18n';

const LABELS: Record<Locale, string> = {
  ro: 'Română',
  ru: 'Русский',
  en: 'English',
};

export function LanguageSwitcher() {
  const { i18n } = useTranslation();
  const current = (i18n.resolvedLanguage ?? 'ro') as Locale;

  return (
    <select
      value={current}
      onChange={(e) => {
        void i18n.changeLanguage(e.target.value);
      }}
      className="rounded-md border border-border bg-background px-2 py-1 text-sm outline-none focus:border-primary"
      aria-label="Language switcher"
    >
      {SUPPORTED_LOCALES.map((loc) => (
        <option key={loc} value={loc}>
          {LABELS[loc]}
        </option>
      ))}
    </select>
  );
}
