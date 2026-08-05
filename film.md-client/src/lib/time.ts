export type SupportedLocale = "ro" | "ru" | "en";

type CompactDurationParts = {
  days?: number;
  hours?: number;
  minutes?: number;
};

const COMPACT_UNITS: Record<SupportedLocale, { day: string; hour: string; minute: string }> = {
  ro: { day: "z", hour: "h", minute: "min" },
  ru: { day: "д", hour: "ч", minute: "мин" },
  en: { day: "d", hour: "h", minute: "m" },
};

export function formatCompactDuration(
  { days = 0, hours = 0, minutes = 0 }: CompactDurationParts,
  locale: SupportedLocale,
): string {
  const units = COMPACT_UNITS[locale] ?? COMPACT_UNITS.ro;
  const parts: string[] = [];

  if (days > 0) {
    parts.push(`${days}${units.day}`);
  }

  if (hours > 0) {
    parts.push(`${hours}${units.hour}`);
  }

  if (minutes > 0 || parts.length === 0) {
    parts.push(`${minutes}${units.minute}`);
  }

  return parts.join(" ");
}

export function formatRuntimeMinutes(totalMinutes: number, locale: SupportedLocale): string {
  const normalizedMinutes = Math.max(0, Math.floor(totalMinutes));

  return formatCompactDuration({
    hours: Math.floor(normalizedMinutes / 60),
    minutes: normalizedMinutes % 60,
  }, locale);
}
