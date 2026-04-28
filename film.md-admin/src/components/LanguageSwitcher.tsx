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
      className="rounded-md bg-zinc-800/60 text-zinc-100 px-2 py-1 text-sm border border-zinc-700"
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
