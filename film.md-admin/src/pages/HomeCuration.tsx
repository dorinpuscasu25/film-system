import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowDownIcon,
  ArrowUpIcon,
  PlusIcon,
  SparklesIcon,
  Trash2Icon,
} from "lucide-react";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi, ApiRequestError } from "../lib/api";
import {
  HomeCurationContentOption,
  HomeCurationHeroSlide,
  HomeCurationOptions,
  HomeCurationRuleFilters,
  HomeCurationSection,
  HomeSectionSourceMode,
  HomeSectionType,
  LocalizedText,
  TaxonomyLocale,
  TaxonomyType,
} from "../types";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Textarea } from "../components/ui/textarea";
import { Tabs } from "../components/shared/Tabs";
import { ImageUploadField } from "../components/shared/ImageUploadField";
import { FormField } from "../components/shared/FormField";
import { cn } from "../lib/utils";

const LOCALES: TaxonomyLocale[] = ["ro", "ru", "en"];
const TAXONOMY_GROUP_ORDER: TaxonomyType[] = ["genre", "collection", "tag", "badge"];
const EMPTY_LOCALIZED_TEXT: LocalizedText = { ro: "", ru: "", en: "" };
const EMPTY_RULE_FILTERS: HomeCurationRuleFilters = {
  taxonomy_ids: [],
  content_types: [],
  access: "all",
  sort_mode: "release_year_desc",
  matching_strategy: "any",
  featured_only: false,
  trending_only: false,
};

function normalizeLocalizedText(value?: Partial<LocalizedText> | null): LocalizedText {
  return {
    ro: String(value?.ro ?? ""),
    ru: String(value?.ru ?? ""),
    en: String(value?.en ?? ""),
  };
}

function normalizeContentOption(value: HomeCurationContentOption): HomeCurationContentOption {
  return {
    ...value,
    genres: value.genres ?? [],
    collections: value.collections ?? [],
    tags: value.tags ?? [],
    badges: value.badges ?? [],
  };
}

function normalizeHeroSlide(value: HomeCurationHeroSlide): HomeCurationHeroSlide {
  return {
    ...value,
    desktop_image_url: value.desktop_image_url ?? "",
    mobile_image_url: value.mobile_image_url ?? "",
    eyebrow: normalizeLocalizedText(value.eyebrow),
    title: normalizeLocalizedText(value.title),
    description: normalizeLocalizedText(value.description),
    primary_cta_label: normalizeLocalizedText(value.primary_cta_label),
    secondary_cta_label: normalizeLocalizedText(value.secondary_cta_label),
    content: value.content ? normalizeContentOption(value.content) : null,
  };
}

function normalizeSection(value: HomeCurationSection): HomeCurationSection {
  return {
    ...value,
    id: typeof value.id === "number" ? value.id : null,
    title: normalizeLocalizedText(value.title),
    subtitle: normalizeLocalizedText(value.subtitle),
    source_mode: value.source_mode ?? "dynamic",
    limit: value.limit ?? 12,
    content_ids: value.content_ids ?? [],
    selected_content: (value.selected_content ?? []).map(normalizeContentOption),
    rule_filters: {
      ...EMPTY_RULE_FILTERS,
      ...value.rule_filters,
      taxonomy_ids: value.rule_filters?.taxonomy_ids ?? [],
      content_types: value.rule_filters?.content_types ?? [],
    },
    hero_slides: (value.hero_slides ?? []).map(normalizeHeroSlide),
    meta: value.meta ?? {},
  };
}

function buildEmptySection(sectionType: HomeSectionType, id: number): HomeCurationSection {
  return normalizeSection({
    id,
    name: sectionType === "hero_slider" ? "Main hero slider" : "New carousel",
    section_type: sectionType,
    active: true,
    sort_order: 0,
    title: EMPTY_LOCALIZED_TEXT,
    subtitle: EMPTY_LOCALIZED_TEXT,
    source_mode: sectionType === "content_carousel" ? "dynamic" : "manual",
    limit: 12,
    content_ids: [],
    selected_content: [],
    rule_filters: EMPTY_RULE_FILTERS,
    hero_slides: [],
    meta: {},
  });
}

function createSlideId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `slide-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function badgeClassName(color?: string | null) {
  if (!color) {
    return "border-border text-muted-foreground";
  }

  return "border-transparent text-white";
}

function contentMatchesDynamicRules(
  content: HomeCurationContentOption,
  section: HomeCurationSection,
  options: HomeCurationOptions | null,
): boolean {
  const filters = section.rule_filters;

  if (filters.content_types.length > 0 && !filters.content_types.includes(content.type)) {
    return false;
  }

  if (filters.access === "free" && content.lowest_price > 0) {
    return false;
  }

  if (filters.access === "paid" && content.lowest_price <= 0) {
    return false;
  }

  if (filters.featured_only && !content.is_featured) {
    return false;
  }

  if (filters.trending_only && !content.is_trending) {
    return false;
  }

  if (!options || filters.taxonomy_ids.length === 0) {
    return true;
  }

  const selectedTaxonomies = Object.values(options.taxonomies ?? {})
    .flat()
    .filter((taxonomy) => filters.taxonomy_ids.includes(taxonomy.id));

  if (selectedTaxonomies.length === 0) {
    return true;
  }

  const matches = selectedTaxonomies.map((taxonomy) => {
    if (taxonomy.type === "genre") {
      return content.genres.includes(taxonomy.localized_name);
    }

    if (taxonomy.type === "collection") {
      return content.collections.includes(taxonomy.localized_name);
    }

    if (taxonomy.type === "tag") {
      return content.tags.includes(taxonomy.localized_name);
    }

    return content.badges.some((badge) => badge.slug === taxonomy.slug);
  });

  return filters.matching_strategy === "all"
    ? matches.every(Boolean)
    : matches.some(Boolean);
}

function sortPreviewItems(items: HomeCurationContentOption[], section: HomeCurationSection) {
  const { sort_mode } = section.rule_filters;

  return [...items].sort((left, right) => {
    switch (sort_mode) {
      case "release_year_asc":
        return (left.release_year ?? 0) - (right.release_year ?? 0);
      case "published_desc":
        return Number(right.is_featured) - Number(left.is_featured) || (right.release_year ?? 0) - (left.release_year ?? 0);
      case "imdb_desc":
        return Number(right.lowest_price === 0) - Number(left.lowest_price === 0) || (right.release_year ?? 0) - (left.release_year ?? 0);
      case "platform_desc":
        return Number(right.is_trending) - Number(left.is_trending) || (right.release_year ?? 0) - (left.release_year ?? 0);
      case "title_asc":
        return left.title.localeCompare(right.title);
      case "manual":
        return 0;
      default:
        return (right.release_year ?? 0) - (left.release_year ?? 0);
    }
  });
}

function resolveSectionPreview(
  section: HomeCurationSection,
  options: HomeCurationOptions | null,
): HomeCurationContentOption[] {
  if (!options) {
    return [];
  }

  const contents = options.contents ?? [];

  if (section.section_type !== "content_carousel") {
    return [];
  }

  if (section.source_mode === "manual") {
    const byId = new Map(contents.map((content) => [content.id, content]));

    return section.content_ids
      .map((contentId) => byId.get(contentId))
      .filter((content): content is HomeCurationContentOption => Boolean(content))
      .slice(0, section.limit ?? 12);
  }

  const filtered = contents.filter((content) => contentMatchesDynamicRules(content, section, options));
  return sortPreviewItems(filtered, section).slice(0, section.limit ?? 12);
}

export function HomeCuration() {
  const { can } = useAdmin();
  const canEdit = can("settings.edit_home_curation");
  const sectionIdRef = useRef(-1);
  const [sections, setSections] = useState<HomeCurationSection[]>([]);
  const [options, setOptions] = useState<HomeCurationOptions | null>(null);
  const [selectedSectionId, setSelectedSectionId] = useState<number | null>(null);
  const [activeLocale, setActiveLocale] = useState<TaxonomyLocale>("ro");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [heroPickerContentId, setHeroPickerContentId] = useState<string>("");
  const [manualPickerContentId, setManualPickerContentId] = useState<string>("");

  useEffect(() => {
    let active = true;

    async function loadHomeCuration() {
      setIsLoading(true);
      setError(null);

      try {
        const response = await adminApi.getHomeCuration();
        if (!active) {
          return;
        }

        const normalizedSections = response.sections.map(normalizeSection);
        const normalizedOptions: HomeCurationOptions = {
          ...response.options,
          contents: (response.options.contents ?? []).map(normalizeContentOption),
          taxonomies: response.options.taxonomies ?? {},
        };

        setSections(normalizedSections);
        setOptions(normalizedOptions);
        setSelectedSectionId(normalizedSections[0]?.id ?? null);
      } catch (loadError) {
        if (!active) {
          return;
        }

        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca homepage curation.");
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void loadHomeCuration();

    return () => {
      active = false;
    };
  }, []);

  const selectedSection = useMemo(
    () => sections.find((section) => section.id === selectedSectionId) ?? null,
    [sections, selectedSectionId],
  );

  const selectedPreview = useMemo(
    () => (selectedSection ? resolveSectionPreview(selectedSection, options) : []),
    [options, selectedSection],
  );

  useEffect(() => {
    if (!selectedSectionId && sections.length > 0) {
      setSelectedSectionId(sections[0].id);
      return;
    }

    if (selectedSectionId !== null && !sections.some((section) => section.id === selectedSectionId)) {
      setSelectedSectionId(sections[0]?.id ?? null);
    }
  }, [sections, selectedSectionId]);

  function updateSection(sectionId: number, updater: (section: HomeCurationSection) => HomeCurationSection) {
    setSections((currentSections) =>
      currentSections.map((section) => (section.id === sectionId ? normalizeSection(updater(section)) : section)),
    );
    setSuccessMessage(null);
  }

  function addSection(sectionType: HomeSectionType) {
    const nextId = sectionIdRef.current;
    sectionIdRef.current -= 1;

    const newSection = buildEmptySection(sectionType, nextId);
    newSection.sort_order = sections.length;
    newSection.title = {
      ro: sectionType === "hero_slider" ? "Hero principal" : "Carusel nou",
      ru: sectionType === "hero_slider" ? "Главный hero" : "Новая полка",
      en: sectionType === "hero_slider" ? "Main hero" : "New shelf",
    };

    setSections((currentSections) => [...currentSections, newSection]);
    setSelectedSectionId(nextId);
    setSuccessMessage(null);
  }

  function removeSection(sectionId: number) {
    setSections((currentSections) => currentSections.filter((section) => section.id !== sectionId));
    setSuccessMessage(null);
  }

  function moveSection(sectionId: number, direction: "up" | "down") {
    setSections((currentSections) => {
      const index = currentSections.findIndex((section) => section.id === sectionId);
      if (index < 0) {
        return currentSections;
      }

      const targetIndex = direction === "up" ? index - 1 : index + 1;
      if (targetIndex < 0 || targetIndex >= currentSections.length) {
        return currentSections;
      }

      const nextSections = [...currentSections];
      const [item] = nextSections.splice(index, 1);
      nextSections.splice(targetIndex, 0, item);

      return nextSections.map((section, sortOrder) => ({
        ...section,
        sort_order: sortOrder,
      }));
    });
    setSuccessMessage(null);
  }

  function addHeroSlide(sectionId: number) {
    const sourceContentId = Number(heroPickerContentId || options?.contents?.[0]?.id || 0);
    if (!sourceContentId) {
      return;
    }

    const sourceContent = options?.contents.find((content) => content.id === sourceContentId) ?? null;

    updateSection(sectionId, (section) => ({
      ...section,
      hero_slides: [
        ...section.hero_slides,
        normalizeHeroSlide({
          id: createSlideId(),
          content_id: sourceContentId,
          active: true,
          sort_order: section.hero_slides.length,
          desktop_image_url: sourceContent?.hero_desktop_url || sourceContent?.backdrop_url || "",
          mobile_image_url: sourceContent?.hero_mobile_url || sourceContent?.poster_url || "",
          eyebrow: EMPTY_LOCALIZED_TEXT,
          title: {
            ro: sourceContent?.title ?? "",
            ru: sourceContent?.title ?? "",
            en: sourceContent?.title ?? "",
          },
          description: EMPTY_LOCALIZED_TEXT,
          primary_cta_label: { ro: "Vezi detalii", ru: "Подробнее", en: "See details" },
          secondary_cta_label: { ro: "Trailer", ru: "Трейлер", en: "Trailer" },
          content: sourceContent,
        }),
      ],
    }));
    setHeroPickerContentId("");
  }

  function updateHeroSlide(sectionId: number, slideId: string, updater: (slide: HomeCurationHeroSlide) => HomeCurationHeroSlide) {
    updateSection(sectionId, (section) => ({
      ...section,
      hero_slides: section.hero_slides.map((slide) => (slide.id === slideId ? normalizeHeroSlide(updater(slide)) : slide)),
    }));
  }

  function moveHeroSlide(sectionId: number, slideId: string, direction: "up" | "down") {
    updateSection(sectionId, (section) => {
      const index = section.hero_slides.findIndex((slide) => slide.id === slideId);
      if (index < 0) {
        return section;
      }

      const targetIndex = direction === "up" ? index - 1 : index + 1;
      if (targetIndex < 0 || targetIndex >= section.hero_slides.length) {
        return section;
      }

      const nextSlides = [...section.hero_slides];
      const [item] = nextSlides.splice(index, 1);
      nextSlides.splice(targetIndex, 0, item);

      return {
        ...section,
        hero_slides: nextSlides.map((slide, sortOrder) => ({
          ...slide,
          sort_order: sortOrder,
        })),
      };
    });
  }

  function removeHeroSlide(sectionId: number, slideId: string) {
    updateSection(sectionId, (section) => ({
      ...section,
      hero_slides: section.hero_slides.filter((slide) => slide.id !== slideId).map((slide, sortOrder) => ({
        ...slide,
        sort_order: sortOrder,
      })),
    }));
  }

  function addManualContent(sectionId: number) {
    const contentId = Number(manualPickerContentId || 0);
    if (!contentId) {
      return;
    }

    updateSection(sectionId, (section) => {
      if (section.content_ids.includes(contentId)) {
        return section;
      }

      const selectedContent = options?.contents.find((content) => content.id === contentId);

      return {
        ...section,
        content_ids: [...section.content_ids, contentId],
        selected_content: selectedContent ? [...section.selected_content, selectedContent] : section.selected_content,
      };
    });
    setManualPickerContentId("");
  }

  function moveManualContent(sectionId: number, contentId: number, direction: "up" | "down") {
    updateSection(sectionId, (section) => {
      const index = section.content_ids.indexOf(contentId);
      if (index < 0) {
        return section;
      }

      const targetIndex = direction === "up" ? index - 1 : index + 1;
      if (targetIndex < 0 || targetIndex >= section.content_ids.length) {
        return section;
      }

      const nextIds = [...section.content_ids];
      const [item] = nextIds.splice(index, 1);
      nextIds.splice(targetIndex, 0, item);

      const contentMap = new Map((options?.contents ?? []).map((content) => [content.id, content]));

      return {
        ...section,
        content_ids: nextIds,
        selected_content: nextIds
          .map((id) => contentMap.get(id))
          .filter((content): content is HomeCurationContentOption => Boolean(content)),
      };
    });
  }

  function removeManualContent(sectionId: number, contentId: number) {
    updateSection(sectionId, (section) => ({
      ...section,
      content_ids: section.content_ids.filter((item) => item !== contentId),
      selected_content: section.selected_content.filter((item) => item.id !== contentId),
    }));
  }

  function toggleRuleContentType(sectionId: number, contentType: HomeCurationRuleFilters["content_types"][number]) {
    updateSection(sectionId, (section) => {
      const exists = section.rule_filters.content_types.includes(contentType);

      return {
        ...section,
        rule_filters: {
          ...section.rule_filters,
          content_types: exists
            ? section.rule_filters.content_types.filter((item) => item !== contentType)
            : [...section.rule_filters.content_types, contentType],
        },
      };
    });
  }

  function toggleRuleTaxonomy(sectionId: number, taxonomyId: number) {
    updateSection(sectionId, (section) => {
      const exists = section.rule_filters.taxonomy_ids.includes(taxonomyId);

      return {
        ...section,
        rule_filters: {
          ...section.rule_filters,
          taxonomy_ids: exists
            ? section.rule_filters.taxonomy_ids.filter((item) => item !== taxonomyId)
            : [...section.rule_filters.taxonomy_ids, taxonomyId],
        },
      };
    });
  }

  async function handleSave() {
    setIsSaving(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const response = await adminApi.updateHomeCuration({
        sections: sections.map((section, sectionIndex) => ({
          ...section,
          id: section.id && section.id > 0 ? section.id : null,
          sort_order: sectionIndex,
          content_ids: section.section_type === "content_carousel" && section.source_mode === "manual"
            ? section.content_ids
            : [],
          hero_slides: section.section_type === "hero_slider"
            ? section.hero_slides.map((slide, slideIndex) => ({
                ...slide,
                sort_order: slideIndex,
                desktop_image_url: slide.desktop_image_url || null,
                mobile_image_url: slide.mobile_image_url || null,
                content: undefined,
              }))
            : [],
        })),
      });

      setSections(response.sections.map(normalizeSection));
      setSuccessMessage("Homepage-ul a fost actualizat.");
    } catch (saveError) {
      const requestError = saveError as ApiRequestError;
      if (requestError.errors) {
        const firstError = Object.values(requestError.errors)[0]?.[0];
        setError(firstError ?? requestError.message);
      } else {
        setError(saveError instanceof Error ? saveError.message : "Nu am putut salva homepage curation.");
      }
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading) {
    return (
      <div className="flex min-h-[55vh] items-center justify-center text-sm text-muted-foreground">
        Se încarcă setările homepage-ului...
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div className="space-y-2">
          <p className="text-sm font-medium uppercase tracking-[0.24em] text-muted-foreground">Homepage</p>
          <h1 className="text-3xl font-semibold tracking-tight">Orchestrare homepage</h1>
          <p className="max-w-3xl text-sm text-muted-foreground">
            Controlezi din admin sliderul principal, carouselele editoriale și shelves dinamice bazate pe genuri,
            colecții sau badge-uri.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <Button variant="outline" onClick={() => addSection("hero_slider")} disabled={!canEdit}>
            <PlusIcon className="h-4 w-4" />
            Adaugă slider hero
          </Button>
          <Button variant="outline" onClick={() => addSection("content_carousel")} disabled={!canEdit}>
            <PlusIcon className="h-4 w-4" />
            Adaugă carusel
          </Button>
          <Button onClick={handleSave} disabled={!canEdit || isSaving}>
            {isSaving ? "Se salvează..." : "Salvează homepage-ul"}
          </Button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="pb-3">
            <CardDescription>Secțiuni</CardDescription>
            <CardTitle>{sections.length}</CardTitle>
          </CardHeader>
          <CardContent className="pt-0 text-sm text-muted-foreground">
            Blocuri hero și carusele gestionate manual din admin.
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-3">
            <CardDescription>Slide-uri hero</CardDescription>
            <CardTitle>{sections.reduce((sum, section) => sum + section.hero_slides.length, 0)}</CardTitle>
          </CardHeader>
          <CardContent className="pt-0 text-sm text-muted-foreground">
            Fiecare slide poate avea text și imagini diferite pe desktop și mobile.
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-3">
            <CardDescription>Rafturi dinamice</CardDescription>
            <CardTitle>{sections.filter((section) => section.source_mode === "dynamic").length}</CardTitle>
          </CardHeader>
          <CardContent className="pt-0 text-sm text-muted-foreground">
            Reguli după tip de content, genuri, colecții, free/paid și sortare.
          </CardContent>
        </Card>
      </div>

      {error ? (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}

      {successMessage ? (
        <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm text-emerald-600">
          {successMessage}
        </div>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
        <Card className="h-fit">
          <CardHeader>
            <CardTitle className="text-xl">Secțiuni</CardTitle>
            <CardDescription>Alege ordinea în care apar pe homepage și ce tip de shelf construiești.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {sections.length === 0 ? (
              <div className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                Nu există secțiuni încă. Adaugă un hero slider sau un carousel.
              </div>
            ) : null}

            {sections.map((section, index) => {
              const itemCount = section.section_type === "hero_slider"
                ? section.hero_slides.length
                : (section.source_mode === "manual" ? section.content_ids.length : resolveSectionPreview(section, options).length);

              return (
                <button
                  key={section.id ?? `section-${index}`}
                  type="button"
                  onClick={() => setSelectedSectionId(section.id)}
                  className={cn(
                    "w-full rounded-xl border p-4 text-left transition-colors",
                    selectedSectionId === section.id
                      ? "border-primary bg-accent/40"
                      : "border-border hover:bg-accent/30",
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold">{section.localized_title || section.name}</span>
                          <span className="rounded-full border px-2 py-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                          {section.section_type === "hero_slider" ? "Hero" : section.source_mode === "manual" ? "Manual" : "Dinamic"}
                          </span>
                      </div>
                      <p className="text-xs text-muted-foreground">{section.name}</p>
                    </div>

                    <div className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">
                      {section.active ? "Activă" : "Oprită"}
                    </div>
                  </div>

                  <div className="mt-4 flex items-center justify-between gap-3">
                    <span className="text-xs text-muted-foreground">
                      {itemCount} {section.section_type === "hero_slider" ? "slide-uri" : "titluri"}
                    </span>

                    <div className="flex items-center gap-1">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={(event) => {
                          event.stopPropagation();
                          moveSection(section.id ?? 0, "up");
                        }}
                        disabled={!canEdit || index === 0}
                      >
                        <ArrowUpIcon className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={(event) => {
                          event.stopPropagation();
                          moveSection(section.id ?? 0, "down");
                        }}
                        disabled={!canEdit || index === sections.length - 1}
                      >
                        <ArrowDownIcon className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={(event) => {
                          event.stopPropagation();
                          removeSection(section.id ?? 0);
                        }}
                        disabled={!canEdit}
                      >
                        <Trash2Icon className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </button>
              );
            })}
          </CardContent>
        </Card>

        {selectedSection ? (
          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle className="text-xl">Setări secțiune</CardTitle>
                <CardDescription>Configurează identitatea editorială și localizările pentru secțiunea selectată.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_160px_160px]">
                  <FormField
                    label="Nume intern"
                    value={selectedSection.name}
                    onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                      ...section,
                      name: event.target.value,
                    }))}
                    disabled={!canEdit}
                  />
                  <FormField
                    label="Tip"
                    type="select"
                    value={selectedSection.section_type}
                    onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                      ...section,
                      section_type: event.target.value as HomeSectionType,
                    }))}
                    disabled
                    options={options?.section_types ?? []}
                  />
                  <FormField
                    label="Stare"
                    type="toggle"
                    checked={selectedSection.active}
                    onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                      ...section,
                      active: event.target.checked,
                    }))}
                    disabled={!canEdit}
                    helperText="Secțiunile inactive rămân în admin, dar nu ies public."
                  />
                </div>

                <div className="space-y-4 rounded-xl border p-4">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <h3 className="font-medium">Copy localizat</h3>
                      <p className="text-sm text-muted-foreground">
                        Titlul și subtitlul shelf-ului sunt independente de titlurile conținutului din interior.
                      </p>
                    </div>
                    <Tabs
                      tabs={LOCALES.map((locale) => ({ id: locale, label: locale.toUpperCase() }))}
                      activeTab={activeLocale}
                      onChange={(value) => setActiveLocale(value as TaxonomyLocale)}
                    />
                  </div>

                  <div className="grid gap-4 lg:grid-cols-2">
                    <div className="space-y-2">
                      <Label>Titlu secțiune ({activeLocale.toUpperCase()})</Label>
                      <Input
                        value={selectedSection.title[activeLocale]}
                        onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                          ...section,
                          title: {
                            ...section.title,
                            [activeLocale]: event.target.value,
                          },
                        }))}
                        disabled={!canEdit}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label>Subtitlu secțiune ({activeLocale.toUpperCase()})</Label>
                      <Textarea
                        rows={3}
                        value={selectedSection.subtitle[activeLocale]}
                        onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                          ...section,
                          subtitle: {
                            ...section.subtitle,
                            [activeLocale]: event.target.value,
                          },
                        }))}
                        disabled={!canEdit}
                      />
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {selectedSection.section_type === "hero_slider" ? (
              <Card>
                <CardHeader>
                  <CardTitle className="text-xl">Slide-uri hero</CardTitle>
                  <CardDescription>
                    Fiecare slide poate folosi un title din catalog, dar și copy și artwork complet personalizate.
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  <div className="grid gap-3 rounded-xl border p-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                    <FormField
                      label="Titlu sursă"
                      type="select"
                      value={heroPickerContentId}
                      onChange={(event) => setHeroPickerContentId(event.target.value)}
                      options={[
                        { value: "", label: "Alege un titlu din catalog" },
                        ...(options?.contents ?? []).map((content) => ({
                          value: String(content.id),
                          label: `${content.title} • ${content.type.toUpperCase()} • ${content.status}`,
                        })),
                      ]}
                      disabled={!canEdit}
                    />
                    <div className="flex items-end">
                      <Button type="button" variant="outline" onClick={() => addHeroSlide(selectedSection.id ?? 0)} disabled={!canEdit}>
                        <PlusIcon className="h-4 w-4" />
                        Adaugă slide hero
                      </Button>
                    </div>
                  </div>

                  {selectedSection.hero_slides.length === 0 ? (
                    <div className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                      Nu există slide-uri încă pentru hero. Alege un titlu din catalog și adaugă primul slide.
                    </div>
                  ) : null}

                  {selectedSection.hero_slides.map((slide, index) => (
                    <div key={slide.id} className="space-y-5 rounded-2xl border p-5">
                      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div className="space-y-2">
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-semibold">
                              Slide {index + 1}
                            </span>
                            <span className="rounded-full border px-2 py-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                              {slide.active ? "Activ" : "Ascuns"}
                            </span>
                          </div>
                          <p className="text-sm text-muted-foreground">
                            Conținut sursă: {slide.content?.title ?? `Titlu #${slide.content_id}`}
                          </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => moveHeroSlide(selectedSection.id ?? 0, slide.id, "up")}
                            disabled={!canEdit || index === 0}
                          >
                            <ArrowUpIcon className="h-4 w-4" />
                          </Button>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => moveHeroSlide(selectedSection.id ?? 0, slide.id, "down")}
                            disabled={!canEdit || index === selectedSection.hero_slides.length - 1}
                          >
                            <ArrowDownIcon className="h-4 w-4" />
                          </Button>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => removeHeroSlide(selectedSection.id ?? 0, slide.id)}
                            disabled={!canEdit}
                          >
                            <Trash2Icon className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>

                      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_160px]">
                        <FormField
                          label="Titlu din catalog"
                          type="select"
                          value={String(slide.content_id)}
                          onChange={(event) => {
                            const contentId = Number(event.target.value);
                            const content = options?.contents.find((item) => item.id === contentId) ?? null;

                            updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                              ...currentSlide,
                              content_id: contentId,
                              content,
                              desktop_image_url: currentSlide.desktop_image_url || content?.hero_desktop_url || content?.backdrop_url || "",
                              mobile_image_url: currentSlide.mobile_image_url || content?.hero_mobile_url || content?.poster_url || "",
                            }));
                          }}
                          options={(options?.contents ?? []).map((content) => ({
                            value: String(content.id),
                            label: `${content.title} • ${content.type.toUpperCase()}`,
                          }))}
                          disabled={!canEdit}
                        />

                        <FormField
                          label="Vizibil"
                          type="toggle"
                          checked={slide.active}
                          onChange={(event) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                            ...currentSlide,
                            active: event.target.checked,
                          }))}
                          disabled={!canEdit}
                          helperText="Poți păstra slide-uri pregătite fără să fie publice."
                        />
                      </div>

                      <div className="grid gap-4 xl:grid-cols-2">
                        <ImageUploadField
                          label="Artwork desktop"
                          value={slide.desktop_image_url ?? ""}
                          onChange={(value) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                            ...currentSlide,
                            desktop_image_url: value,
                          }))}
                          aspectClassName="aspect-[21/9]"
                          helperText="Imagine dedicată pentru hero pe desktop."
                        />
                        <ImageUploadField
                          label="Artwork mobile"
                          value={slide.mobile_image_url ?? ""}
                          onChange={(value) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                            ...currentSlide,
                            mobile_image_url: value,
                          }))}
                          aspectClassName="aspect-[9/16]"
                          helperText="Variantă separată pentru mobile hero."
                        />
                      </div>

                      <div className="space-y-4 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-3">
                          <div>
                            <h4 className="font-medium">Copy hero localizat</h4>
                            <p className="text-sm text-muted-foreground">
                              Poți suprascrie titlul și descrierea din catalog pentru campanii sau premiere.
                            </p>
                          </div>
                          <Tabs
                            tabs={LOCALES.map((locale) => ({ id: locale, label: locale.toUpperCase() }))}
                            activeTab={activeLocale}
                            onChange={(value) => setActiveLocale(value as TaxonomyLocale)}
                          />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                          <div className="space-y-2">
                            <Label>Eyebrow ({activeLocale.toUpperCase()})</Label>
                            <Input
                              value={slide.eyebrow[activeLocale]}
                              onChange={(event) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                                ...currentSlide,
                                eyebrow: {
                                  ...currentSlide.eyebrow,
                                  [activeLocale]: event.target.value,
                                },
                              }))}
                              disabled={!canEdit}
                            />
                          </div>
                          <div className="space-y-2">
                            <Label>Titlu principal ({activeLocale.toUpperCase()})</Label>
                            <Input
                              value={slide.title[activeLocale]}
                              onChange={(event) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                                ...currentSlide,
                                title: {
                                  ...currentSlide.title,
                                  [activeLocale]: event.target.value,
                                },
                              }))}
                              disabled={!canEdit}
                            />
                          </div>
                          <div className="space-y-2 lg:col-span-2">
                            <Label>Descriere ({activeLocale.toUpperCase()})</Label>
                            <Textarea
                              rows={4}
                              value={slide.description[activeLocale]}
                              onChange={(event) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                                ...currentSlide,
                                description: {
                                  ...currentSlide.description,
                                  [activeLocale]: event.target.value,
                                },
                              }))}
                              disabled={!canEdit}
                            />
                          </div>
                          <div className="space-y-2">
                            <Label>CTA principal ({activeLocale.toUpperCase()})</Label>
                            <Input
                              value={slide.primary_cta_label[activeLocale]}
                              onChange={(event) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                                ...currentSlide,
                                primary_cta_label: {
                                  ...currentSlide.primary_cta_label,
                                  [activeLocale]: event.target.value,
                                },
                              }))}
                              disabled={!canEdit}
                            />
                          </div>
                          <div className="space-y-2">
                            <Label>CTA secundar ({activeLocale.toUpperCase()})</Label>
                            <Input
                              value={slide.secondary_cta_label[activeLocale]}
                              onChange={(event) => updateHeroSlide(selectedSection.id ?? 0, slide.id, (currentSlide) => ({
                                ...currentSlide,
                                secondary_cta_label: {
                                  ...currentSlide.secondary_cta_label,
                                  [activeLocale]: event.target.value,
                                },
                              }))}
                              disabled={!canEdit}
                            />
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : null}

            {selectedSection.section_type === "content_carousel" ? (
              <>
                <Card>
                  <CardHeader>
                    <CardTitle className="text-xl">Logică raft</CardTitle>
                    <CardDescription>
                      Alege dacă această secțiune este controlată manual sau populată automat din reguli.
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-5">
                    <div className="grid gap-4 lg:grid-cols-2">
                      <FormField
                        label="Mod sursă"
                        type="select"
                        value={selectedSection.source_mode ?? "dynamic"}
                        onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                          ...section,
                          source_mode: event.target.value as HomeSectionSourceMode,
                        }))}
                        options={options?.source_modes ?? []}
                        disabled={!canEdit}
                      />
                      <FormField
                        label="Număr maxim de titluri"
                        type="number"
                        min={1}
                        max={40}
                        value={selectedSection.limit ?? 12}
                        onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                          ...section,
                          limit: Number(event.target.value || 12),
                        }))}
                        disabled={!canEdit}
                      />
                    </div>

                    {selectedSection.source_mode === "manual" ? (
                      <div className="space-y-5 rounded-xl border p-4">
                        <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_200px]">
                          <FormField
                            label="Adaugă titlu din catalog"
                            type="select"
                            value={manualPickerContentId}
                            onChange={(event) => setManualPickerContentId(event.target.value)}
                            options={[
                              { value: "", label: "Alege un titlu" },
                              ...(options?.contents ?? [])
                                .filter((content) => !selectedSection.content_ids.includes(content.id))
                                .map((content) => ({
                                  value: String(content.id),
                                  label: `${content.title} • ${content.type.toUpperCase()} • ${content.status}`,
                                })),
                            ]}
                            disabled={!canEdit}
                          />
                          <div className="flex items-end">
                            <Button type="button" variant="outline" onClick={() => addManualContent(selectedSection.id ?? 0)} disabled={!canEdit}>
                              <PlusIcon className="h-4 w-4" />
                              Adaugă titlu
                            </Button>
                          </div>
                        </div>

                        <div className="space-y-3">
                          {selectedSection.selected_content.length === 0 ? (
                            <div className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                              Ordinea manuală este goală. Adaugă titluri în această secțiune.
                            </div>
                          ) : null}

                          {selectedSection.selected_content.map((content, index) => (
                            <div key={content.id} className="flex flex-col gap-4 rounded-xl border p-4 lg:flex-row lg:items-center lg:justify-between">
                              <div className="flex items-center gap-4">
                                <div className="h-16 w-12 overflow-hidden rounded-md border bg-muted">
                                  {content.poster_url ? (
                                    <img src={content.poster_url} alt={content.title} className="h-full w-full object-cover" />
                                  ) : (
                                    <div className="flex h-full items-center justify-center text-xs text-muted-foreground">Fără imagine</div>
                                  )}
                                </div>
                                <div className="space-y-1">
                                  <div className="flex items-center gap-2">
                                    <span className="font-medium">{content.title}</span>
                                    <span className="rounded-full border px-2 py-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                                      {content.type}
                                    </span>
                                    <span className="rounded-full border px-2 py-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                                      {content.status}
                                    </span>
                                  </div>
                                  <p className="text-sm text-muted-foreground">
                                    {content.release_year ?? "TBD"} • {content.genres.join(", ") || "Fără genuri"} • {content.lowest_price === 0 ? "Gratuit" : `$${content.lowest_price}`}
                                  </p>
                                </div>
                              </div>

                              <div className="flex items-center gap-2">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  onClick={() => moveManualContent(selectedSection.id ?? 0, content.id, "up")}
                                  disabled={!canEdit || index === 0}
                                >
                                  <ArrowUpIcon className="h-4 w-4" />
                                </Button>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  onClick={() => moveManualContent(selectedSection.id ?? 0, content.id, "down")}
                                  disabled={!canEdit || index === selectedSection.selected_content.length - 1}
                                >
                                  <ArrowDownIcon className="h-4 w-4" />
                                </Button>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  onClick={() => removeManualContent(selectedSection.id ?? 0, content.id)}
                                  disabled={!canEdit}
                                >
                                  <Trash2Icon className="h-4 w-4" />
                                </Button>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    ) : null}

                    {selectedSection.source_mode === "dynamic" ? (
                      <div className="space-y-5 rounded-xl border p-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                          <FormField
                            label="Tip acces"
                            type="select"
                            value={selectedSection.rule_filters.access}
                            onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                              ...section,
                              rule_filters: {
                                ...section.rule_filters,
                                access: event.target.value as HomeCurationRuleFilters["access"],
                              },
                            }))}
                            options={options?.access_modes ?? []}
                            disabled={!canEdit}
                          />
                          <FormField
                            label="Sortează după"
                            type="select"
                            value={selectedSection.rule_filters.sort_mode}
                            onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                              ...section,
                              rule_filters: {
                                ...section.rule_filters,
                                sort_mode: event.target.value as HomeCurationRuleFilters["sort_mode"],
                              },
                            }))}
                            options={options?.sort_modes ?? []}
                            disabled={!canEdit}
                          />
                          <FormField
                            label="Potrivire taxonomii"
                            type="select"
                            value={selectedSection.rule_filters.matching_strategy}
                            onChange={(event) => updateSection(selectedSection.id ?? 0, (section) => ({
                              ...section,
                              rule_filters: {
                                ...section.rule_filters,
                                matching_strategy: event.target.value as HomeCurationRuleFilters["matching_strategy"],
                              },
                            }))}
                            options={options?.matching_strategies ?? []}
                            disabled={!canEdit}
                          />
                        </div>

                        <div className="space-y-3">
                          <Label>Tipuri de conținut</Label>
                          <div className="flex flex-wrap gap-2">
                            {(options?.content_types ?? []).map((option) => {
                              const active = selectedSection.rule_filters.content_types.includes(option.value);

                              return (
                                <button
                                  key={option.value}
                                  type="button"
                                  onClick={() => toggleRuleContentType(selectedSection.id ?? 0, option.value)}
                                  disabled={!canEdit}
                                  className={cn(
                                    "rounded-full border px-3 py-1.5 text-sm transition-colors",
                                    active ? "border-primary bg-primary text-primary-foreground" : "border-border text-muted-foreground hover:bg-accent/40",
                                  )}
                                >
                                  {option.label}
                                </button>
                              );
                            })}
                          </div>
                        </div>

                        <div className="grid gap-3 md:grid-cols-2">
                          <button
                            type="button"
                            onClick={() => updateSection(selectedSection.id ?? 0, (section) => ({
                              ...section,
                              rule_filters: {
                                ...section.rule_filters,
                                featured_only: !section.rule_filters.featured_only,
                              },
                            }))}
                            disabled={!canEdit}
                            className={cn(
                              "rounded-xl border px-4 py-3 text-left transition-colors",
                              selectedSection.rule_filters.featured_only
                                ? "border-primary bg-primary text-primary-foreground"
                                : "border-border hover:bg-accent/30",
                            )}
                          >
                            <div className="font-medium">Doar featured</div>
                            <div className="text-sm opacity-80">Include doar titluri marcate ca featured.</div>
                          </button>
                          <button
                            type="button"
                            onClick={() => updateSection(selectedSection.id ?? 0, (section) => ({
                              ...section,
                              rule_filters: {
                                ...section.rule_filters,
                                trending_only: !section.rule_filters.trending_only,
                              },
                            }))}
                            disabled={!canEdit}
                            className={cn(
                              "rounded-xl border px-4 py-3 text-left transition-colors",
                              selectedSection.rule_filters.trending_only
                                ? "border-primary bg-primary text-primary-foreground"
                                : "border-border hover:bg-accent/30",
                            )}
                          >
                            <div className="font-medium">Doar trending</div>
                            <div className="text-sm opacity-80">Păstrează doar titlurile cu flag de trend.</div>
                          </button>
                        </div>

                        <div className="space-y-4">
                          <div>
                            <h3 className="font-medium">Taxonomy filters</h3>
                            <h3 className="font-medium">Filtre taxonomii</h3>
                            <p className="text-sm text-muted-foreground">
                              Poți combina genuri, colecții, tag-uri și badge-uri într-un singur shelf dinamic.
                            </p>
                          </div>

                          <div className="space-y-4">
                            {TAXONOMY_GROUP_ORDER.map((group) => {
                              const items = options?.taxonomies?.[group] ?? [];
                              if (items.length === 0) {
                                return null;
                              }

                              return (
                                <div key={group} className="space-y-2">
                                  <Label className="capitalize">{group.replace("_", " ")}</Label>
                                  <div className="flex flex-wrap gap-2">
                                    {items.map((taxonomy) => {
                                      const active = selectedSection.rule_filters.taxonomy_ids.includes(taxonomy.id);

                                      return (
                                        <button
                                          key={taxonomy.id}
                                          type="button"
                                          onClick={() => toggleRuleTaxonomy(selectedSection.id ?? 0, taxonomy.id)}
                                          disabled={!canEdit}
                                          className={cn(
                                            "rounded-full border px-3 py-1.5 text-sm transition-colors",
                                            active
                                              ? "border-primary bg-primary text-primary-foreground"
                                              : badgeClassName(taxonomy.color),
                                          )}
                                          style={!active && taxonomy.color ? { backgroundColor: `${taxonomy.color}12`, borderColor: taxonomy.color, color: taxonomy.color } : undefined}
                                        >
                                          {taxonomy.localized_name}
                                        </button>
                                      );
                                    })}
                                  </div>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                    ) : null}
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle className="text-xl">Previzualizare live</CardTitle>
                    <CardDescription>
                      Preview rapid din admin pentru titlurile care vor intra în shelf după regulile curente.
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-3">
                    {selectedPreview.length === 0 ? (
                      <div className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                        Nu există titluri care să corespundă configurației curente.
                      </div>
                    ) : null}

                    {selectedPreview.map((content, index) => (
                      <div key={`${content.id}-${index}`} className="flex flex-col gap-4 rounded-xl border p-4 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex items-center gap-4">
                          <div className="h-16 w-12 overflow-hidden rounded-md border bg-muted">
                            {content.poster_url ? (
                              <img src={content.poster_url} alt={content.title} className="h-full w-full object-cover" />
                            ) : (
                              <div className="flex h-full items-center justify-center text-xs text-muted-foreground">Fără imagine</div>
                            )}
                          </div>
                          <div className="space-y-1">
                            <div className="flex items-center gap-2">
                              <span className="font-medium">{content.title}</span>
                              <span className="rounded-full border px-2 py-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                                {content.type}
                              </span>
                              {content.is_trending ? (
                                <span className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                                  <SparklesIcon className="h-3 w-3" />
                                  În trend
                                </span>
                              ) : null}
                            </div>
                            <p className="text-sm text-muted-foreground">
                              {content.release_year ?? "TBD"} • {content.genres.join(", ") || "Fără genuri"} • {content.lowest_price === 0 ? "Gratuit" : `$${content.lowest_price}`}
                            </p>
                          </div>
                        </div>

                        <div className="text-sm text-muted-foreground">{index + 1}</div>
                      </div>
                    ))}
                  </CardContent>
                </Card>
              </>
            ) : null}
          </div>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle className="text-xl">Selectează o secțiune</CardTitle>
              <CardDescription>Alege o secțiune din stânga ca să începi configurarea homepage-ului.</CardDescription>
            </CardHeader>
          </Card>
        )}
      </div>
    </div>
  );
}
