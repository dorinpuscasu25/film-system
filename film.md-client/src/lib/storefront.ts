import { Movie, Offer } from "../types";

const API_URL = import.meta.env.VITE_API_URL ?? "http://localhost:8000/api/v1";
const DEFAULT_PAGE_SIZE = 100;

type LocaleCode = "en" | "ro" | "ru";

interface PublicBadge {
  id: string;
  slug: string;
  label: string;
  color: string | null;
}

interface PublicOffer {
  id: string;
  name: string;
  offer_type: "free" | "rental" | "lifetime";
  quality: string;
  currency: string;
  price_amount: number;
  rental_days?: number | null;
}

interface PublicCastMember {
  id: string;
  name: string;
  role: string;
  avatar_url?: string | null;
}

interface PublicCrewMember {
  id: string;
  name: string;
  job: string;
  avatar_url?: string | null;
}

interface PublicVideo {
  id: string;
  type: "trailer" | "teaser" | "clip" | "extra" | "behind_scenes" | "interview";
  title: string;
  description?: string | null;
  video_url: string;
  thumbnail_url?: string | null;
  duration_seconds?: number | null;
  is_primary?: boolean;
}

interface PublicEpisode {
  id: string;
  episode_number: number;
  title: string;
  description?: string | null;
  runtime_minutes?: number | null;
  thumbnail_url?: string | null;
  backdrop_url?: string | null;
  video_url?: string | null;
  trailer_url?: string | null;
}

interface PublicSeason {
  id: string;
  season_number: number;
  title?: string | null;
  description?: string | null;
  poster_url?: string | null;
  episodes: PublicEpisode[];
}

interface PublicContentCard {
  id: string;
  slug: string;
  type: "movie" | "series";
  title: string;
  original_title: string;
  short_description?: string;
  tagline?: string;
  release_year?: number | null;
  country_name?: string | null;
  country_code?: string | null;
  imdb_rating?: number | null;
  platform_rating?: number | null;
  genres: string[];
  badges?: PublicBadge[];
  is_featured?: boolean;
  is_trending?: boolean;
  is_free?: boolean;
  poster_url: string;
  backdrop_url: string;
  hero_desktop_url?: string | null;
  hero_mobile_url?: string | null;
  trailer_url?: string | null;
  premiere_event?: {
    id: string | number;
    title: string;
    starts_at: string;
    ends_at?: string | null;
  } | null;
  lowest_price?: number;
  currency?: string;
  available_qualities?: string[];
}

interface PublicContentDetail extends PublicContentCard {
  description: string;
  cast: PublicCastMember[];
  crew: PublicCrewMember[];
  videos: PublicVideo[];
  seasons: PublicSeason[];
  seasons_count?: number;
  episodes_count?: number;
  offers: PublicOffer[];
}

export interface PublicReview {
  id: string | number;
  user_id: string | number;
  user_name: string;
  user_avatar: string;
  rating: number;
  comment: string;
  status: "published" | "hidden";
  created_at: string;
  updated_at?: string | null;
}

export interface PublicReviewsResponse {
  items: PublicReview[];
  summary: {
    count: number;
    average_rating: number;
  };
}

interface HomeResponse {
  hero: PublicContentCard | null;
  hero_slides?: Array<{
    id: string;
    desktop_image_url: string;
    mobile_image_url?: string | null;
    eyebrow?: string | null;
    title: string;
    description?: string | null;
    primary_cta_label?: string | null;
    secondary_cta_label?: string | null;
    content: PublicContentCard;
  }>;
  sections?: Array<{
    id: string;
    name: string;
    section_type: "content_carousel";
    source_mode?: "manual" | "dynamic" | null;
    sort_order: number;
    title: string;
    subtitle?: string | null;
    items: PublicContentCard[];
  }>;
  featured: PublicContentCard[];
  free_to_watch: PublicContentCard[];
  latest: PublicContentCard[];
  movies: PublicContentCard[];
  series: PublicContentCard[];
}

export interface HomeHeroSlide {
  id: string;
  desktopImageUrl: string;
  mobileImageUrl?: string;
  eyebrow?: string;
  title: string;
  description?: string;
  primaryCtaLabel?: string;
  secondaryCtaLabel?: string;
  content: Movie;
}

export interface HomeCarouselSection {
  id: string;
  name: string;
  title: string;
  subtitle?: string;
  sourceMode?: "manual" | "dynamic";
  items: Movie[];
}

interface CatalogResponse {
  items: PublicContentCard[];
  page: number;
  page_size: number;
  total: number;
  filters?: {
    genres?: Array<{ value: string; label: string; count: number }>;
    years?: Array<{ value: string; label: string; count: number }>;
    countries?: Array<{ value: string; label: string; count: number }>;
    types?: Array<{ value: string; label: string; count: number }>;
    access?: Array<{ value: string; label: string; count: number }>;
  };
  search_engine?: "meilisearch" | "database";
}

export interface HomeSections {
  hero: Movie | null;
  heroSlides: HomeHeroSlide[];
  sections: HomeCarouselSection[];
  featured: Movie[];
  freeToWatch: Movie[];
  latest: Movie[];
  movies: Movie[];
  series: Movie[];
}

export interface CatalogQuery {
  query?: string;
  type?: "movie" | "series";
  genre?: string;
  access?: "free" | "paid";
  year?: string;
  country?: string;
  minRating?: number;
  page?: number;
  pageSize?: number;
}

export interface CatalogFilterOption {
  value: string;
  label: string;
  count: number;
}

export interface CatalogFilters {
  genres: CatalogFilterOption[];
  years: CatalogFilterOption[];
  countries: CatalogFilterOption[];
  types: CatalogFilterOption[];
  access: CatalogFilterOption[];
}

export interface CatalogPageResult {
  items: Movie[];
  page: number;
  pageSize: number;
  total: number;
  filters: CatalogFilters;
  searchEngine: "meilisearch" | "database";
}

async function fetchJson<T>(path: string, query?: Record<string, string | number | undefined>): Promise<T> {
  const params = new URLSearchParams();

  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== "") {
      params.set(key, String(value));
    }
  });

  const url = `${API_URL}${path}${params.toString() ? `?${params.toString()}` : ""}`;
  const response = await fetch(url, {
    headers: {
      Accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error("Nu am putut încărca datele storefront.");
  }

  return response.json() as Promise<T>;
}

function deriveAccessDuration(offers: Offer[], fallbackPrice: number) {
  const rentalOffer = offers.find((offer) => offer.accessType === "rental");
  if (rentalOffer?.rentalDays) {
    return rentalOffer.rentalDays * 24;
  }

  if (offers.some((offer) => offer.accessType === "free" || offer.accessType === "lifetime")) {
    return 999999;
  }

  return fallbackPrice === 0 ? 999999 : 48;
}

function mapOffer(offer: PublicOffer): Offer {
  return {
    id: offer.id,
    name: offer.name,
    accessType: offer.offer_type,
    quality: offer.quality,
    price: Number(offer.price_amount ?? 0),
    currency: offer.currency || "USD",
    rentalDays: offer.rental_days ?? undefined,
  };
}

function mapCardToMovie(item: PublicContentCard): Movie {
  const offers = item.is_free
    ? [{
        id: `${item.slug}-free`,
        name: "Free access",
        accessType: "free" as const,
        quality: item.available_qualities?.[0] ?? "HD",
        price: 0,
        currency: item.currency || "USD",
      }]
    : [];
  const currentYear = new Date().getFullYear();
  const year = item.release_year ?? currentYear;

  return {
    id: item.slug,
    title: item.title,
    originalTitle: item.original_title,
    year,
    genres: item.genres ?? [],
    country: item.country_name || item.country_code || "Unknown",
    rating: Number(item.imdb_rating ?? 0),
    platformRating: Number(item.platform_rating ?? 0),
    price: Number(item.lowest_price ?? 0),
    accessDuration: deriveAccessDuration(offers, Number(item.lowest_price ?? 0)),
    posterUrl: item.poster_url,
    backdropUrl: item.backdrop_url,
    heroDesktopUrl: item.hero_desktop_url ?? undefined,
    heroMobileUrl: item.hero_mobile_url ?? undefined,
    shortDescription: item.short_description ?? "",
    tagline: item.tagline ?? "",
    description: item.short_description ?? item.tagline ?? "",
    cast: [],
    crew: [],
    videos: [],
    trailerUrl: item.trailer_url ?? "",
    premiereEvent: item.premiere_event
      ? {
          id: String(item.premiere_event.id),
          title: item.premiere_event.title,
          startsAt: item.premiere_event.starts_at,
          endsAt: item.premiere_event.ends_at ?? undefined,
        }
      : undefined,
    isNew: Boolean(item.badges?.some((badge) => badge.slug === "new")) || year >= currentYear - 1,
    isTrending: Boolean(item.is_trending),
    isFeatured: Boolean(item.is_featured),
    isFree: Boolean(item.is_free),
    type: item.type,
    offers,
  };
}

function mapDetailToMovie(item: PublicContentDetail): Movie {
  const offers = (item.offers ?? []).map(mapOffer);
  const currentYear = new Date().getFullYear();
  const year = item.release_year ?? currentYear;

  return {
    id: item.slug,
    title: item.title,
    originalTitle: item.original_title,
    year,
    genres: item.genres ?? [],
    country: item.country_name || item.country_code || "Unknown",
    rating: Number(item.imdb_rating ?? 0),
    platformRating: Number(item.platform_rating ?? 0),
    price: Number(item.lowest_price ?? 0),
    accessDuration: deriveAccessDuration(offers, Number(item.lowest_price ?? 0)),
    posterUrl: item.poster_url,
    backdropUrl: item.backdrop_url,
    heroDesktopUrl: item.hero_desktop_url ?? undefined,
    heroMobileUrl: item.hero_mobile_url ?? undefined,
    shortDescription: item.short_description ?? "",
    tagline: item.tagline ?? "",
    description: item.description ?? item.short_description ?? "",
    cast: (item.cast ?? []).map((member) => ({
      id: member.id,
      name: member.name,
      role: member.role,
      avatarUrl: member.avatar_url || item.poster_url,
    })),
    crew: (item.crew ?? []).map((member) => ({
      id: member.id,
      name: member.name,
      job: member.job,
      avatarUrl: member.avatar_url || item.poster_url,
    })),
    videos: (item.videos ?? []).map((video) => ({
      id: video.id,
      type: video.type,
      title: video.title,
      videoUrl: video.video_url,
      thumbnailUrl: video.thumbnail_url || item.backdrop_url,
      durationSeconds: video.duration_seconds ?? undefined,
      isPrimary: Boolean(video.is_primary),
    })),
    trailerUrl: item.trailer_url ?? item.videos?.find((video) => video.is_primary)?.video_url ?? "",
    premiereEvent: item.premiere_event
      ? {
          id: String(item.premiere_event.id),
          title: item.premiere_event.title,
          startsAt: item.premiere_event.starts_at,
          endsAt: item.premiere_event.ends_at ?? undefined,
        }
      : undefined,
    isNew: Boolean(item.badges?.some((badge) => badge.slug === "new")) || year >= currentYear - 1,
    isTrending: Boolean(item.is_trending),
    isFeatured: Boolean(item.is_featured),
    isFree: Boolean(item.is_free),
    type: item.type,
    seasons: item.seasons_count ?? item.seasons?.length ?? 0,
    episodes: item.episodes_count ?? item.seasons?.reduce((sum, season) => sum + (season.episodes?.length ?? 0), 0) ?? 0,
    seasonsData: (item.seasons ?? []).map((season) => ({
      id: season.id,
      seasonNumber: season.season_number,
      title: season.title ?? undefined,
      description: season.description ?? undefined,
      posterUrl: season.poster_url ?? undefined,
      episodes: (season.episodes ?? []).map((episode) => ({
        id: episode.id,
        episodeNumber: episode.episode_number,
        title: episode.title,
        description: episode.description ?? undefined,
        runtimeMinutes: episode.runtime_minutes ?? undefined,
        thumbnailUrl: episode.thumbnail_url ?? undefined,
        backdropUrl: episode.backdrop_url ?? undefined,
        videoUrl: episode.video_url ?? undefined,
        trailerUrl: episode.trailer_url ?? undefined,
      })),
    })),
    offers,
  };
}

export async function getHomeSections(locale: LocaleCode): Promise<HomeSections> {
  const response = await fetchJson<HomeResponse>("/public/home", { locale });

  return {
    hero: response.hero ? mapCardToMovie(response.hero) : null,
    heroSlides: (response.hero_slides ?? []).map((slide) => ({
      id: slide.id,
      desktopImageUrl: slide.desktop_image_url,
      mobileImageUrl: slide.mobile_image_url ?? undefined,
      eyebrow: slide.eyebrow ?? undefined,
      title: slide.title,
      description: slide.description ?? undefined,
      primaryCtaLabel: slide.primary_cta_label ?? undefined,
      secondaryCtaLabel: slide.secondary_cta_label ?? undefined,
      content: mapCardToMovie(slide.content),
    })),
    sections: (response.sections ?? []).map((section) => ({
      id: section.id,
      name: section.name,
      title: section.title,
      subtitle: section.subtitle ?? undefined,
      sourceMode: section.source_mode ?? undefined,
      items: (section.items ?? []).map(mapCardToMovie),
    })),
    featured: (response.featured ?? []).map(mapCardToMovie),
    freeToWatch: (response.free_to_watch ?? []).map(mapCardToMovie),
    latest: (response.latest ?? []).map(mapCardToMovie),
    movies: (response.movies ?? []).map(mapCardToMovie),
    series: (response.series ?? []).map(mapCardToMovie),
  };
}

export async function getCatalogPage(locale: LocaleCode, query: CatalogQuery = {}): Promise<CatalogPageResult> {
  const response = await fetchJson<CatalogResponse>("/public/catalog", {
    locale,
    query: query.query,
    type: query.type,
    genre: query.genre,
    access: query.access,
    year: query.year,
    country: query.country,
    min_rating: query.minRating,
    page: query.page ?? 1,
    page_size: query.pageSize ?? DEFAULT_PAGE_SIZE,
  });

  return {
    items: (response.items ?? []).map(mapCardToMovie),
    page: response.page ?? 1,
    pageSize: response.page_size ?? DEFAULT_PAGE_SIZE,
    total: response.total ?? 0,
    filters: {
      genres: response.filters?.genres ?? [],
      years: response.filters?.years ?? [],
      countries: response.filters?.countries ?? [],
      types: response.filters?.types ?? [],
      access: response.filters?.access ?? [],
    },
    searchEngine: response.search_engine ?? "database",
  };
}

export async function getFullCatalog(locale: LocaleCode, query: Omit<CatalogQuery, "page" | "pageSize"> = {}): Promise<Movie[]> {
  const items: Movie[] = [];
  let page = 1;
  let total = Number.POSITIVE_INFINITY;

  do {
    const response = await getCatalogPage(locale, {
      ...query,
      page,
      pageSize: DEFAULT_PAGE_SIZE,
    });
    const pageItems = response.items ?? [];

    items.push(...pageItems);
    total = Number.isFinite(response.total) && response.total > 0 ? response.total : items.length;

    if (pageItems.length === 0 || page >= Math.ceil(total / DEFAULT_PAGE_SIZE)) {
      break;
    }

    page += 1;
  } while (true);

  return items;
}

export async function getContentDetail(locale: LocaleCode, slug: string): Promise<Movie> {
  const response = await fetchJson<PublicContentDetail>(`/public/content/${slug}`, { locale });
  return mapDetailToMovie(response);
}

export async function fetchContentReviews(slug: string): Promise<PublicReviewsResponse> {
  return fetchJson<PublicReviewsResponse>(`/public/content/${slug}/reviews`);
}
