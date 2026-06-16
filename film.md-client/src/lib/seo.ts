import type { Movie } from "../types";

const API_URL = import.meta.env.VITE_API_URL ?? "https://filmmd-api.veezify.com/api/v1";

type LocaleCode = "ro" | "ru" | "en";
type LocalizedText = Record<LocaleCode, string>;

interface SeoSettings {
  site_name?: string;
  default_title?: Partial<LocalizedText>;
  default_description?: Partial<LocalizedText>;
  default_image_url?: string;
  canonical_base_url?: string;
}

let cachedSettings: SeoSettings | null = null;

function localized(value: Partial<LocalizedText> | undefined, locale: LocaleCode) {
  return value?.[locale] || value?.ro || value?.en || "";
}

function absoluteUrl(url?: string | null) {
  if (!url) {
    return "";
  }

  try {
    return new URL(url, window.location.origin).toString();
  } catch {
    return url;
  }
}

function compactText(value?: string | null, maxLength = 180) {
  const text = (value ?? "").replace(/\s+/g, " ").trim();

  if (text.length <= maxLength) {
    return text;
  }

  const slice = text.slice(0, maxLength - 1).trimEnd();
  const lastSpace = slice.lastIndexOf(" ");
  const shortened = lastSpace > 80 ? slice.slice(0, lastSpace) : slice;

  return `${shortened}...`;
}

export function movieShareImage(movie: Movie, fallbackImageUrl?: string) {
  return movie.heroDesktopUrl || movie.backdropUrl || movie.posterUrl || fallbackImageUrl || "";
}

export function movieShareDescription(movie: Movie, fallbackDescription?: string) {
  const baseDescription = movie.metaDescription || movie.shortDescription || movie.description || fallbackDescription || "";
  const details = [
    movie.year ? String(movie.year) : null,
    movie.typeLabel,
    movie.genres?.slice(0, 2).join(", "),
  ].filter(Boolean);
  const detailSuffix = details.length > 0 ? ` ${details.join(" · ")}.` : "";
  const watchSuffix = ` Vezi ${movie.title} online pe filmoteca.md.`;

  return compactText(`${baseDescription}${detailSuffix}${watchSuffix}`);
}

export function movieSharePreviewUrl(movie: Movie, locale: LocaleCode) {
  const apiBaseUrl = API_URL.replace(/\/$/, "");
  const identifier = encodeURIComponent(movie.id);

  return `${apiBaseUrl}/public/content/${identifier}/share-preview?locale=${encodeURIComponent(locale)}`;
}

function setMeta(selector: string, attr: "name" | "property", key: string, content?: string) {
  let element = document.head.querySelector<HTMLMetaElement>(selector);

  if (!content) {
    element?.remove();
    return;
  }

  if (!element) {
    element = document.createElement("meta");
    element.setAttribute(attr, key);
    document.head.appendChild(element);
  }

  element.setAttribute("content", content);
}

function setLink(rel: string, href?: string) {
  let element = document.head.querySelector<HTMLLinkElement>(`link[rel="${rel}"]`);

  if (!href) {
    element?.remove();
    return;
  }

  if (!element) {
    element = document.createElement("link");
    element.rel = rel;
    document.head.appendChild(element);
  }

  element.href = href;
}

export async function getSeoSettings() {
  if (cachedSettings) {
    return cachedSettings;
  }

  try {
    const response = await fetch(`${API_URL}/public/settings`, { headers: { Accept: "application/json" } });
    const payload = await response.json();
    cachedSettings = typeof payload.seo === "object" && payload.seo !== null ? payload.seo : {};
  } catch {
    cachedSettings = {};
  }

  return cachedSettings;
}

export function applySeo(options: {
  title: string;
  description?: string;
  imageUrl?: string;
  canonicalUrl?: string;
  type?: string;
}) {
  const title = options.title.trim() || "filmoteca.md";
  const description = options.description?.trim() ?? "";
  const imageUrl = absoluteUrl(options.imageUrl);
  const canonicalUrl = absoluteUrl(options.canonicalUrl || window.location.href);

  document.title = title;
  setMeta('meta[name="description"]', "name", "description", description);
  setMeta('meta[property="og:title"]', "property", "og:title", title);
  setMeta('meta[property="og:description"]', "property", "og:description", description);
  setMeta('meta[property="og:image"]', "property", "og:image", imageUrl);
  setMeta('meta[property="og:url"]', "property", "og:url", canonicalUrl);
  setMeta('meta[property="og:type"]', "property", "og:type", options.type ?? "website");
  setMeta('meta[name="twitter:card"]', "name", "twitter:card", imageUrl ? "summary_large_image" : "summary");
  setMeta('meta[name="twitter:title"]', "name", "twitter:title", title);
  setMeta('meta[name="twitter:description"]', "name", "twitter:description", description);
  setMeta('meta[name="twitter:image"]', "name", "twitter:image", imageUrl);
  setLink("canonical", canonicalUrl);
}

export async function applyDefaultSeo(locale: LocaleCode) {
  const settings = await getSeoSettings();
  const siteName = settings.site_name || "filmoteca.md";
  const title = localized(settings.default_title, locale) || siteName;

  applySeo({
    title,
    description: localized(settings.default_description, locale),
    imageUrl: settings.default_image_url,
    canonicalUrl: settings.canonical_base_url
      ? `${settings.canonical_base_url.replace(/\/$/, "")}${window.location.pathname}`
      : window.location.href,
  });
}

export async function applyMovieSeo(movie: Movie, locale: LocaleCode) {
  const settings = await getSeoSettings();
  const siteName = settings.site_name || "filmoteca.md";
  const title = movie.metaTitle || `${movie.title} | ${siteName}`;

  applySeo({
    title,
    description: movieShareDescription(movie, localized(settings.default_description, locale)),
    imageUrl: movieShareImage(movie, settings.default_image_url),
    canonicalUrl: movie.canonicalUrl || (settings.canonical_base_url
      ? `${settings.canonical_base_url.replace(/\/$/, "")}/movie/${movie.id}`
      : window.location.href),
    type: "video.movie",
  });
}
