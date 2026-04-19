import React, { useEffect, useMemo, useState } from "react";
import {
  ArrowLeftIcon,
  PlusIcon,
  SaveIcon,
  TrashIcon,
} from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { FormField } from "../components/shared/FormField";
import { ImageUploadField } from "../components/shared/ImageUploadField";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../components/ui/tabs";
import { useAdmin } from "../hooks/useAdmin";
import { ApiRequestError, adminApi } from "../lib/api";
import { cn } from "../lib/utils";
import {
  AdminContent,
  AdminContentCastMember,
  AdminContentCrewMember,
  AdminContentEpisode,
  AdminContentFormat,
  AdminPremiereEvent,
  AdminOffer,
  AdminOfferType,
  AdminRightsWindow,
  AdminContentSeason,
  AdminContentOptions,
  AdminContentStatus,
  AdminSubtitleTrack,
  AdminContentTaxonomyOption,
  AdminContentType,
  AdminContentVideo,
  ContentPayload,
  LocalizedText,
  TaxonomyLocale,
} from "../types";

type ContentFormState = {
  type: AdminContentType;
  slug: string;
  default_locale: TaxonomyLocale;
  status: AdminContentStatus;
  original_title: string;
  title: LocalizedText;
  tagline: LocalizedText;
  short_description: LocalizedText;
  description: LocalizedText;
  editor_notes: LocalizedText;
  meta_title: LocalizedText;
  meta_description: LocalizedText;
  release_year: number | "";
  country_code: string;
  imdb_rating: number | "";
  platform_rating: number | "";
  runtime_minutes: number | "";
  age_rating: string;
  poster_url: string;
  backdrop_url: string;
  hero_desktop_url: string;
  hero_mobile_url: string;
  trailer_url: string;
  preview_images: string[];
  cast_members: AdminContentCastMember[];
  crew_members: AdminContentCrewMember[];
  videos: AdminContentVideo[];
  seasons: AdminContentSeason[];
  subtitle_locales: TaxonomyLocale[];
  available_qualities: string[];
  is_featured: boolean;
  is_trending: boolean;
  is_free: boolean;
  price_amount: number | "";
  currency: string;
  rental_days: number | "";
  sort_order: number | "";
  canonical_url: string;
  taxonomy_ids: number[];
};

type OfferFormState = {
  local_id: string;
  id?: number;
  name: string;
  offer_type: AdminOfferType;
  quality: string;
  currency: string;
  price_amount: number | "";
  playback_url: string;
  rental_days: number | "";
  is_active: boolean;
  starts_at: string;
  ends_at: string;
  sort_order: number | "";
};

type ContentFormatFormState = {
  local_id: string;
  id?: number;
  quality: string;
  format_type: "main" | "trailer";
  bunny_library_id: string;
  bunny_video_id: string;
  stream_url: string;
  token_path: string;
  drm_policy: string;
  is_active: boolean;
  is_default: boolean;
  sort_order: number | "";
};

type RightsWindowFormState = {
  local_id: string;
  id?: number;
  content_format_quality: string;
  country_code: string;
  is_allowed: boolean;
  starts_at: string;
  ends_at: string;
};

type SubtitleTrackFormState = {
  local_id: string;
  id?: number;
  content_format_quality: string;
  locale: TaxonomyLocale;
  label: string;
  file_url: string;
  is_default: boolean;
  sort_order: number | "";
};

type PremiereEventFormState = {
  local_id: string;
  id?: number;
  title: string;
  starts_at: string;
  ends_at: string;
  is_active: boolean;
  is_public: boolean;
};

const FALLBACK_OPTIONS: AdminContentOptions = {
  locales: [
    { value: "ro", label: "RO" },
    { value: "ru", label: "RU" },
    { value: "en", label: "EN" },
  ],
  types: [
    { value: "movie", label: "Filme" },
    { value: "series", label: "Seriale" },
  ],
  statuses: [
    { value: "draft", label: "Ciornă" },
    { value: "ready", label: "Pregătit" },
    { value: "published", label: "Publicat" },
    { value: "archived", label: "Arhivat" },
  ],
  countries: [],
  age_ratings: ["0+", "6+", "12+", "16+", "18+"],
  quality_options: ["SD", "HD", "Full HD", "4K"],
  offer_types: [
    { value: "free", label: "Gratuit" },
    { value: "rental", label: "Închiriere" },
    { value: "lifetime", label: "Permanent" },
  ],
  video_types: [
    { value: "trailer", label: "Trailer" },
    { value: "teaser", label: "Teaser" },
    { value: "clip", label: "Clip" },
    { value: "extra", label: "Extra" },
    { value: "behind_scenes", label: "Din culise" },
    { value: "interview", label: "Interviu" },
  ],
  cast_credit_types: [
    { value: "lead_actor", label: "Actor principal" },
    { value: "supporting_actor", label: "Actor secundar" },
    { value: "voice_actor", label: "Actor voice-over" },
    { value: "guest_star", label: "Invitat special" },
    { value: "cameo", label: "Cameo" },
  ],
  crew_credit_types: [
    { value: "director", label: "Regizor" },
    { value: "screenwriter", label: "Scenarist" },
    { value: "producer", label: "Producător" },
    { value: "creator", label: "Creator" },
    { value: "showrunner", label: "Showrunner" },
    { value: "cinematographer", label: "Director de imagine" },
    { value: "composer", label: "Compozitor" },
    { value: "editor", label: "Montaj" },
  ],
  format_types: [
    { value: "main", label: "Main" },
    { value: "trailer", label: "Trailer" },
  ],
  taxonomies: {
    genre: [],
    collection: [],
    tag: [],
    badge: [],
  },
};

function createEmptyLocalizedText(): LocalizedText {
  return {
    ro: "",
    ru: "",
    en: "",
  };
}

function createEmptyFormState(): ContentFormState {
  return {
    type: "movie",
    slug: "",
    default_locale: "ro",
    status: "draft",
    original_title: "",
    title: createEmptyLocalizedText(),
    tagline: createEmptyLocalizedText(),
    short_description: createEmptyLocalizedText(),
    description: createEmptyLocalizedText(),
    editor_notes: createEmptyLocalizedText(),
    meta_title: createEmptyLocalizedText(),
    meta_description: createEmptyLocalizedText(),
    release_year: "",
    country_code: "",
    imdb_rating: "",
    platform_rating: "",
    runtime_minutes: "",
    age_rating: "",
    poster_url: "",
    backdrop_url: "",
    hero_desktop_url: "",
    hero_mobile_url: "",
    trailer_url: "",
    preview_images: [],
    cast_members: [],
    crew_members: [],
    videos: [],
    seasons: [],
    subtitle_locales: ["ro"],
    available_qualities: ["HD"],
    is_featured: false,
    is_trending: false,
    is_free: false,
    price_amount: "",
    currency: "USD",
    rental_days: 2,
    sort_order: 0,
    canonical_url: "",
    taxonomy_ids: [],
  };
}

function createEmptyCastMember(): AdminContentCastMember {
  return {
    id: crypto.randomUUID(),
    name: "",
    credit_type: "lead_actor",
    character_name: createEmptyLocalizedText(),
    avatar_url: null,
    sort_order: 0,
  };
}

function createEmptyCrewMember(): AdminContentCrewMember {
  return {
    id: crypto.randomUUID(),
    name: "",
    credit_type: "director",
    job_title: createEmptyLocalizedText(),
    avatar_url: null,
    sort_order: 0,
  };
}

function createEmptyVideo(): AdminContentVideo {
  return {
    id: crypto.randomUUID(),
    type: "trailer",
    title: createEmptyLocalizedText(),
    description: createEmptyLocalizedText(),
    video_url: "",
    thumbnail_url: null,
    duration_seconds: null,
    is_primary: false,
    sort_order: 0,
  };
}

function createEmptyEpisode(): AdminContentEpisode {
  return {
    id: crypto.randomUUID(),
    episode_number: 1,
    title: createEmptyLocalizedText(),
    description: createEmptyLocalizedText(),
    runtime_minutes: null,
    thumbnail_url: null,
    backdrop_url: null,
    video_url: null,
    trailer_url: null,
    sort_order: 0,
  };
}

function createEmptySeason(): AdminContentSeason {
  return {
    id: crypto.randomUUID(),
    season_number: 1,
    title: createEmptyLocalizedText(),
    description: createEmptyLocalizedText(),
    poster_url: null,
    sort_order: 0,
    episodes: [],
  };
}

function mapContentFormatToForm(format: AdminContentFormat): ContentFormatFormState {
  return {
    local_id: `format-${format.id}`,
    id: format.id,
    quality: format.quality,
    format_type: format.format_type,
    bunny_library_id: format.bunny_library_id,
    bunny_video_id: format.bunny_video_id,
    stream_url: format.stream_url ?? "",
    token_path: format.token_path ?? "",
    drm_policy: format.drm_policy,
    is_active: format.is_active,
    is_default: format.is_default,
    sort_order: format.sort_order,
  };
}

function createEmptyContentFormat(options: AdminContentOptions): ContentFormatFormState {
  return {
    local_id: crypto.randomUUID(),
    quality: options.quality_options[0] ?? "HD",
    format_type: "main",
    bunny_library_id: "",
    bunny_video_id: "",
    stream_url: "",
    token_path: "",
    drm_policy: "tokenized",
    is_active: true,
    is_default: false,
    sort_order: 0,
  };
}

function mapRightsWindowToForm(item: AdminRightsWindow): RightsWindowFormState {
  return {
    local_id: `rights-${item.id}`,
    id: item.id,
    content_format_quality: item.content_format_quality ?? "",
    country_code: item.country_code ?? "",
    is_allowed: item.is_allowed,
    starts_at: item.starts_at ? item.starts_at.slice(0, 10) : "",
    ends_at: item.ends_at ? item.ends_at.slice(0, 10) : "",
  };
}

function createEmptyRightsWindow(): RightsWindowFormState {
  return {
    local_id: crypto.randomUUID(),
    content_format_quality: "",
    country_code: "",
    is_allowed: true,
    starts_at: "",
    ends_at: "",
  };
}

function mapSubtitleTrackToForm(item: AdminSubtitleTrack): SubtitleTrackFormState {
  return {
    local_id: `subtitle-${item.id}`,
    id: item.id,
    content_format_quality: item.content_format_quality ?? "",
    locale: item.locale,
    label: item.label,
    file_url: item.file_url,
    is_default: item.is_default,
    sort_order: item.sort_order,
  };
}

function createEmptySubtitleTrack(): SubtitleTrackFormState {
  return {
    local_id: crypto.randomUUID(),
    content_format_quality: "",
    locale: "ro",
    label: "",
    file_url: "",
    is_default: false,
    sort_order: 0,
  };
}

function mapPremiereEventToForm(item: AdminPremiereEvent): PremiereEventFormState {
  return {
    local_id: `premiere-${item.id}`,
    id: item.id,
    title: item.title,
    starts_at: item.starts_at ? item.starts_at.slice(0, 16) : "",
    ends_at: item.ends_at ? item.ends_at.slice(0, 16) : "",
    is_active: item.is_active,
    is_public: item.is_public,
  };
}

function createEmptyPremiereEvent(): PremiereEventFormState {
  return {
    local_id: crypto.randomUUID(),
    title: "",
    starts_at: "",
    ends_at: "",
    is_active: true,
    is_public: true,
  };
}

function ensureLocalizedText(value?: LocalizedText | string | null): LocalizedText {
  if (!value) {
    return createEmptyLocalizedText();
  }

  if (typeof value === "string") {
    return {
      ro: value,
      ru: value,
      en: value,
    };
  }

  return {
    ro: value.ro ?? "",
    ru: value.ru ?? "",
    en: value.en ?? "",
  };
}

function mapOfferToForm(offer: AdminOffer): OfferFormState {
  return {
    local_id: `offer-${offer.id}`,
    id: offer.id,
    name: offer.name,
    offer_type: offer.offer_type,
    quality: offer.quality,
    currency: offer.currency,
    price_amount: offer.offer_type === "free" ? "" : offer.price_amount,
    playback_url: offer.playback_url ?? "",
    rental_days: offer.rental_days ?? "",
    is_active: offer.is_active,
    starts_at: offer.starts_at ? offer.starts_at.slice(0, 10) : "",
    ends_at: offer.ends_at ? offer.ends_at.slice(0, 10) : "",
    sort_order: offer.sort_order,
  };
}

function createEmptyOfferForm(options: AdminContentOptions): OfferFormState {
  return {
    local_id: crypto.randomUUID(),
    name: "",
    offer_type: "rental",
    quality: options.quality_options[0] ?? "HD",
    currency: "USD",
    price_amount: "",
    playback_url: "",
    rental_days: 2,
    is_active: true,
    starts_at: "",
    ends_at: "",
    sort_order: 0,
  };
}

function slugify(value: string) {
  return value
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 80);
}

function mapContentToForm(content: AdminContent): ContentFormState {
  return {
    type: content.type,
    slug: content.slug,
    default_locale: content.default_locale,
    status: content.status,
    original_title: content.original_title,
    title: { ...content.title },
    tagline: { ...content.tagline },
    short_description: { ...content.short_description },
    description: { ...content.description },
    editor_notes: { ...content.editor_notes },
    meta_title: { ...content.meta_title },
    meta_description: { ...content.meta_description },
    release_year: content.release_year ?? "",
    country_code: content.country_code ?? "",
    imdb_rating: content.imdb_rating ?? "",
    platform_rating: content.platform_rating ?? "",
    runtime_minutes: content.runtime_minutes ?? "",
    age_rating: content.age_rating ?? "",
    poster_url: content.poster_url,
    backdrop_url: content.backdrop_url,
    hero_desktop_url: content.hero_desktop_url ?? "",
    hero_mobile_url: content.hero_mobile_url ?? "",
    trailer_url: content.trailer_url ?? "",
    preview_images: content.preview_images ?? [],
    cast_members: (content.cast ?? []).map((member) => ({
      ...member,
      character_name: ensureLocalizedText(member.character_name),
    })),
    crew_members: (content.crew ?? []).map((member) => ({
      ...member,
      job_title: ensureLocalizedText(member.job_title),
    })),
    videos: (content.videos ?? []).map((video) => ({
      ...video,
      title: ensureLocalizedText(video.title),
      description: ensureLocalizedText(video.description),
    })),
    seasons: (content.seasons ?? []).map((season) => ({
      ...season,
      title: season.title ? ensureLocalizedText(season.title) : createEmptyLocalizedText(),
      description: season.description ? ensureLocalizedText(season.description) : createEmptyLocalizedText(),
      episodes: season.episodes.map((episode) => ({
        ...episode,
        title: ensureLocalizedText(episode.title),
        description: episode.description ? ensureLocalizedText(episode.description) : createEmptyLocalizedText(),
      })),
    })),
    subtitle_locales: content.subtitle_locales ?? [],
    available_qualities: content.available_qualities ?? [],
    is_featured: content.is_featured,
    is_trending: content.is_trending,
    is_free: content.is_free,
    price_amount: content.is_free ? "" : content.price_amount,
    currency: content.currency || "USD",
    rental_days: content.rental_days ?? 2,
    sort_order: content.sort_order ?? 0,
    canonical_url: content.canonical_url ?? "",
    taxonomy_ids: content.taxonomy_ids ?? [],
  };
}

function trimLocalizedText(value: LocalizedText): LocalizedText {
  return {
    ro: value.ro.trim(),
    ru: value.ru.trim(),
    en: value.en.trim(),
  };
}

function isLocalizedTextEmpty(value: LocalizedText | null | undefined) {
  if (!value) {
    return true;
  }

  return !value.ro.trim() && !value.ru.trim() && !value.en.trim();
}

function statusVariant(status: AdminContentStatus) {
  switch (status) {
    case "published":
      return "published";
    case "ready":
      return "ready";
    case "archived":
      return "archived";
    default:
      return "draft";
  }
}

function TaxonomyPicker({
  label,
  items,
  selectedIds,
  locale,
  onToggle,
}: {
  label: string;
  items: AdminContentTaxonomyOption[];
  selectedIds: number[];
  locale: TaxonomyLocale;
  onToggle: (id: number) => void;
}) {
  if (!items || items.length === 0) {
    return null;
  }

  return (
    <div className="space-y-3">
      <div className="text-sm font-medium">{label}</div>
      <div className="flex flex-wrap gap-2">
        {items.map((item) => {
          const isSelected = selectedIds.includes(item.id);
          const labelText = item.name[locale] || item.name.ro || item.slug;

          return (
            <button
              key={item.id}
              type="button"
              onClick={() => onToggle(item.id)}
              className={cn(
                "inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors",
                isSelected
                  ? "border-foreground bg-foreground text-background"
                  : "border-input bg-background hover:bg-accent hover:text-accent-foreground",
              )}
            >
              {item.type === "badge" && item.color ? (
                <span
                  className="h-2.5 w-2.5 rounded-full"
                  style={{ backgroundColor: item.color }}
                />
              ) : null}
              {labelText}
            </button>
          );
        })}
      </div>
    </div>
  );
}

export function ContentEditor() {
  const { currentUser, selectedContentId, navigate } = useAdmin();
  const isNew = selectedContentId === "new" || !selectedContentId;
  const numericContentId = !isNew && selectedContentId ? Number(selectedContentId) : null;
  const [editorTab, setEditorTab] = useState("general");
  const [localeTab, setLocaleTab] = useState<TaxonomyLocale>(currentUser?.preferred_locale ?? "ro");
  const [options, setOptions] = useState<AdminContentOptions>(FALLBACK_OPTIONS);
  const [formState, setFormState] = useState<ContentFormState>(createEmptyFormState);
  const [offerDrafts, setOfferDrafts] = useState<OfferFormState[]>([]);
  const [contentFormatDrafts, setContentFormatDrafts] = useState<ContentFormatFormState[]>([]);
  const [rightsWindowDrafts, setRightsWindowDrafts] = useState<RightsWindowFormState[]>([]);
  const [subtitleTrackDrafts, setSubtitleTrackDrafts] = useState<SubtitleTrackFormState[]>([]);
  const [premiereEventDrafts, setPremiereEventDrafts] = useState<PremiereEventFormState[]>([]);
  const [offerValidationErrors, setOfferValidationErrors] = useState<Record<string, Record<string, string[]>>>({});
  const [offerBusyKey, setOfferBusyKey] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [validationErrors, setValidationErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (currentUser?.preferred_locale) {
      setLocaleTab(currentUser.preferred_locale);
    }
  }, [currentUser?.preferred_locale]);

  async function loadEditor() {
    setIsLoading(true);
    setError(null);

    try {
      if (numericContentId) {
        const response = await adminApi.getContent(numericContentId);
        setOptions(response.options);
        setFormState(mapContentToForm(response.content));
        setOfferDrafts((response.content.offers ?? []).map((offer) => mapOfferToForm(offer)));
        setContentFormatDrafts((response.content.content_formats ?? []).map((item) => mapContentFormatToForm(item)));
        setRightsWindowDrafts((response.content.rights_windows ?? []).map((item) => mapRightsWindowToForm(item)));
        setSubtitleTrackDrafts((response.content.subtitle_tracks ?? []).map((item) => mapSubtitleTrackToForm(item)));
        setPremiereEventDrafts((response.content.premiere_events ?? []).map((item) => mapPremiereEventToForm(item)));
        setOfferValidationErrors({});
      } else {
        const response = await adminApi.getContentOptions();
        setOptions(response.options);
        setFormState((current) => ({
          ...createEmptyFormState(),
          default_locale: currentUser?.preferred_locale ?? "ro",
        }));
        setOfferDrafts([]);
        setContentFormatDrafts([]);
        setRightsWindowDrafts([]);
        setSubtitleTrackDrafts([]);
        setPremiereEventDrafts([]);
        setOfferValidationErrors({});
      }
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca editorul.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadEditor();
  }, [numericContentId]);

  const pageTitle = isNew
    ? "Creează titlu nou"
    : formState.title[formState.default_locale] || formState.original_title || "Editează titlul";

  const selectedTaxonomyCount = formState.taxonomy_ids.length;
  const previewImages = useMemo(() => formState.preview_images.filter(Boolean), [formState.preview_images]);

  function getFieldError(field: string) {
    return validationErrors[field]?.[0];
  }

  function updateLocalizedField(field: keyof Pick<
    ContentFormState,
    "title" | "tagline" | "short_description" | "description" | "editor_notes" | "meta_title" | "meta_description"
  >, locale: TaxonomyLocale, value: string) {
    setFormState((current) => {
      const next = {
        ...current,
        [field]: {
          ...current[field],
          [locale]: value,
        },
      };

      if (field === "title" && locale === current.default_locale && current.slug.trim().length === 0) {
        next.slug = slugify(value);
      }

      return next;
    });
  }

  function toggleSelection<T extends string | number>(list: T[], value: T) {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
  }

  function toggleTaxonomy(id: number) {
    setFormState((current) => ({
      ...current,
      taxonomy_ids: toggleSelection(current.taxonomy_ids, id),
    }));
  }

  function toggleQuality(quality: string) {
    setFormState((current) => ({
      ...current,
      available_qualities: toggleSelection(current.available_qualities, quality),
    }));
  }

  function toggleSubtitleLocale(locale: TaxonomyLocale) {
    setFormState((current) => ({
      ...current,
      subtitle_locales: toggleSelection(current.subtitle_locales, locale),
    }));
  }

  function updatePreviewImage(index: number, value: string) {
    setFormState((current) => ({
      ...current,
      preview_images: current.preview_images.map((image, imageIndex) =>
        imageIndex === index ? value : image,
      ),
    }));
  }

  function addPreviewImage() {
    setFormState((current) => ({
      ...current,
      preview_images: [...current.preview_images, ""],
    }));
  }

  function removePreviewImage(index: number) {
    setFormState((current) => ({
      ...current,
      preview_images: current.preview_images.filter((_, imageIndex) => imageIndex !== index),
    }));
  }

  function updateCastMember(index: number, field: keyof Omit<AdminContentCastMember, "character_name">, value: string | number | null) {
    setFormState((current) => ({
      ...current,
      cast_members: current.cast_members.map((member, memberIndex) =>
        memberIndex === index ? { ...member, [field]: value } : member,
      ),
    }));
  }

  function updateCastMemberLocalized(index: number, locale: TaxonomyLocale, value: string) {
    setFormState((current) => ({
      ...current,
      cast_members: current.cast_members.map((member, memberIndex) =>
        memberIndex === index
          ? {
              ...member,
              character_name: {
                ...member.character_name,
                [locale]: value,
              },
            }
          : member,
      ),
    }));
  }

  function updateContentFormatDraft(localId: string, field: keyof ContentFormatFormState, value: string | number | boolean) {
    setContentFormatDrafts((current) =>
      current.map((item) => (item.local_id === localId ? { ...item, [field]: value } : item)),
    );
  }

  function updateRightsWindowDraft(localId: string, field: keyof RightsWindowFormState, value: string | boolean) {
    setRightsWindowDrafts((current) =>
      current.map((item) => (item.local_id === localId ? { ...item, [field]: value } : item)),
    );
  }

  function updateSubtitleTrackDraft(localId: string, field: keyof SubtitleTrackFormState, value: string | number | boolean) {
    setSubtitleTrackDrafts((current) =>
      current.map((item) => (item.local_id === localId ? { ...item, [field]: value } : item)),
    );
  }

  function updatePremiereEventDraft(localId: string, field: keyof PremiereEventFormState, value: string | boolean) {
    setPremiereEventDrafts((current) =>
      current.map((item) => (item.local_id === localId ? { ...item, [field]: value } : item)),
    );
  }

  function updateCrewMember(index: number, field: keyof Omit<AdminContentCrewMember, "job_title">, value: string | number | null) {
    setFormState((current) => ({
      ...current,
      crew_members: current.crew_members.map((member, memberIndex) =>
        memberIndex === index ? { ...member, [field]: value } : member,
      ),
    }));
  }

  function updateCrewMemberLocalized(index: number, locale: TaxonomyLocale, value: string) {
    setFormState((current) => ({
      ...current,
      crew_members: current.crew_members.map((member, memberIndex) =>
        memberIndex === index
          ? {
              ...member,
              job_title: {
                ...member.job_title,
                [locale]: value,
              },
            }
          : member,
      ),
    }));
  }

  function updateVideo(index: number, field: keyof Omit<AdminContentVideo, "title" | "description">, value: string | number | boolean | null) {
    setFormState((current) => ({
      ...current,
      videos: current.videos.map((video, videoIndex) =>
        videoIndex === index ? { ...video, [field]: value } : video,
      ),
    }));
  }

  function updateVideoLocalized(index: number, field: "title" | "description", locale: TaxonomyLocale, value: string) {
    setFormState((current) => ({
      ...current,
      videos: current.videos.map((video, videoIndex) =>
        videoIndex === index
          ? {
              ...video,
              [field]: {
                ...(video[field] ?? createEmptyLocalizedText()),
                [locale]: value,
              },
            }
          : video,
      ),
    }));
  }

  function setPrimaryVideo(index: number) {
    setFormState((current) => ({
      ...current,
      videos: current.videos.map((video, videoIndex) => ({
        ...video,
        is_primary: videoIndex === index,
      })),
    }));
  }

  function updateSeason(index: number, field: keyof Omit<AdminContentSeason, "title" | "description" | "episodes">, value: string | number | null) {
    setFormState((current) => ({
      ...current,
      seasons: current.seasons.map((season, seasonIndex) =>
        seasonIndex === index ? { ...season, [field]: value } : season,
      ),
    }));
  }

  function updateSeasonLocalized(index: number, field: "title" | "description", locale: TaxonomyLocale, value: string) {
    setFormState((current) => ({
      ...current,
      seasons: current.seasons.map((season, seasonIndex) =>
        seasonIndex === index
          ? {
              ...season,
              [field]: {
                ...(season[field] ?? createEmptyLocalizedText()),
                [locale]: value,
              },
            }
          : season,
      ),
    }));
  }

  function updateEpisode(
    seasonIndex: number,
    episodeIndex: number,
    field: keyof Omit<AdminContentEpisode, "title" | "description">,
    value: string | number | null,
  ) {
    setFormState((current) => ({
      ...current,
      seasons: current.seasons.map((season, currentSeasonIndex) => {
        if (currentSeasonIndex !== seasonIndex) {
          return season;
        }

        return {
          ...season,
          episodes: season.episodes.map((episode, currentEpisodeIndex) =>
            currentEpisodeIndex === episodeIndex ? { ...episode, [field]: value } : episode,
          ),
        };
      }),
    }));
  }

  function updateEpisodeLocalized(
    seasonIndex: number,
    episodeIndex: number,
    field: "title" | "description",
    locale: TaxonomyLocale,
    value: string,
  ) {
    setFormState((current) => ({
      ...current,
      seasons: current.seasons.map((season, currentSeasonIndex) => {
        if (currentSeasonIndex !== seasonIndex) {
          return season;
        }

        return {
          ...season,
          episodes: season.episodes.map((episode, currentEpisodeIndex) =>
            currentEpisodeIndex === episodeIndex
              ? {
                  ...episode,
                  [field]: {
                    ...(episode[field] ?? createEmptyLocalizedText()),
                    [locale]: value,
                  },
                }
              : episode,
          ),
        };
      }),
    }));
  }

  function updateOfferDraft(localId: string, field: keyof OfferFormState, value: string | number | boolean) {
    setOfferDrafts((current) =>
      current.map((offer) => (offer.local_id === localId ? { ...offer, [field]: value } : offer)),
    );
  }

  function getOfferFieldError(localId: string, field: string) {
    return offerValidationErrors[localId]?.[field]?.[0];
  }

  function validateForm() {
    const nextErrors: Record<string, string[]> = {};

    if (!formState.slug.trim()) {
      nextErrors.slug = ["Slug-ul este obligatoriu."];
    }

    if (!formState.original_title.trim()) {
      nextErrors.original_title = ["Titlul original este obligatoriu."];
    }

    if (!formState.poster_url.trim()) {
      nextErrors.poster_url = ["URL-ul posterului este obligatoriu."];
    }

    if (!formState.backdrop_url.trim()) {
      nextErrors.backdrop_url = ["URL-ul backdrop-ului este obligatoriu."];
    }

    if (formState.available_qualities.length === 0) {
      nextErrors.available_qualities = ["Selectează cel puțin o calitate."];
    }

    for (const locale of options.locales) {
      if (!formState.title[locale.value].trim()) {
        nextErrors[`title.${locale.value}`] = ["Titlul este obligatoriu."];
      }

      if (!formState.short_description[locale.value].trim()) {
        nextErrors[`short_description.${locale.value}`] = ["Descrierea scurtă este obligatorie."];
      }

      if (!formState.description[locale.value].trim()) {
        nextErrors[`description.${locale.value}`] = ["Descrierea este obligatorie."];
      }
    }

    return nextErrors;
  }

  function payloadFromForm(): ContentPayload {
    return {
      type: formState.type,
      slug: formState.slug.trim(),
      default_locale: formState.default_locale,
      status: formState.status,
      original_title: formState.original_title.trim(),
      title: {
        ro: formState.title.ro.trim(),
        ru: formState.title.ru.trim(),
        en: formState.title.en.trim(),
      },
      tagline: {
        ro: formState.tagline.ro.trim(),
        ru: formState.tagline.ru.trim(),
        en: formState.tagline.en.trim(),
      },
      short_description: {
        ro: formState.short_description.ro.trim(),
        ru: formState.short_description.ru.trim(),
        en: formState.short_description.en.trim(),
      },
      description: {
        ro: formState.description.ro.trim(),
        ru: formState.description.ru.trim(),
        en: formState.description.en.trim(),
      },
      editor_notes: {
        ro: formState.editor_notes.ro.trim(),
        ru: formState.editor_notes.ru.trim(),
        en: formState.editor_notes.en.trim(),
      },
      meta_title: {
        ro: formState.meta_title.ro.trim(),
        ru: formState.meta_title.ru.trim(),
        en: formState.meta_title.en.trim(),
      },
      meta_description: {
        ro: formState.meta_description.ro.trim(),
        ru: formState.meta_description.ru.trim(),
        en: formState.meta_description.en.trim(),
      },
      release_year: formState.release_year === "" ? null : Number(formState.release_year),
      country_code: formState.country_code || null,
      imdb_rating: formState.imdb_rating === "" ? null : Number(formState.imdb_rating),
      platform_rating: formState.platform_rating === "" ? null : Number(formState.platform_rating),
      runtime_minutes: formState.runtime_minutes === "" ? null : Number(formState.runtime_minutes),
      age_rating: formState.age_rating || null,
      poster_url: formState.poster_url.trim(),
      backdrop_url: formState.backdrop_url.trim(),
      hero_desktop_url: formState.hero_desktop_url.trim() || null,
      hero_mobile_url: formState.hero_mobile_url.trim() || null,
      trailer_url: formState.trailer_url.trim() || null,
      preview_images: formState.preview_images.map((item) => item.trim()).filter(Boolean),
      cast_members: formState.cast_members
        .filter((member) => member.name.trim() && !isLocalizedTextEmpty(member.character_name))
        .map((member, index) => ({
          id: member.id,
          name: member.name.trim(),
          credit_type: member.credit_type,
          character_name: trimLocalizedText(member.character_name),
          avatar_url: member.avatar_url?.trim() || null,
          sort_order: member.sort_order ?? index,
        })),
      crew_members: formState.crew_members
        .filter((member) => member.name.trim() && !isLocalizedTextEmpty(member.job_title))
        .map((member, index) => ({
          id: member.id,
          name: member.name.trim(),
          credit_type: member.credit_type,
          job_title: trimLocalizedText(member.job_title),
          avatar_url: member.avatar_url?.trim() || null,
          sort_order: member.sort_order ?? index,
        })),
      videos: formState.videos
        .filter((video) => !isLocalizedTextEmpty(video.title) && video.video_url.trim())
        .map((video, index) => ({
          id: video.id,
          type: video.type,
          title: trimLocalizedText(video.title),
          description: isLocalizedTextEmpty(video.description) ? null : trimLocalizedText(video.description),
          video_url: video.video_url.trim(),
          thumbnail_url: video.thumbnail_url?.trim() || null,
          duration_seconds: video.duration_seconds ?? null,
          is_primary: Boolean(video.is_primary),
          sort_order: video.sort_order ?? index,
        })),
      seasons: formState.seasons
        .filter((season) => season.season_number > 0)
        .map((season, seasonIndex) => ({
          id: season.id,
          season_number: season.season_number,
          title: isLocalizedTextEmpty(season.title) ? null : trimLocalizedText(season.title),
          description: isLocalizedTextEmpty(season.description) ? null : trimLocalizedText(season.description),
          poster_url: season.poster_url?.trim() || null,
          sort_order: season.sort_order ?? seasonIndex,
          episodes: season.episodes
            .filter((episode) => !isLocalizedTextEmpty(episode.title))
            .map((episode, episodeIndex) => ({
              id: episode.id,
              episode_number: episode.episode_number,
              title: trimLocalizedText(episode.title),
              description: isLocalizedTextEmpty(episode.description) ? null : trimLocalizedText(episode.description),
              runtime_minutes: episode.runtime_minutes ?? null,
              thumbnail_url: episode.thumbnail_url?.trim() || null,
              backdrop_url: episode.backdrop_url?.trim() || null,
              video_url: episode.video_url?.trim() || null,
              trailer_url: episode.trailer_url?.trim() || null,
              sort_order: episode.sort_order ?? episodeIndex,
            })),
        })),
      subtitle_locales: formState.subtitle_locales,
      content_formats: contentFormatDrafts
        .filter((item) => item.bunny_library_id.trim() && item.bunny_video_id.trim())
        .map((item, index) => ({
          id: item.id,
          quality: item.quality,
          format_type: item.format_type,
          bunny_library_id: item.bunny_library_id.trim(),
          bunny_video_id: item.bunny_video_id.trim(),
          stream_url: item.stream_url.trim() || null,
          token_path: item.token_path.trim() || null,
          drm_policy: item.drm_policy.trim() || "tokenized",
          is_active: item.is_active,
          is_default: item.is_default,
          sort_order: item.sort_order === "" ? index : Number(item.sort_order),
          meta: {},
        })),
      rights_windows: rightsWindowDrafts
        .filter((item) => item.country_code.trim() || item.content_format_quality.trim())
        .map((item) => ({
          id: item.id,
          content_format_quality: item.content_format_quality.trim() || null,
          country_code: item.country_code.trim() || null,
          is_allowed: item.is_allowed,
          starts_at: item.starts_at || null,
          ends_at: item.ends_at || null,
          meta: {},
        })),
      subtitle_tracks: subtitleTrackDrafts
        .filter((item) => item.label.trim() && item.file_url.trim())
        .map((item, index) => ({
          id: item.id,
          content_format_quality: item.content_format_quality.trim() || null,
          locale: item.locale,
          label: item.label.trim(),
          file_url: item.file_url.trim(),
          is_default: item.is_default,
          sort_order: item.sort_order === "" ? index : Number(item.sort_order),
        })),
      premiere_events: premiereEventDrafts
        .filter((item) => item.title.trim() && item.starts_at)
        .map((item) => ({
          id: item.id,
          title: item.title.trim(),
          starts_at: item.starts_at,
          ends_at: item.ends_at || null,
          is_active: item.is_active,
          is_public: item.is_public,
          meta: {},
        })),
      available_qualities: formState.available_qualities,
      is_featured: formState.is_featured,
      is_trending: formState.is_trending,
      is_free: formState.is_free,
      price_amount: formState.is_free ? 0 : Number(formState.price_amount),
      currency: formState.currency.toUpperCase() || "USD",
      rental_days: formState.is_free ? null : Number(formState.rental_days),
      sort_order: formState.sort_order === "" ? 0 : Number(formState.sort_order),
      canonical_url: formState.canonical_url.trim() || null,
      taxonomy_ids: formState.taxonomy_ids,
    };
  }

  async function handleSaveOffer(localId: string) {
    if (!numericContentId) {
      setError("Salvează mai întâi titlul și apoi adaugă ofertele.");
      return;
    }

    const currentOffer = offerDrafts.find((offer) => offer.local_id === localId);
    if (!currentOffer) {
      return;
    }

    const localErrors: Record<string, string[]> = {};
    if (currentOffer.offer_type !== "free" && currentOffer.price_amount === "") {
      localErrors.price_amount = ["Setează prețul pentru oferta plătită."];
    }
    if (currentOffer.offer_type === "rental" && currentOffer.rental_days === "") {
      localErrors.rental_days = ["Setează numărul de zile pentru rental."];
    }
    if (Object.keys(localErrors).length > 0) {
      setOfferValidationErrors((current) => ({
        ...current,
        [localId]: localErrors,
      }));
      return;
    }

    setOfferBusyKey(localId);
    setOfferValidationErrors((current) => ({ ...current, [localId]: {} }));
    setError(null);
    setSuccessMessage(null);

    try {
      const payload = {
        content_id: numericContentId,
        name: currentOffer.name.trim() || undefined,
        offer_type: currentOffer.offer_type,
        quality: currentOffer.quality,
        currency: currentOffer.currency.trim().toUpperCase() || "USD",
        price_amount: currentOffer.offer_type === "free" ? 0 : Number(currentOffer.price_amount || 0),
        playback_url: currentOffer.playback_url.trim() || null,
        rental_days: currentOffer.offer_type === "rental" ? Number(currentOffer.rental_days || 0) : null,
        is_active: currentOffer.is_active,
        starts_at: currentOffer.starts_at || null,
        ends_at: currentOffer.ends_at || null,
        sort_order: Number(currentOffer.sort_order || 0),
      };

      if (currentOffer.id) {
        await adminApi.updateOffer(currentOffer.id, payload);
        setSuccessMessage("Oferta a fost actualizată.");
      } else {
        await adminApi.createOffer(payload);
        setSuccessMessage("Oferta a fost creată.");
      }

      await loadEditor();
    } catch (saveError) {
      const apiError = saveError as ApiRequestError;
      setError(apiError.message ?? "Nu am putut salva oferta.");
      setOfferValidationErrors((current) => ({
        ...current,
        [localId]: apiError.errors ?? {},
      }));
    } finally {
      setOfferBusyKey(null);
    }
  }

  async function handleDeleteOffer(localId: string) {
    const currentOffer = offerDrafts.find((offer) => offer.local_id === localId);
    if (!currentOffer) {
      return;
    }

    if (!currentOffer.id) {
      setOfferDrafts((current) => current.filter((offer) => offer.local_id !== localId));
      setOfferValidationErrors((current) => {
        const next = { ...current };
        delete next[localId];
        return next;
      });
      return;
    }

    const confirmed = window.confirm(`Ștergi oferta "${currentOffer.name || currentOffer.quality}"?`);
    if (!confirmed) {
      return;
    }

    setOfferBusyKey(localId);
    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.deleteOffer(currentOffer.id);
      setSuccessMessage("Oferta a fost ștearsă.");
      await loadEditor();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Nu am putut șterge oferta.");
    } finally {
      setOfferBusyKey(null);
    }
  }

  async function handleSave() {
    const clientErrors = validateForm();
    if (Object.keys(clientErrors).length > 0) {
      setValidationErrors(clientErrors);
      setError("Completează câmpurile obligatorii pentru toate limbile și media minimă.");

      if (clientErrors["title.ro"] || clientErrors["short_description.ro"] || clientErrors["description.ro"]) {
        setLocaleTab("ro");
      } else if (clientErrors["title.ru"] || clientErrors["short_description.ru"] || clientErrors["description.ru"]) {
        setLocaleTab("ru");
      } else if (clientErrors["title.en"] || clientErrors["short_description.en"] || clientErrors["description.en"]) {
        setLocaleTab("en");
      }
      return;
    }

    setIsSubmitting(true);
    setError(null);
    setSuccessMessage(null);
    setValidationErrors({});

    try {
      const payload = payloadFromForm();
      const response = numericContentId
        ? await adminApi.updateContent(numericContentId, payload)
        : await adminApi.createContent(payload);

      setSuccessMessage("Titlul a fost salvat.");
      navigate("editor", String(response.content.id), ["Catalog", response.content.localized_title]);
      setFormState(mapContentToForm(response.content));
      setOfferDrafts((response.content.offers ?? []).map((offer) => mapOfferToForm(offer)));
      setContentFormatDrafts((response.content.content_formats ?? []).map((item) => mapContentFormatToForm(item)));
      setRightsWindowDrafts((response.content.rights_windows ?? []).map((item) => mapRightsWindowToForm(item)));
      setSubtitleTrackDrafts((response.content.subtitle_tracks ?? []).map((item) => mapSubtitleTrackToForm(item)));
      setPremiereEventDrafts((response.content.premiere_events ?? []).map((item) => mapPremiereEventToForm(item)));
    } catch (saveError) {
      const apiError = saveError as ApiRequestError;
      setValidationErrors(apiError.errors ?? {});
      setError(apiError.message ?? "Nu am putut salva titlul.");
    } finally {
      setIsSubmitting(false);
    }
  }

  if (isLoading) {
    return (
      <Card>
        <CardContent className="p-10 text-center text-sm text-muted-foreground">Se încarcă editorul...</CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6 pb-16">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div className="flex items-start gap-3">
          <Button variant="outline" size="icon" onClick={() => navigate("catalog")}>
            <ArrowLeftIcon className="h-4 w-4" />
          </Button>

          <div className="space-y-2">
            <div className="page-header">
              <h1 className="page-title">{pageTitle}</h1>
              <p className="page-description">
                Configurezi aceleași date de care storefront-ul are nevoie pentru cards, hero banners, search și pagina de detaliu.
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={statusVariant(formState.status)}>{formState.status}</Badge>
              <span className="text-sm text-muted-foreground">
                {selectedTaxonomyCount} taxonomii atașate
              </span>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap gap-3">
          <Button variant="outline" onClick={() => navigate("catalog")}>
            Înapoi la catalog
          </Button>
          <Button onClick={() => void handleSave()} disabled={isSubmitting}>
            <SaveIcon className="h-4 w-4" />
            {isSubmitting ? "Se salvează..." : "Salvează titlul"}
          </Button>
        </div>
      </div>

      {error ? (
        <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}

      {successMessage ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          {successMessage}
        </div>
      ) : null}

      <Card>
        <CardContent className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div className="text-sm font-medium">Limbă de editare</div>
            <div className="text-sm text-muted-foreground">
              Textele locale din videos, credits și seasons folosesc limba selectată aici.
            </div>
          </div>
          <Tabs value={localeTab} onValueChange={(value) => setLocaleTab(value as TaxonomyLocale)} className="w-full max-w-sm">
            <TabsList className="grid h-auto w-full grid-cols-3">
              {options.locales.map((locale) => (
                <TabsTrigger key={locale.value} value={locale.value}>
                  {locale.label}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>
        </CardContent>
      </Card>

      <Tabs value={editorTab} onValueChange={setEditorTab} className="space-y-4">
        <TabsList className="grid h-auto w-full grid-cols-2 gap-2 bg-transparent p-0 lg:grid-cols-9">
          <TabsTrigger value="general" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            General
          </TabsTrigger>
          <TabsTrigger value="localization" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Localizare
          </TabsTrigger>
          <TabsTrigger value="seo" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            SEO și note
          </TabsTrigger>
          <TabsTrigger value="media" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Media
          </TabsTrigger>
          <TabsTrigger value="playback" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Bunny & Rights
          </TabsTrigger>
          <TabsTrigger value="credits" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Distribuție
          </TabsTrigger>
          <TabsTrigger value="series" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Serii
          </TabsTrigger>
          <TabsTrigger value="publishing" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Publicare
          </TabsTrigger>
          <TabsTrigger value="commerce" className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground">
            Oferte
          </TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Date de bază</CardTitle>
              <CardDescription>Informațiile structurale și taxonomiile folosite în catalog și în filtre.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <FormField
                  label="Tip conținut"
                  type="select"
                  value={formState.type}
                  onChange={(event) => setFormState((current) => ({ ...current, type: event.target.value as AdminContentType }))}
                  options={options.types.map((item) => ({ label: item.label, value: item.value }))}
                />
                <FormField
                  label="Status"
                  type="select"
                  value={formState.status}
                  onChange={(event) => setFormState((current) => ({ ...current, status: event.target.value as AdminContentStatus }))}
                  options={options.statuses.map((item) => ({ label: item.label, value: item.value }))}
                />
                <FormField
                  label="Limbă implicită"
                  type="select"
                  value={formState.default_locale}
                  onChange={(event) => setFormState((current) => ({ ...current, default_locale: event.target.value as TaxonomyLocale }))}
                  options={options.locales.map((item) => ({ label: item.label, value: item.value }))}
                />
                <FormField
                  label="Slug"
                  value={formState.slug}
                  error={getFieldError("slug")}
                  onChange={(event) => setFormState((current) => ({ ...current, slug: slugify(event.target.value) }))}
                />
              </div>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <FormField
                  label="Titlu original"
                  value={formState.original_title}
                  error={getFieldError("original_title")}
                  onChange={(event) => setFormState((current) => ({ ...current, original_title: event.target.value }))}
                />
                <FormField
                  label="An lansare"
                  type="number"
                  value={formState.release_year}
                  onChange={(event) =>
                    setFormState((current) => ({
                      ...current,
                      release_year: event.target.value === "" ? "" : Number(event.target.value),
                    }))
                  }
                />
                <FormField
                  label="Țară"
                  type="select"
                  value={formState.country_code}
                  onChange={(event) => setFormState((current) => ({ ...current, country_code: event.target.value }))}
                  options={[{ label: "Selectează țara", value: "" }, ...options.countries]}
                />
                <FormField
                  label="IMDb rating"
                  type="number"
                  value={formState.imdb_rating}
                  onChange={(event) =>
                    setFormState((current) => ({
                      ...current,
                      imdb_rating: event.target.value === "" ? "" : Number(event.target.value),
                    }))
                  }
                />
                <FormField
                  label="Rating platformă"
                  type="number"
                  value={formState.platform_rating}
                  onChange={(event) =>
                    setFormState((current) => ({
                      ...current,
                      platform_rating: event.target.value === "" ? "" : Number(event.target.value),
                    }))
                  }
                />
                <FormField
                  label="Durată (minute)"
                  type="number"
                  value={formState.runtime_minutes}
                  onChange={(event) =>
                    setFormState((current) => ({
                      ...current,
                      runtime_minutes: event.target.value === "" ? "" : Number(event.target.value),
                    }))
                  }
                />
              </div>

              <div className="grid gap-4 md:grid-cols-3">
                <FormField
                  label="Restricție de vârstă"
                  type="select"
                  value={formState.age_rating}
                  onChange={(event) => setFormState((current) => ({ ...current, age_rating: event.target.value }))}
                  options={[
                    { label: "Selectează ratingul", value: "" },
                    ...options.age_ratings.map((value) => ({ label: value, value })),
                  ]}
                />
                <FormField
                  label="Ordine de sortare"
                  type="number"
                  value={formState.sort_order}
                  onChange={(event) =>
                    setFormState((current) => ({
                      ...current,
                      sort_order: event.target.value === "" ? "" : Number(event.target.value),
                    }))
                  }
                />
              </div>

              <div className="grid gap-6 xl:grid-cols-2">
                <TaxonomyPicker
                  label="Genuri"
                  items={options.taxonomies.genre ?? []}
                  selectedIds={formState.taxonomy_ids}
                  locale={localeTab}
                  onToggle={toggleTaxonomy}
                />
                <TaxonomyPicker
                  label="Colecții"
                  items={options.taxonomies.collection ?? []}
                  selectedIds={formState.taxonomy_ids}
                  locale={localeTab}
                  onToggle={toggleTaxonomy}
                />
                <TaxonomyPicker
                  label="Tag-uri"
                  items={options.taxonomies.tag ?? []}
                  selectedIds={formState.taxonomy_ids}
                  locale={localeTab}
                  onToggle={toggleTaxonomy}
                />
                <TaxonomyPicker
                  label="Badge-uri"
                  items={options.taxonomies.badge ?? []}
                  selectedIds={formState.taxonomy_ids}
                  locale={localeTab}
                  onToggle={toggleTaxonomy}
                />
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="localization" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Copy localizat pentru storefront</CardTitle>
              <CardDescription>Textele care intră în carduri, home hero și pagina de detaliu.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <Tabs value={localeTab} onValueChange={(value) => setLocaleTab(value as TaxonomyLocale)} className="space-y-6">
                <TabsList className="grid h-auto w-full grid-cols-3">
                  {options.locales.map((locale) => (
                    <TabsTrigger key={locale.value} value={locale.value}>
                      {locale.label}
                    </TabsTrigger>
                  ))}
                </TabsList>

                {options.locales.map((locale) => (
                  <TabsContent key={locale.value} value={locale.value} className="space-y-4">
                    <FormField
                      label={`Titlu (${locale.label})`}
                      value={formState.title[locale.value]}
                      error={getFieldError(`title.${locale.value}`)}
                      onChange={(event) => updateLocalizedField("title", locale.value, event.target.value)}
                    />
                    <FormField
                      label={`Tagline (${locale.label})`}
                      value={formState.tagline[locale.value]}
                      onChange={(event) => updateLocalizedField("tagline", locale.value, event.target.value)}
                    />
                    <FormField
                      label={`Descriere scurtă (${locale.label})`}
                      type="textarea"
                      rows={4}
                      value={formState.short_description[locale.value]}
                      error={getFieldError(`short_description.${locale.value}`)}
                      onChange={(event) => updateLocalizedField("short_description", locale.value, event.target.value)}
                    />
                    <FormField
                      label={`Descriere completă (${locale.label})`}
                      type="textarea"
                      rows={8}
                      value={formState.description[locale.value]}
                      error={getFieldError(`description.${locale.value}`)}
                      onChange={(event) => updateLocalizedField("description", locale.value, event.target.value)}
                    />
                  </TabsContent>
                ))}
              </Tabs>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="seo" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>SEO și note editoriale</CardTitle>
              <CardDescription>Metadate și note suplimentare pentru detaliu și indexare.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <Tabs value={localeTab} onValueChange={(value) => setLocaleTab(value as TaxonomyLocale)} className="space-y-6">
                <TabsList className="grid h-auto w-full grid-cols-3">
                  {options.locales.map((locale) => (
                    <TabsTrigger key={locale.value} value={locale.value}>
                      {locale.label}
                    </TabsTrigger>
                  ))}
                </TabsList>

                {options.locales.map((locale) => (
                  <TabsContent key={locale.value} value={locale.value} className="space-y-4">
                    <FormField
                      label={`Meta titlu (${locale.label})`}
                      value={formState.meta_title[locale.value]}
                      onChange={(event) => updateLocalizedField("meta_title", locale.value, event.target.value)}
                    />
                    <FormField
                      label={`Meta descriere (${locale.label})`}
                      type="textarea"
                      rows={4}
                      value={formState.meta_description[locale.value]}
                      onChange={(event) => updateLocalizedField("meta_description", locale.value, event.target.value)}
                    />
                    <FormField
                      label={`Note editoriale (${locale.label})`}
                      type="textarea"
                      rows={4}
                      value={formState.editor_notes[locale.value]}
                      onChange={(event) => updateLocalizedField("editor_notes", locale.value, event.target.value)}
                    />
                  </TabsContent>
                ))}
              </Tabs>

              <FormField
                label="Canonical URL"
                value={formState.canonical_url}
                onChange={(event) => setFormState((current) => ({ ...current, canonical_url: event.target.value }))}
              />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="media" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Asset-uri media</CardTitle>
              <CardDescription>
                Pentru imagini poți lipi URL sau încărca local cu preview. Video-urile rămân pe URL până legăm upload-ul media.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
              <div className="space-y-4">
                  <ImageUploadField
                    label="Poster"
                  value={formState.poster_url}
                  error={getFieldError("poster_url")}
                  previewLabel="Poster"
                  aspectClassName="aspect-[2/3]"
                  uploadDirectory="content/posters"
                  onChange={(value) => setFormState((current) => ({ ...current, poster_url: value }))}
                />
                <ImageUploadField
                  label="Backdrop"
                  value={formState.backdrop_url}
                  error={getFieldError("backdrop_url")}
                  previewLabel="Backdrop"
                  uploadDirectory="content/backdrops"
                  onChange={(value) => setFormState((current) => ({ ...current, backdrop_url: value }))}
                />
                <div className="grid gap-4 md:grid-cols-2">
                  <ImageUploadField
                    label="Hero desktop"
                    value={formState.hero_desktop_url}
                    previewLabel="Desktop"
                    uploadDirectory="content/heroes"
                    onChange={(value) =>
                      setFormState((current) => ({ ...current, hero_desktop_url: value }))
                    }
                  />
                  <ImageUploadField
                    label="Hero mobile"
                    value={formState.hero_mobile_url}
                    previewLabel="Mobile"
                    aspectClassName="aspect-[3/4]"
                    uploadDirectory="content/heroes"
                    onChange={(value) =>
                      setFormState((current) => ({ ...current, hero_mobile_url: value }))
                    }
                  />
                </div>
                <FormField
                  label="URL trailer"
                  value={formState.trailer_url}
                  onChange={(event) => setFormState((current) => ({ ...current, trailer_url: event.target.value }))}
                />
                <Card>
                  <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                    <div>
                      <CardTitle className="text-base">Gallery previews</CardTitle>
                      <CardDescription>Card-uri și detalii gallery pentru storefront.</CardDescription>
                    </div>
                    <Button type="button" variant="outline" onClick={addPreviewImage}>
                      <PlusIcon className="h-4 w-4" />
                      Adaugă imagine
                    </Button>
                  </CardHeader>
                  <CardContent className="space-y-4 pt-6">
                    {formState.preview_images.length === 0 ? (
                      <div className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                        Nu ai încă imagini secundare pentru galerie.
                      </div>
                    ) : null}
                    {formState.preview_images.map((image, index) => (
                      <div key={`${index}-${image}`} className="space-y-3 rounded-xl border p-4">
                        <div className="flex items-center justify-between">
                          <div className="text-sm font-medium">Imagine preview #{index + 1}</div>
                          <Button type="button" variant="ghost" size="icon" onClick={() => removePreviewImage(index)}>
                            <TrashIcon className="h-4 w-4" />
                          </Button>
                        </div>
                        <ImageUploadField
                          label={`Imagine preview ${index + 1}`}
                          value={image}
                          previewLabel={`Galerie ${index + 1}`}
                          uploadDirectory="content/previews"
                          onChange={(value) => updatePreviewImage(index, value)}
                        />
                      </div>
                    ))}
                  </CardContent>
                </Card>
              </div>

              <div className="space-y-4">
                <Card className="overflow-hidden">
                  <CardHeader className="border-b pb-4">
                    <CardTitle className="text-lg">Previzualizare vizuală</CardTitle>
                    <CardDescription>Verificare rapidă pentru poster și hero.</CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4 pt-6">
                    <div className="space-y-2">
                      <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Poster</div>
                      <div className="overflow-hidden rounded-lg border bg-muted">
                        {formState.poster_url ? (
                          <img src={formState.poster_url} alt="Poster preview" className="aspect-[2/3] w-full object-cover" />
                        ) : (
                          <div className="flex aspect-[2/3] items-center justify-center text-sm text-muted-foreground">
                            Previzualizare poster
                          </div>
                        )}
                      </div>
                    </div>
                    <div className="space-y-2">
                      <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Hero</div>
                      <div className="overflow-hidden rounded-lg border bg-muted">
                        {formState.hero_desktop_url || formState.backdrop_url ? (
                          <img
                            src={formState.hero_desktop_url || formState.backdrop_url}
                            alt="Hero preview"
                            className="aspect-video w-full object-cover"
                          />
                        ) : (
                          <div className="flex aspect-video items-center justify-center text-sm text-muted-foreground">
                            Previzualizare hero
                          </div>
                        )}
                      </div>
                    </div>
                    {previewImages.length > 0 ? (
                      <div className="grid grid-cols-2 gap-3">
                        {previewImages.slice(0, 4).map((image) => (
                          <div key={image} className="overflow-hidden rounded-md border bg-muted">
                            <img src={image} alt="Preview asset" className="aspect-video w-full object-cover" />
                          </div>
                        ))}
                      </div>
                    ) : null}
                  </CardContent>
                </Card>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle className="text-lg">Asset-uri video</CardTitle>
                <CardDescription>Trailere, teasere și extras. Pentru moment fiecare asset folosește doar URL.</CardDescription>
              </div>
              <Button
                variant="outline"
                onClick={() =>
                  setFormState((current) => ({
                    ...current,
                    videos: [
                      ...current.videos,
                      {
                        ...createEmptyVideo(),
                        sort_order: current.videos.length,
                      },
                    ],
                  }))
                }
              >
                <PlusIcon className="h-4 w-4" />
                Adaugă video
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {formState.videos.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Nu există încă video-uri. Poți adăuga `Trailer oficial`, `Teaser`, `Din culise` sau orice extra relevant pentru storefront.
                </div>
              ) : (
                formState.videos.map((video, index) => (
                  <Card key={video.id}>
                    <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                      <div>
                        <CardTitle className="text-base">
                          {video.title[localeTab] || video.title.ro || `Video #${index + 1}`}
                          {video.title[localeTab] || video.title.ro || `Video ${index + 1}`}
                        </CardTitle>
                        <CardDescription>{video.type}</CardDescription>
                      </div>
                      <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => setPrimaryVideo(index)}>
                          {video.is_primary ? "Principal" : "Setează principal"}
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() =>
                            setFormState((current) => ({
                              ...current,
                              videos: current.videos.filter((_, videoIndex) => videoIndex !== index),
                            }))
                          }
                        >
                          <TrashIcon className="h-4 w-4" />
                        </Button>
                      </div>
                    </CardHeader>
                    <CardContent className="grid gap-4 pt-6 md:grid-cols-2">
                      <FormField
                        label={`Titlu video (${localeTab.toUpperCase()})`}
                        value={video.title[localeTab]}
                        onChange={(event) => updateVideoLocalized(index, "title", localeTab, event.target.value)}
                      />
                      <FormField
                        label="Tip video"
                        type="select"
                        value={video.type}
                        onChange={(event) => updateVideo(index, "type", event.target.value as AdminContentVideo["type"])}
                        options={(options.video_types ?? FALLBACK_OPTIONS.video_types ?? []).map((item) => ({
                          label: item.label,
                          value: item.value,
                        }))}
                      />
                      <FormField
                        label="Video URL"
                        value={video.video_url}
                        onChange={(event) => updateVideo(index, "video_url", event.target.value)}
                      />
                      <div className="md:col-span-2">
                        <ImageUploadField
                          label={`Thumbnail ${index + 1}`}
                          value={video.thumbnail_url ?? ""}
                          previewLabel="Thumbnail"
                          uploadDirectory="content/video-thumbnails"
                          onChange={(value) => updateVideo(index, "thumbnail_url", value)}
                        />
                      </div>
                      <FormField
                        label="Durată (secunde)"
                        type="number"
                        value={video.duration_seconds ?? ""}
                        onChange={(event) =>
                          updateVideo(
                            index,
                            "duration_seconds",
                            event.target.value === "" ? null : Number(event.target.value),
                          )
                        }
                      />
                      <FormField
                        label="Ordine de sortare"
                        type="number"
                        value={video.sort_order}
                        onChange={(event) =>
                          updateVideo(
                            index,
                            "sort_order",
                            event.target.value === "" ? 0 : Number(event.target.value),
                          )
                        }
                      />
                      <div className="md:col-span-2">
                        <FormField
                          label={`Video description (${localeTab.toUpperCase()})`}
                          type="textarea"
                          rows={3}
                          value={video.description?.[localeTab] ?? ""}
                          onChange={(event) => updateVideoLocalized(index, "description", localeTab, event.target.value)}
                        />
                      </div>
                    </CardContent>
                  </Card>
                ))
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="playback" className="space-y-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle>Bunny content formats</CardTitle>
                <CardDescription>Mapezi fiecare calitate din platformă la `Library ID` și `Movie ID` din Bunny.</CardDescription>
              </div>
              <Button type="button" variant="outline" onClick={() => setContentFormatDrafts((current) => [...current, { ...createEmptyContentFormat(options), sort_order: current.length }])}>
                <PlusIcon className="h-4 w-4" />
                Adaugă format
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {contentFormatDrafts.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Nu există încă formate Bunny. Adaugă cel puțin un format principal pentru playback.
                </div>
              ) : contentFormatDrafts.map((item, index) => (
                <Card key={item.local_id}>
                  <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                    <div>
                      <CardTitle className="text-base">{item.quality} · {item.format_type}</CardTitle>
                      <CardDescription>{item.bunny_library_id || "Library ID neconfigurat"}</CardDescription>
                    </div>
                    <Button type="button" variant="ghost" size="icon" onClick={() => setContentFormatDrafts((current) => current.filter((entry) => entry.local_id !== item.local_id))}>
                      <TrashIcon className="h-4 w-4" />
                    </Button>
                  </CardHeader>
                  <CardContent className="grid gap-4 pt-6 md:grid-cols-2 xl:grid-cols-4">
                    <FormField
                      label="Calitate"
                      type="select"
                      value={item.quality}
                      options={options.quality_options.map((quality) => ({ label: quality, value: quality }))}
                      onChange={(event) => updateContentFormatDraft(item.local_id, "quality", event.target.value)}
                    />
                  <FormField
                    label="Tip format"
                    type="select"
                    value={item.format_type}
                    options={(options.format_types ?? FALLBACK_OPTIONS.format_types ?? []).map((option) => ({ label: option.label, value: option.value }))}
                    onChange={(event) => updateContentFormatDraft(item.local_id, "format_type", event.target.value)}
                  />
                    <FormField
                      label="Bunny Library ID"
                      value={item.bunny_library_id}
                      onChange={(event) => updateContentFormatDraft(item.local_id, "bunny_library_id", event.target.value)}
                    />
                    <FormField
                      label="Bunny Movie ID"
                      value={item.bunny_video_id}
                      onChange={(event) => updateContentFormatDraft(item.local_id, "bunny_video_id", event.target.value)}
                    />
                    <FormField
                      label="Stream URL override"
                      value={item.stream_url}
                      helperText="Lasă gol pentru URL generat automat din Bunny."
                      onChange={(event) => updateContentFormatDraft(item.local_id, "stream_url", event.target.value)}
                    />
                    <FormField
                      label="Token path"
                      value={item.token_path}
                      helperText="Opțional pentru pull zone / semnare custom."
                      onChange={(event) => updateContentFormatDraft(item.local_id, "token_path", event.target.value)}
                    />
                    <FormField
                      label="DRM policy"
                      value={item.drm_policy}
                      onChange={(event) => updateContentFormatDraft(item.local_id, "drm_policy", event.target.value)}
                    />
                    <FormField
                      label="Sort order"
                      type="number"
                      value={item.sort_order}
                      onChange={(event) => updateContentFormatDraft(item.local_id, "sort_order", event.target.value === "" ? "" : Number(event.target.value))}
                    />
                    <div className="md:col-span-2 xl:col-span-4 grid gap-4 md:grid-cols-2">
                      <FormField
                        label="Activ"
                        type="toggle"
                        checked={item.is_active}
                        helperText="Formatul poate fi ales pentru playback dacă este activ."
                        onChange={(event) => updateContentFormatDraft(item.local_id, "is_active", event.target.checked)}
                      />
                      <FormField
                        label="Format implicit"
                        type="toggle"
                        checked={item.is_default}
                        helperText="Formatul preferat când utilizatorul nu cere explicit o altă calitate."
                        onChange={(event) => updateContentFormatDraft(item.local_id, "is_default", event.target.checked)}
                      />
                    </div>
                  </CardContent>
                </Card>
              ))}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle>Rights windows</CardTitle>
                <CardDescription>Restricții teritoriale și perioade de disponibilitate per titlu sau per format.</CardDescription>
              </div>
              <Button type="button" variant="outline" onClick={() => setRightsWindowDrafts((current) => [...current, createEmptyRightsWindow()])}>
                <PlusIcon className="h-4 w-4" />
                Adaugă regulă
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {rightsWindowDrafts.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Fără reguli explicite, titlul rămâne disponibil global.
                </div>
              ) : rightsWindowDrafts.map((item) => (
                <div key={item.local_id} className="grid gap-4 rounded-xl border p-4 md:grid-cols-2 xl:grid-cols-6">
                  <FormField
                    label="Format"
                    type="select"
                    value={item.content_format_quality}
                    options={[{ label: "Toate formatele", value: "" }, ...contentFormatDrafts.map((format) => ({ label: format.quality, value: format.quality }))]}
                    onChange={(event) => updateRightsWindowDraft(item.local_id, "content_format_quality", event.target.value)}
                  />
                  <FormField
                    label="Țară"
                    type="select"
                    value={item.country_code}
                    options={[{ label: "Global", value: "" }, ...options.countries]}
                    onChange={(event) => updateRightsWindowDraft(item.local_id, "country_code", event.target.value)}
                  />
                  <FormField
                    label="Permite acces"
                    type="toggle"
                    checked={item.is_allowed}
                    helperText="Dacă este oprit, regula blochează accesul."
                    onChange={(event) => updateRightsWindowDraft(item.local_id, "is_allowed", event.target.checked)}
                  />
                  <FormField
                    label="Începe la"
                    type="date"
                    value={item.starts_at}
                    onChange={(event) => updateRightsWindowDraft(item.local_id, "starts_at", event.target.value)}
                  />
                  <FormField
                    label="Se termină la"
                    type="date"
                    value={item.ends_at}
                    onChange={(event) => updateRightsWindowDraft(item.local_id, "ends_at", event.target.value)}
                  />
                  <div className="flex items-end justify-end">
                    <Button type="button" variant="ghost" size="icon" onClick={() => setRightsWindowDrafts((current) => current.filter((entry) => entry.local_id !== item.local_id))}>
                      <TrashIcon className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle>Subtitle tracks</CardTitle>
                <CardDescription>Subtitrări multiple, opțional asociate unui format specific.</CardDescription>
              </div>
              <Button type="button" variant="outline" onClick={() => setSubtitleTrackDrafts((current) => [...current, { ...createEmptySubtitleTrack(), sort_order: current.length }])}>
                <PlusIcon className="h-4 w-4" />
                Adaugă subtitrare
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {subtitleTrackDrafts.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Nu există încă subtitrări configurate pentru playback.
                </div>
              ) : subtitleTrackDrafts.map((item) => (
                <div key={item.local_id} className="grid gap-4 rounded-xl border p-4 md:grid-cols-2 xl:grid-cols-6">
                  <FormField
                    label="Format"
                    type="select"
                    value={item.content_format_quality}
                    options={[{ label: "Toate formatele", value: "" }, ...contentFormatDrafts.map((format) => ({ label: format.quality, value: format.quality }))]}
                    onChange={(event) => updateSubtitleTrackDraft(item.local_id, "content_format_quality", event.target.value)}
                  />
                  <FormField
                    label="Locale"
                    type="select"
                    value={item.locale}
                    options={options.locales.map((locale) => ({ label: locale.label, value: locale.value }))}
                    onChange={(event) => updateSubtitleTrackDraft(item.local_id, "locale", event.target.value)}
                  />
                  <FormField
                    label="Label"
                    value={item.label}
                    onChange={(event) => updateSubtitleTrackDraft(item.local_id, "label", event.target.value)}
                  />
                  <FormField
                    label="Subtitle URL"
                    value={item.file_url}
                    onChange={(event) => updateSubtitleTrackDraft(item.local_id, "file_url", event.target.value)}
                  />
                  <FormField
                    label="Sort order"
                    type="number"
                    value={item.sort_order}
                    onChange={(event) => updateSubtitleTrackDraft(item.local_id, "sort_order", event.target.value === "" ? "" : Number(event.target.value))}
                  />
                  <div className="flex items-end gap-2">
                    <div className="flex-1">
                      <FormField
                        label="Implicit"
                        type="toggle"
                        checked={item.is_default}
                        helperText="Selectat automat în player."
                        onChange={(event) => updateSubtitleTrackDraft(item.local_id, "is_default", event.target.checked)}
                      />
                    </div>
                    <Button type="button" variant="ghost" size="icon" onClick={() => setSubtitleTrackDrafts((current) => current.filter((entry) => entry.local_id !== item.local_id))}>
                      <TrashIcon className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="credits" className="space-y-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle>Distribuție și echipă</CardTitle>
                <CardDescription>Actorii, personajele și echipa principală care trebuie afișate în pagina de detaliu.</CardDescription>
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  onClick={() =>
                    setFormState((current) => ({
                      ...current,
                      cast_members: [
                        ...current.cast_members,
                        {
                          ...createEmptyCastMember(),
                          sort_order: current.cast_members.length,
                        },
                      ],
                    }))
                  }
                >
                  <PlusIcon className="h-4 w-4" />
                  Adaugă actor
                </Button>
                <Button
                  variant="outline"
                  onClick={() =>
                    setFormState((current) => ({
                      ...current,
                      crew_members: [
                        ...current.crew_members,
                        {
                          ...createEmptyCrewMember(),
                          sort_order: current.crew_members.length,
                        },
                      ],
                    }))
                  }
                >
                  <PlusIcon className="h-4 w-4" />
                  Adaugă membru echipă
                </Button>
              </div>
            </CardHeader>
            <CardContent className="grid gap-6 pt-6 xl:grid-cols-2">
              <div className="space-y-4">
                <div className="text-sm font-medium">Distribuție</div>
                {formState.cast_members.length === 0 ? (
                  <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                    Nu există membri în distribuție.
                  </div>
                ) : null}
                {formState.cast_members.map((member, index) => (
                  <Card key={member.id}>
                    <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                      <CardTitle className="text-base">{member.name || `Actor ${index + 1}`}</CardTitle>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() =>
                          setFormState((current) => ({
                            ...current,
                            cast_members: current.cast_members.filter((_, memberIndex) => memberIndex !== index),
                          }))
                        }
                      >
                        <TrashIcon className="h-4 w-4" />
                      </Button>
                    </CardHeader>
                    <CardContent className="grid gap-4 pt-6">
                      <FormField
                        label="Actor"
                        value={member.name}
                        onChange={(event) => updateCastMember(index, "name", event.target.value)}
                      />
                      <FormField
                        label="Tip credit actor"
                        type="select"
                        value={member.credit_type}
                        options={(options.cast_credit_types ?? FALLBACK_OPTIONS.cast_credit_types ?? []).map((item) => ({
                          label: item.label,
                          value: item.value,
                        }))}
                        onChange={(event) => updateCastMember(index, "credit_type", event.target.value)}
                      />
                      <FormField
                        label={`Nume personaj (${localeTab.toUpperCase()})`}
                        value={member.character_name[localeTab]}
                        onChange={(event) => updateCastMemberLocalized(index, localeTab, event.target.value)}
                      />
                      <ImageUploadField
                        label={`Avatar actor ${index + 1}`}
                        value={member.avatar_url ?? ""}
                        previewLabel="Avatar"
                        aspectClassName="aspect-square"
                        uploadDirectory="content/avatars"
                        onChange={(value) => updateCastMember(index, "avatar_url", value)}
                      />
                    </CardContent>
                  </Card>
                ))}
              </div>

              <div className="space-y-4">
                <div className="text-sm font-medium">Echipă</div>
                {formState.crew_members.length === 0 ? (
                  <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                    Nu există membri în echipă.
                  </div>
                ) : null}
                {formState.crew_members.map((member, index) => (
                  <Card key={member.id}>
                    <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                      <CardTitle className="text-base">{member.name || `Membru echipă ${index + 1}`}</CardTitle>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() =>
                          setFormState((current) => ({
                            ...current,
                            crew_members: current.crew_members.filter((_, memberIndex) => memberIndex !== index),
                          }))
                        }
                      >
                        <TrashIcon className="h-4 w-4" />
                      </Button>
                    </CardHeader>
                    <CardContent className="grid gap-4 pt-6">
                      <FormField
                        label="Nume"
                        value={member.name}
                        onChange={(event) => updateCrewMember(index, "name", event.target.value)}
                      />
                      <FormField
                        label="Rol în echipă"
                        type="select"
                        value={member.credit_type}
                        options={(options.crew_credit_types ?? FALLBACK_OPTIONS.crew_credit_types ?? []).map((item) => ({
                          label: item.label,
                          value: item.value,
                        }))}
                        onChange={(event) => updateCrewMember(index, "credit_type", event.target.value)}
                      />
                      <FormField
                        label={`Etichetă rol (${localeTab.toUpperCase()})`}
                        value={member.job_title[localeTab]}
                        onChange={(event) => updateCrewMemberLocalized(index, localeTab, event.target.value)}
                      />
                      <ImageUploadField
                        label={`Avatar echipă ${index + 1}`}
                        value={member.avatar_url ?? ""}
                        previewLabel="Avatar"
                        aspectClassName="aspect-square"
                        uploadDirectory="content/avatars"
                        onChange={(value) => updateCrewMember(index, "avatar_url", value)}
                      />
                    </CardContent>
                  </Card>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="series" className="space-y-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle>Structură serial</CardTitle>
                <CardDescription>Sezoane și episoade. Pentru filme, secțiunea poate rămâne goală.</CardDescription>
              </div>
              <Button
                variant="outline"
                onClick={() =>
                  setFormState((current) => ({
                    ...current,
                    seasons: [
                      ...current.seasons,
                      {
                        ...createEmptySeason(),
                        season_number: current.seasons.length + 1,
                        sort_order: current.seasons.length,
                      },
                    ],
                  }))
                }
              >
                <PlusIcon className="h-4 w-4" />
                Adaugă sezon
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {formState.type !== "series" ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Titlul curent este `movie`. Dacă schimbi tipul în `series`, poți construi aici sezoanele și episoadele.
                </div>
              ) : null}

              {formState.seasons.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Nu există încă sezoane definite.
                </div>
              ) : null}

              {formState.seasons.map((season, seasonIndex) => (
                <Card key={season.id}>
                  <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                    <div>
                      <CardTitle className="text-base">
                        {season.title?.[localeTab] || season.title?.ro || `Sezonul ${season.season_number}`}
                      </CardTitle>
                      <CardDescription>{season.episodes.length} episoade</CardDescription>
                    </div>
                    <div className="flex gap-2">
                      <Button
                        variant="outline"
                        onClick={() =>
                          setFormState((current) => ({
                            ...current,
                            seasons: current.seasons.map((item, itemIndex) =>
                              itemIndex === seasonIndex
                                ? {
                                    ...item,
                                    episodes: [
                                      ...item.episodes,
                                      {
                                        ...createEmptyEpisode(),
                                        episode_number: item.episodes.length + 1,
                                        sort_order: item.episodes.length,
                                      },
                                    ],
                                  }
                                : item,
                            ),
                          }))
                        }
                        >
                          <PlusIcon className="h-4 w-4" />
                        Adaugă episod
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() =>
                          setFormState((current) => ({
                            ...current,
                            seasons: current.seasons.filter((_, itemIndex) => itemIndex !== seasonIndex),
                          }))
                        }
                      >
                        <TrashIcon className="h-4 w-4" />
                      </Button>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-6 pt-6">
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                      <FormField
                        label="Număr sezon"
                        type="number"
                        value={season.season_number}
                        onChange={(event) =>
                          updateSeason(
                            seasonIndex,
                            "season_number",
                            event.target.value === "" ? 1 : Number(event.target.value),
                          )
                        }
                      />
                      <FormField
                        label={`Title (${localeTab.toUpperCase()})`}
                        value={season.title?.[localeTab] ?? ""}
                        onChange={(event) => updateSeasonLocalized(seasonIndex, "title", localeTab, event.target.value)}
                      />
                      <ImageUploadField
                        label={`Poster sezon ${seasonIndex + 1}`}
                        value={season.poster_url ?? ""}
                        previewLabel="Poster sezon"
                        aspectClassName="aspect-[2/3]"
                        uploadDirectory="content/seasons"
                        onChange={(value) => updateSeason(seasonIndex, "poster_url", value)}
                      />
                      <FormField
                        label="Ordine de sortare"
                        type="number"
                        value={season.sort_order}
                        onChange={(event) =>
                          updateSeason(
                            seasonIndex,
                            "sort_order",
                            event.target.value === "" ? 0 : Number(event.target.value),
                          )
                        }
                      />
                    </div>
                    <FormField
                      label={`Descriere sezon (${localeTab.toUpperCase()})`}
                      type="textarea"
                      rows={4}
                      value={season.description?.[localeTab] ?? ""}
                      onChange={(event) => updateSeasonLocalized(seasonIndex, "description", localeTab, event.target.value)}
                    />

                    <div className="space-y-4">
                      {season.episodes.map((episode, episodeIndex) => (
                        <Card key={episode.id}>
                          <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                            <CardTitle className="text-base">
                              {episode.title?.[localeTab] || episode.title?.ro || `Episodul ${episode.episode_number}`}
                            </CardTitle>
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() =>
                                setFormState((current) => ({
                                  ...current,
                                  seasons: current.seasons.map((item, itemIndex) =>
                                    itemIndex === seasonIndex
                                      ? {
                                          ...item,
                                          episodes: item.episodes.filter((_, currentEpisodeIndex) => currentEpisodeIndex !== episodeIndex),
                                        }
                                      : item,
                                  ),
                                }))
                              }
                            >
                              <TrashIcon className="h-4 w-4" />
                            </Button>
                          </CardHeader>
                          <CardContent className="grid gap-4 pt-6 md:grid-cols-2 xl:grid-cols-4">
                            <FormField
                              label="Număr episod"
                              type="number"
                              value={episode.episode_number}
                              onChange={(event) =>
                                updateEpisode(
                                  seasonIndex,
                                  episodeIndex,
                                  "episode_number",
                                  event.target.value === "" ? 1 : Number(event.target.value),
                                )
                              }
                            />
                            <FormField
                              label="Titlu"
                              value={episode.title[localeTab]}
                              onChange={(event) =>
                                updateEpisodeLocalized(seasonIndex, episodeIndex, "title", localeTab, event.target.value)
                              }
                            />
                            <FormField
                              label="Durată"
                              type="number"
                              value={episode.runtime_minutes ?? ""}
                              onChange={(event) =>
                                updateEpisode(
                                  seasonIndex,
                                  episodeIndex,
                                  "runtime_minutes",
                                  event.target.value === "" ? null : Number(event.target.value),
                                )
                              }
                            />
                            <div className="md:col-span-2 xl:col-span-2">
                              <ImageUploadField
                                label={`Miniatură episod ${episodeIndex + 1}`}
                                value={episode.thumbnail_url ?? ""}
                                previewLabel="Miniatură"
                                uploadDirectory="content/episodes"
                                onChange={(value) =>
                                  updateEpisode(seasonIndex, episodeIndex, "thumbnail_url", value)
                                }
                              />
                            </div>
                            <div className="md:col-span-2 xl:col-span-2">
                              <ImageUploadField
                                label={`Backdrop episod ${episodeIndex + 1}`}
                                value={episode.backdrop_url ?? ""}
                                previewLabel="Backdrop"
                                uploadDirectory="content/episodes"
                                onChange={(value) =>
                                  updateEpisode(seasonIndex, episodeIndex, "backdrop_url", value)
                                }
                              />
                            </div>
                            <FormField
                              label="URL video episod"
                              value={episode.video_url ?? ""}
                              onChange={(event) => updateEpisode(seasonIndex, episodeIndex, "video_url", event.target.value)}
                            />
                            <FormField
                              label="URL trailer episod"
                              value={episode.trailer_url ?? ""}
                              onChange={(event) =>
                                updateEpisode(seasonIndex, episodeIndex, "trailer_url", event.target.value)
                              }
                            />
                            <FormField
                              label="Ordine de sortare"
                              type="number"
                              value={episode.sort_order}
                              onChange={(event) =>
                                updateEpisode(
                                  seasonIndex,
                                  episodeIndex,
                                  "sort_order",
                                  event.target.value === "" ? 0 : Number(event.target.value),
                                )
                              }
                            />
                            <div className="md:col-span-2 xl:col-span-4">
                              <FormField
                                label={`Descriere episod (${localeTab.toUpperCase()})`}
                                type="textarea"
                                rows={3}
                                value={episode.description?.[localeTab] ?? ""}
                                onChange={(event) =>
                                  updateEpisodeLocalized(
                                    seasonIndex,
                                    episodeIndex,
                                    "description",
                                    localeTab,
                                    event.target.value,
                                  )
                                }
                              />
                            </div>
                          </CardContent>
                        </Card>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              ))}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="publishing" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Comportament în storefront</CardTitle>
              <CardDescription>Controlezi vizibilitatea pe home, highlights și disponibilitatea publică.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 xl:grid-cols-2">
              <FormField
                label="Promovat"
                type="toggle"
                checked={formState.is_featured}
                helperText="Poate apărea în secțiunile promovate și în hero-ul principal."
                onChange={(event) => setFormState((current) => ({ ...current, is_featured: event.target.checked }))}
              />
              <FormField
                label="În trend"
                type="toggle"
                checked={formState.is_trending}
                helperText="Poate intra în highlights sau listări promo."
                onChange={(event) => setFormState((current) => ({ ...current, is_trending: event.target.checked }))}
              />
              <Card className="xl:col-span-2">
                <CardHeader className="border-b pb-4">
                  <CardTitle className="text-lg">Subtitrări și calități playback</CardTitle>
                </CardHeader>
                <CardContent className="space-y-5 pt-6">
                  <div className="space-y-3">
                    <div className="text-sm font-medium">Limbi subtitrare</div>
                    <div className="flex flex-wrap gap-2">
                      {options.locales.map((locale) => {
                        const selected = formState.subtitle_locales.includes(locale.value);
                        return (
                          <button
                            key={locale.value}
                            type="button"
                            onClick={() => toggleSubtitleLocale(locale.value)}
                            className={cn(
                              "rounded-full border px-3 py-1.5 text-sm transition-colors",
                              selected
                                ? "border-foreground bg-foreground text-background"
                                : "border-input bg-background hover:bg-accent hover:text-accent-foreground",
                            )}
                          >
                            {locale.label}
                          </button>
                        );
                      })}
                    </div>
                  </div>

                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="text-sm font-medium">Calități disponibile</div>
                      {getFieldError("available_qualities") ? (
                        <span className="text-sm text-destructive">{getFieldError("available_qualities")}</span>
                      ) : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {options.quality_options.map((quality) => {
                        const selected = formState.available_qualities.includes(quality);
                        return (
                          <button
                            key={quality}
                            type="button"
                            onClick={() => toggleQuality(quality)}
                            className={cn(
                              "rounded-full border px-3 py-1.5 text-sm transition-colors",
                              selected
                                ? "border-foreground bg-foreground text-background"
                                : "border-input bg-background hover:bg-accent hover:text-accent-foreground",
                            )}
                          >
                            {quality}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                </CardContent>
              </Card>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle>Premiere și acces sincronizat</CardTitle>
                <CardDescription>
                  Configurezi premiere digitale care blochează playback-ul până la ora de start și afișează countdown în storefront.
                </CardDescription>
              </div>
              <Button
                type="button"
                variant="outline"
                onClick={() =>
                  setPremiereEventDrafts((current) => [
                    ...current,
                    createEmptyPremiereEvent(),
                  ])
                }
              >
                <PlusIcon className="h-4 w-4" />
                Adaugă premieră
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {premiereEventDrafts.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Nu există încă premiere configurate. Dacă adaugi una publică și activă, playback-ul se va deschide doar după ora de start.
                </div>
              ) : (
                premiereEventDrafts.map((item, index) => (
                  <div key={item.local_id} className="grid gap-4 rounded-xl border p-4 md:grid-cols-2 xl:grid-cols-6">
                    <FormField
                      label="Titlu premieră"
                      value={item.title}
                      onChange={(event) => updatePremiereEventDraft(item.local_id, "title", event.target.value)}
                    />
                    <FormField
                      label="Start"
                      type="datetime-local"
                      value={item.starts_at}
                      onChange={(event) => updatePremiereEventDraft(item.local_id, "starts_at", event.target.value)}
                    />
                    <FormField
                      label="Sfârșit"
                      type="datetime-local"
                      value={item.ends_at}
                      onChange={(event) => updatePremiereEventDraft(item.local_id, "ends_at", event.target.value)}
                    />
                    <FormField
                      label="Activ"
                      type="toggle"
                      checked={item.is_active}
                      helperText="Dacă este oprită, premiera nu influențează storefront-ul."
                      onChange={(event) => updatePremiereEventDraft(item.local_id, "is_active", event.target.checked)}
                    />
                    <FormField
                      label="Public"
                      type="toggle"
                      checked={item.is_public}
                      helperText="Afișează countdown-ul și aplică lock pe playback."
                      onChange={(event) => updatePremiereEventDraft(item.local_id, "is_public", event.target.checked)}
                    />
                    <div className="flex items-end justify-end">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => setPremiereEventDrafts((current) => current.filter((entry) => entry.local_id !== item.local_id))}
                      >
                        <TrashIcon className="h-4 w-4" />
                      </Button>
                    </div>
                    <div className="md:col-span-2 xl:col-span-6 text-xs text-muted-foreground">
                      Premieră #{index + 1} este folosită și pentru countdown în client. Dacă există mai multe premiere active, storefront-ul o va lua pe următoarea în ordine cronologică.
                    </div>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="commerce" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Acces și oferte</CardTitle>
              <CardDescription>
                Toată logica de monetizare stă aici: free, rental, forever și URL-ul video pentru fiecare calitate.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <FormField
                label="Titlu gratuit"
                type="toggle"
                checked={formState.is_free}
                helperText="Dacă e activ, titlul intră în secțiunea free și nu cere preț."
                onChange={(event) =>
                  setFormState((current) => ({
                    ...current,
                    is_free: event.target.checked,
                  }))
                }
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
              <div>
                <CardTitle className="text-lg">Variante de ofertă</CardTitle>
                <CardDescription>
                  Pentru filme, fiecare offer trebuie să aibă `playback URL` pentru calitatea respectivă. Pentru seriale îl poți lăsa gol și folosești URL-urile pe episoade.
                </CardDescription>
              </div>
              <Button
                type="button"
                variant="outline"
                disabled={!numericContentId}
                onClick={() =>
                  setOfferDrafts((current) => [
                    ...current,
                    {
                      ...createEmptyOfferForm(options),
                      sort_order: current.length,
                    },
                  ])
                }
              >
                <PlusIcon className="h-4 w-4" />
                Adaugă ofertă
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
              {!numericContentId ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Salvează mai întâi filmul sau serialul, apoi poți adăuga offer-urile pe calități și tipuri de acces.
                </div>
              ) : null}

              {numericContentId && offerDrafts.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                  Nu există încă oferte. Poți crea variante `Gratuit`, `Închiriere` sau `Permanent` pentru fiecare calitate.
                </div>
              ) : null}

              {numericContentId && offerDrafts.map((offer) => {
                const isBusy = offerBusyKey === offer.local_id;
                const isRental = offer.offer_type === "rental";
                const isFreeOffer = offer.offer_type === "free";

                return (
                  <Card key={offer.local_id}>
                    <CardHeader className="flex flex-row items-center justify-between border-b pb-4">
                      <div>
                        <CardTitle className="text-base">
                          {offer.name || `${offer.offer_type} ${offer.quality}`}
                        </CardTitle>
                        <CardDescription>
                          {offer.offer_type === "free"
                            ? "Acces gratuit"
                            : offer.offer_type === "lifetime"
                              ? "Acces permanent"
                              : `${offer.rental_days || 0} zile închiriere`}
                        </CardDescription>
                      </div>
                      <div className="flex gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          onClick={() => void handleSaveOffer(offer.local_id)}
                          disabled={isBusy}
                        >
                          {isBusy ? "Se salvează..." : "Salvează oferta"}
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => void handleDeleteOffer(offer.local_id)}
                          disabled={isBusy}
                        >
                          <TrashIcon className="h-4 w-4" />
                        </Button>
                      </div>
                    </CardHeader>
                    <CardContent className="grid gap-4 pt-6 md:grid-cols-2 xl:grid-cols-4">
                      <FormField
                        label="Nume ofertă"
                        value={offer.name}
                        error={getOfferFieldError(offer.local_id, "name")}
                        onChange={(event) => updateOfferDraft(offer.local_id, "name", event.target.value)}
                      />
                      <FormField
                        label="Tip acces"
                        type="select"
                        value={offer.offer_type}
                        error={getOfferFieldError(offer.local_id, "offer_type")}
                        options={(options.offer_types ?? FALLBACK_OPTIONS.offer_types ?? []).map((item) => ({
                          label: item.label,
                          value: item.value,
                        }))}
                        onChange={(event) =>
                          setOfferDrafts((current) =>
                            current.map((item) =>
                              item.local_id === offer.local_id
                                ? {
                                    ...item,
                                    offer_type: event.target.value as AdminOfferType,
                                    price_amount: event.target.value === "free" ? "" : item.price_amount,
                                    rental_days: event.target.value === "rental" ? item.rental_days || 2 : "",
                                  }
                                : item,
                            ),
                          )
                        }
                      />
                      <FormField
                        label="Calitate"
                        type="select"
                        value={offer.quality}
                        error={getOfferFieldError(offer.local_id, "quality")}
                        options={options.quality_options.map((quality) => ({ label: quality, value: quality }))}
                        onChange={(event) => updateOfferDraft(offer.local_id, "quality", event.target.value)}
                      />
                      <FormField
                        label="Monedă"
                        value={offer.currency}
                        error={getOfferFieldError(offer.local_id, "currency")}
                        onChange={(event) => updateOfferDraft(offer.local_id, "currency", event.target.value.toUpperCase())}
                      />
                      <FormField
                        label="Preț"
                        type="number"
                        value={offer.price_amount}
                        disabled={isFreeOffer}
                        error={getOfferFieldError(offer.local_id, "price_amount")}
                        onChange={(event) =>
                          updateOfferDraft(
                            offer.local_id,
                            "price_amount",
                            event.target.value === "" ? "" : Number(event.target.value),
                          )
                        }
                      />
                      <FormField
                        label="Zile închiriere"
                        type="number"
                        value={offer.rental_days}
                        disabled={!isRental}
                        error={getOfferFieldError(offer.local_id, "rental_days")}
                        onChange={(event) =>
                          updateOfferDraft(
                            offer.local_id,
                            "rental_days",
                            event.target.value === "" ? "" : Number(event.target.value),
                          )
                        }
                      />
                      <FormField
                        label="Începe la"
                        type="date"
                        value={offer.starts_at}
                        error={getOfferFieldError(offer.local_id, "starts_at")}
                        onChange={(event) => updateOfferDraft(offer.local_id, "starts_at", event.target.value)}
                      />
                      <FormField
                        label="Se termină la"
                        type="date"
                        value={offer.ends_at}
                        error={getOfferFieldError(offer.local_id, "ends_at")}
                        onChange={(event) => updateOfferDraft(offer.local_id, "ends_at", event.target.value)}
                      />
                      <div className="md:col-span-2 xl:col-span-3">
                        <FormField
                          label="URL playback"
                          value={offer.playback_url}
                          error={getOfferFieldError(offer.local_id, "playback_url")}
                          helperText="Opțional. Dacă playback-ul este definit prin Bunny content formats, oferta nu are nevoie de URL propriu."
                          onChange={(event) => updateOfferDraft(offer.local_id, "playback_url", event.target.value)}
                        />
                      </div>
                      <FormField
                        label="Ordine de sortare"
                        type="number"
                        value={offer.sort_order}
                        error={getOfferFieldError(offer.local_id, "sort_order")}
                        onChange={(event) =>
                          updateOfferDraft(
                            offer.local_id,
                            "sort_order",
                            event.target.value === "" ? "" : Number(event.target.value),
                          )
                        }
                      />
                      <div className="md:col-span-2 xl:col-span-4">
                        <FormField
                          label="Activă"
                          type="toggle"
                          checked={offer.is_active}
                          helperText="Offer-ul poate fi afișat și cumpărat doar dacă este activ."
                          onChange={(event) => updateOfferDraft(offer.local_id, "is_active", event.target.checked)}
                        />
                      </div>
                    </CardContent>
                  </Card>
                );
              })}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
