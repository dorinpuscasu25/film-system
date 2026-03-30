import React, { useEffect, useMemo, useState } from "react";
import { CheckIcon, EditIcon, GlobeIcon, HashIcon, PaletteIcon, PlusIcon, TrashIcon } from "lucide-react";
import { Badge as UiBadge } from "../components/ui/badge";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../components/ui/tabs";
import { Badge } from "../components/shared/Badge";
import { DataTable } from "../components/shared/DataTable";
import { FormField } from "../components/shared/FormField";
import { Modal } from "../components/shared/Modal";
import { useAdmin } from "../hooks/useAdmin";
import { ApiRequestError, adminApi } from "../lib/api";
import { cn } from "../lib/utils";
import {
  AdminTaxonomy,
  LocalizedText,
  TaxonomyLocale,
  TaxonomyLocaleOption,
  TaxonomyPayload,
  TaxonomyType,
  TaxonomyTypeOption,
} from "../types";

type TaxonomyFormState = {
  type: TaxonomyType;
  slug: string;
  active: boolean;
  color: string;
  sort_order: number;
  name: LocalizedText;
  description: LocalizedText;
};

type TaxonomyTableRow = AdminTaxonomy & {
  search_index: string;
};

const FALLBACK_TYPES: TaxonomyTypeOption[] = [
  { value: "genre", label: "Genres" },
  { value: "collection", label: "Collections" },
  { value: "tag", label: "Tags" },
  { value: "badge", label: "Badges" },
];

const FALLBACK_LOCALES: TaxonomyLocaleOption[] = [
  { value: "ro", label: "RO" },
  { value: "ru", label: "RU" },
  { value: "en", label: "EN" },
];

const TYPE_DESCRIPTIONS: Record<TaxonomyType, string> = {
  genre: "Genurile sunt folosite în navigare, recomandări și filtrele principale din catalog.",
  collection: "Colecțiile grupează titlurile în seturi curatoriate pentru homepage, landing pages sau campanii.",
  tag: "Tag-urile sunt etichete editoriale flexibile pentru search, landing-uri tematice și campanii de merchandising.",
  badge: "Badges sunt marcaje vizuale pentru storefront. Culoarea și label-ul merg direct în UI-ul public.",
};

const TYPE_SINGULAR: Record<TaxonomyType, string> = {
  genre: "genre",
  collection: "collection",
  tag: "tag",
  badge: "badge",
};

function createEmptyLocalizedText(): LocalizedText {
  return {
    ro: "",
    ru: "",
    en: "",
  };
}

function createEmptyTaxonomyMap(): Record<TaxonomyType, AdminTaxonomy[]> {
  return {
    genre: [],
    collection: [],
    tag: [],
    badge: [],
  };
}

function createEmptyFormState(type: TaxonomyType): TaxonomyFormState {
  return {
    type,
    slug: "",
    active: true,
    color: type === "badge" ? "#0F172A" : "",
    sort_order: 0,
    name: createEmptyLocalizedText(),
    description: createEmptyLocalizedText(),
  };
}

function normalizeTaxonomies(taxonomies?: Partial<Record<TaxonomyType, AdminTaxonomy[]>>) {
  const next = createEmptyTaxonomyMap();

  if (!taxonomies) {
    return next;
  }

  for (const type of Object.keys(next) as TaxonomyType[]) {
    next[type] = taxonomies[type] ?? [];
  }

  return next;
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

function formatDate(value: string | null) {
  if (!value) {
    return "N/A";
  }

  return new Date(value).toLocaleDateString();
}

function hexToRgba(hex: string, alpha: number) {
  const value = hex.replace("#", "");
  const normalized = value.length >= 6 ? value.slice(0, 6) : value;

  if (!/^[A-Fa-f0-9]{6}$/.test(normalized)) {
    return undefined;
  }

  const numeric = Number.parseInt(normalized, 16);
  const red = (numeric >> 16) & 255;
  const green = (numeric >> 8) & 255;
  const blue = numeric & 255;

  return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
}

function TaxonomyColorBadge({ label, color }: { label: string; color?: string | null }) {
  const trimmedColor = color?.trim() || null;
  const backgroundColor = trimmedColor ? hexToRgba(trimmedColor, 0.12) : undefined;

  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium",
        !trimmedColor && "border-border bg-muted text-muted-foreground",
      )}
      style={
        trimmedColor
          ? {
              borderColor: trimmedColor,
              backgroundColor,
              color: trimmedColor,
            }
          : undefined
      }
    >
      {label}
    </span>
  );
}

function SummaryCard({
  icon: Icon,
  label,
  value,
  description,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string | number;
  description: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-start justify-between p-5">
        <div className="space-y-1.5">
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="text-2xl font-semibold tracking-tight">{value}</p>
          <p className="text-xs text-muted-foreground">{description}</p>
        </div>
        <div className="flex h-10 w-10 items-center justify-center rounded-lg border bg-muted/50">
          <Icon className="h-4 w-4 text-muted-foreground" />
        </div>
      </CardContent>
    </Card>
  );
}

export function Taxonomies() {
  const { can, currentPage, currentUser } = useAdmin();
  const initialType = currentPage === "collections" ? "collection" : "genre";
  const [typeOptions, setTypeOptions] = useState<TaxonomyTypeOption[]>(FALLBACK_TYPES);
  const [localeOptions, setLocaleOptions] = useState<TaxonomyLocaleOption[]>(FALLBACK_LOCALES);
  const [taxonomies, setTaxonomies] = useState<Record<TaxonomyType, AdminTaxonomy[]>>(createEmptyTaxonomyMap);
  const [activeType, setActiveType] = useState<TaxonomyType>(initialType);
  const [activeLocale, setActiveLocale] = useState<TaxonomyLocale>(currentUser?.preferred_locale ?? "ro");
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editingTaxonomy, setEditingTaxonomy] = useState<AdminTaxonomy | null>(null);
  const [formLocale, setFormLocale] = useState<TaxonomyLocale>(currentUser?.preferred_locale ?? "ro");
  const [formState, setFormState] = useState<TaxonomyFormState>(createEmptyFormState(initialType));
  const [validationErrors, setValidationErrors] = useState<Record<string, string[]>>({});
  const [modalError, setModalError] = useState<string | null>(null);

  async function loadData() {
    setIsLoading(true);
    setError(null);

    try {
      const response = await adminApi.getTaxonomies();
      setTypeOptions(response.types.length > 0 ? response.types : FALLBACK_TYPES);
      setLocaleOptions(response.locales.length > 0 ? response.locales : FALLBACK_LOCALES);
      setTaxonomies(normalizeTaxonomies(response.taxonomies));
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca taxonomiile.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  useEffect(() => {
    setActiveType(currentPage === "collections" ? "collection" : "genre");
  }, [currentPage]);

  useEffect(() => {
    if (currentUser?.preferred_locale) {
      setActiveLocale(currentUser.preferred_locale);
      setFormLocale(currentUser.preferred_locale);
    }
  }, [currentUser?.preferred_locale]);

  const currentTypeItems = taxonomies[activeType] ?? [];
  const currentTypeLabel = typeOptions.find((type) => type.value === activeType)?.label ?? "Taxonomies";

  const tableRows = useMemo<TaxonomyTableRow[]>(
    () =>
      currentTypeItems.map((taxonomy) => ({
        ...taxonomy,
        search_index: [
          taxonomy.slug,
          taxonomy.localized_name,
          taxonomy.localized_description ?? "",
          taxonomy.color ?? "",
          ...Object.values(taxonomy.name),
          ...Object.values(taxonomy.description),
        ].join(" "),
      })),
    [currentTypeItems],
  );

  const completeLocalizations = useMemo(
    () =>
      currentTypeItems.filter((taxonomy) =>
        localeOptions.every((locale) => taxonomy.name[locale.value].trim().length > 0),
      ).length,
    [currentTypeItems, localeOptions],
  );

  const describedTaxonomies = useMemo(
    () =>
      currentTypeItems.filter((taxonomy) =>
        localeOptions.some((locale) => taxonomy.description[locale.value].trim().length > 0),
      ).length,
    [currentTypeItems, localeOptions],
  );

  const activeItemsCount = useMemo(
    () => currentTypeItems.filter((taxonomy) => taxonomy.active).length,
    [currentTypeItems],
  );

  const coloredItemsCount = useMemo(
    () => currentTypeItems.filter((taxonomy) => Boolean(taxonomy.color)).length,
    [currentTypeItems],
  );

  const columns = useMemo(() => {
    const baseColumns = [
      {
        key: "name",
        header: "Localized label",
        render: (taxonomy: TaxonomyTableRow) => (
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{taxonomy.name[activeLocale] || taxonomy.localized_name}</span>
              {taxonomy.type === "badge" && taxonomy.color ? (
                <TaxonomyColorBadge label="Preview" color={taxonomy.color} />
              ) : null}
            </div>
            <div className="text-xs uppercase tracking-wide text-muted-foreground">{taxonomy.slug}</div>
            <p className="max-w-xl text-xs text-muted-foreground">
              {taxonomy.description[activeLocale] || taxonomy.localized_description || "No description yet."}
            </p>
          </div>
        ),
      },
      {
        key: "translations",
        header: "Locales",
        render: (taxonomy: TaxonomyTableRow) => (
          <div className="flex flex-wrap gap-2">
            {localeOptions.map((locale) => {
              const isFilled = taxonomy.name[locale.value].trim().length > 0;

              return (
                <UiBadge
                  key={`${taxonomy.id}-${locale.value}`}
                  variant="outline"
                  className={cn(
                    "font-medium",
                    isFilled
                      ? "border-emerald-200 bg-emerald-50 text-emerald-700"
                      : "border-border bg-muted text-muted-foreground",
                  )}
                >
                  {locale.label}
                </UiBadge>
              );
            })}
          </div>
        ),
      },
      {
        key: "content_count",
        header: "Content",
        render: (taxonomy: TaxonomyTableRow) => taxonomy.content_count,
      },
      {
        key: "sort_order",
        header: "Sort",
        render: (taxonomy: TaxonomyTableRow) => taxonomy.sort_order,
      },
      {
        key: "active",
        header: "Status",
        render: (taxonomy: TaxonomyTableRow) => (
          <Badge variant={taxonomy.active ? "published" : "archived"}>
            {taxonomy.active ? "Active" : "Inactive"}
          </Badge>
        ),
      },
      {
        key: "updated_at",
        header: "Updated",
        render: (taxonomy: TaxonomyTableRow) => formatDate(taxonomy.updated_at),
      },
      {
        key: "actions",
        header: "",
        render: (taxonomy: TaxonomyTableRow) => (
          <div className="flex justify-end gap-2">
            {can("taxonomies.edit") ? (
              <Button
                variant="ghost"
                size="icon"
                title="Edit taxonomy"
                onClick={(event) => {
                  event.stopPropagation();
                  setSuccessMessage(null);
                  setValidationErrors({});
                  setModalError(null);
                  setEditingTaxonomy(taxonomy);
                  setFormState({
                    type: taxonomy.type,
                    slug: taxonomy.slug,
                    active: taxonomy.active,
                    color: taxonomy.color ?? "",
                    sort_order: taxonomy.sort_order,
                    name: { ...taxonomy.name },
                    description: { ...taxonomy.description },
                  });
                  setFormLocale(activeLocale);
                  setIsModalOpen(true);
                }}
              >
                <EditIcon className="h-4 w-4" />
              </Button>
            ) : null}

            {can("taxonomies.delete") ? (
              <Button
                variant="ghost"
                size="icon"
                title="Delete taxonomy"
                onClick={(event) => {
                  event.stopPropagation();
                  void handleDelete(taxonomy);
                }}
              >
                <TrashIcon className="h-4 w-4" />
              </Button>
            ) : null}

          </div>
        ),
      },
    ];

    return baseColumns;
  }, [activeLocale, can, localeOptions]);

  function getFieldError(field: string) {
    return validationErrors[field]?.[0];
  }

  function handleCreate() {
    setEditingTaxonomy(null);
    setSuccessMessage(null);
    setValidationErrors({});
    setModalError(null);
    setFormLocale(activeLocale);
    setFormState(createEmptyFormState(activeType));
    setIsModalOpen(true);
  }

  function updateLocalizedField(field: "name" | "description", locale: TaxonomyLocale, value: string) {
    setFormState((current) => {
      const nextState: TaxonomyFormState = {
        ...current,
        [field]: {
          ...current[field],
          [locale]: value,
        },
      };

      if (field === "name" && !editingTaxonomy && locale === "ro" && current.slug.trim().length === 0) {
        nextState.slug = slugify(value);
      }

      return nextState;
    });
  }

  function validateForm() {
    const nextErrors: Record<string, string[]> = {};

    if (!formState.slug.trim()) {
      nextErrors.slug = ["Slug-ul este obligatoriu."];
    }

    for (const locale of localeOptions) {
      if (!formState.name[locale.value].trim()) {
        nextErrors[`name.${locale.value}`] = ["Numele este obligatoriu pentru această limbă."];
      }
    }

    if (formState.type === "badge" && !formState.color.trim()) {
      nextErrors.color = ["Badge-urile trebuie să aibă o culoare."];
    }

    return nextErrors;
  }

  function payloadFromForm(): TaxonomyPayload {
    return {
      type: formState.type,
      slug: formState.slug.trim(),
      active: formState.active,
      color: formState.color.trim() || null,
      sort_order: Number(formState.sort_order) || 0,
      name: {
        ro: formState.name.ro.trim(),
        ru: formState.name.ru.trim(),
        en: formState.name.en.trim(),
      },
      description: {
        ro: formState.description.ro.trim(),
        ru: formState.description.ru.trim(),
        en: formState.description.en.trim(),
      },
    };
  }

  async function handleSave() {
    const clientErrors = validateForm();
    if (Object.keys(clientErrors).length > 0) {
      setValidationErrors(clientErrors);
      setModalError("Completează câmpurile obligatorii pentru toate limbile.");
      if (clientErrors["name.ro"]) setFormLocale("ro");
      else if (clientErrors["name.ru"]) setFormLocale("ru");
      else if (clientErrors["name.en"]) setFormLocale("en");
      return;
    }

    setIsSubmitting(true);
    setValidationErrors({});
    setModalError(null);
    setError(null);
    setSuccessMessage(null);

    try {
      const payload = payloadFromForm();

      if (editingTaxonomy) {
        await adminApi.updateTaxonomy(editingTaxonomy.id, payload);
        setSuccessMessage("Taxonomia a fost actualizată.");
      } else {
        await adminApi.createTaxonomy(payload);
        setSuccessMessage("Taxonomia a fost creată.");
      }

      setActiveType(payload.type);
      setIsModalOpen(false);
      await loadData();
    } catch (submitError) {
      const apiError = submitError as ApiRequestError;
      setValidationErrors(apiError.errors ?? {});
      setModalError(apiError.message ?? "Nu am putut salva taxonomia.");

      if (apiError.errors?.["name.ro"]) setFormLocale("ro");
      else if (apiError.errors?.["name.ru"]) setFormLocale("ru");
      else if (apiError.errors?.["name.en"]) setFormLocale("en");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleDelete(taxonomy: AdminTaxonomy) {
    const confirmed = window.confirm(
      `Ștergi ${TYPE_SINGULAR[taxonomy.type]} "${taxonomy.localized_name}"? Acțiunea nu poate fi anulată.`,
    );

    if (!confirmed) {
      return;
    }

    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.deleteTaxonomy(taxonomy.id);
      setSuccessMessage("Taxonomia a fost ștearsă.");
      await loadData();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Nu am putut șterge taxonomia.");
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div className="page-header">
          <h1 className="page-title">Taxonomies</h1>
          <p className="page-description">
            Genuri, colecții, tag-uri și badges multilingve, gata pentru consum pe storefront și în filtrele publice.
          </p>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <Tabs value={activeLocale} onValueChange={(value) => setActiveLocale(value as TaxonomyLocale)}>
            <TabsList className="grid w-full grid-cols-3 sm:w-[220px]">
              {localeOptions.map((locale) => (
                <TabsTrigger key={locale.value} value={locale.value}>
                  {locale.label}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>

          {can("taxonomies.create") ? (
            <Button onClick={handleCreate}>
              <PlusIcon className="h-4 w-4" />
              Add taxonomy
            </Button>
          ) : null}
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

      <Tabs value={activeType} onValueChange={(value) => setActiveType(value as TaxonomyType)} className="space-y-4">
        <TabsList className="grid h-auto w-full grid-cols-2 gap-2 bg-transparent p-0 lg:grid-cols-4">
          {typeOptions.map((type) => (
            <TabsTrigger
              key={type.value}
              value={type.value}
              className="h-auto rounded-lg border bg-background px-4 py-3 data-[state=active]:border-foreground"
            >
              {type.label}
            </TabsTrigger>
          ))}
        </TabsList>

        <TabsContent value={activeType} className="space-y-4">
          <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
            <SummaryCard
              icon={HashIcon}
              label={`${currentTypeLabel} total`}
              value={currentTypeItems.length}
              description="Numărul total de intrări pentru tipul selectat."
            />
            <SummaryCard
              icon={CheckIcon}
              label="Active"
              value={activeItemsCount}
              description="Intrări vizibile și pregătite pentru folosire."
            />
            <SummaryCard
              icon={GlobeIcon}
              label="Localized"
              value={completeLocalizations}
              description="Au label complet în RO, RU și EN."
            />
            <SummaryCard
              icon={PaletteIcon}
              label={activeType === "badge" ? "Custom colors" : "With description"}
              value={activeType === "badge" ? coloredItemsCount : describedTaxonomies}
              description={
                activeType === "badge"
                  ? "Badges cu stil vizual gata de pus pe site."
                  : "Intrări care au și context editorial, nu doar nume."
              }
            />
          </div>

          <Card>
            <CardHeader className="border-b pb-4">
              <CardTitle className="text-xl">{currentTypeLabel}</CardTitle>
              <CardDescription>{TYPE_DESCRIPTIONS[activeType]}</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              {isLoading ? (
                <div className="px-6 py-16 text-center text-sm text-muted-foreground">
                  Se încarcă taxonomiile...
                </div>
              ) : (
                <DataTable
                  data={tableRows}
                  columns={columns}
                  keyExtractor={(taxonomy) => String(taxonomy.id)}
                  searchPlaceholder={`Search ${currentTypeLabel.toLowerCase()}...`}
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Modal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        size="xl"
        title={editingTaxonomy ? `Edit ${TYPE_SINGULAR[formState.type]}` : `Add new ${TYPE_SINGULAR[formState.type]}`}
        footer={
          <>
            <Button variant="outline" onClick={() => setIsModalOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => void handleSave()} disabled={isSubmitting}>
              {isSubmitting ? "Saving..." : "Save taxonomy"}
            </Button>
          </>
        }
      >
        <div className="space-y-6">
          {modalError ? (
            <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
              {modalError}
            </div>
          ) : null}

          <div className="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
            <Card>
              <CardHeader className="border-b pb-4">
                <CardTitle className="text-lg">Localized content</CardTitle>
                <CardDescription>
                  Completează label-urile și descrierile pe limbi. Valorile acestea sunt salvate în JSON și pot fi
                  trimise direct spre frontend.
                </CardDescription>
              </CardHeader>
              <CardContent className="pt-6">
                <Tabs value={formLocale} onValueChange={(value) => setFormLocale(value as TaxonomyLocale)} className="space-y-6">
                  <TabsList className="grid h-auto w-full grid-cols-3">
                    {localeOptions.map((locale) => {
                      const isFilled = formState.name[locale.value].trim().length > 0;

                      return (
                        <TabsTrigger key={locale.value} value={locale.value} className="gap-2">
                          <span>{locale.label}</span>
                          <span
                            className={cn(
                              "h-2 w-2 rounded-full",
                              isFilled ? "bg-emerald-500" : "bg-border",
                            )}
                          />
                        </TabsTrigger>
                      );
                    })}
                  </TabsList>

                  {localeOptions.map((locale) => (
                    <TabsContent key={locale.value} value={locale.value} className="space-y-4">
                      <FormField
                        label={`Name (${locale.label})`}
                        value={formState.name[locale.value]}
                        error={getFieldError(`name.${locale.value}`)}
                        onChange={(event) => updateLocalizedField("name", locale.value, event.target.value)}
                        placeholder={`Enter ${TYPE_SINGULAR[formState.type]} name in ${locale.label}`}
                      />

                      <FormField
                        label={`Description (${locale.label})`}
                        type="textarea"
                        rows={6}
                        value={formState.description[locale.value]}
                        error={getFieldError(`description.${locale.value}`)}
                        onChange={(event) => updateLocalizedField("description", locale.value, event.target.value)}
                        placeholder={`Optional description for ${locale.label}`}
                      />
                    </TabsContent>
                  ))}
                </Tabs>
              </CardContent>
            </Card>

            <div className="space-y-4">
              <Card>
                <CardHeader className="border-b pb-4">
                  <CardTitle className="text-lg">Shared settings</CardTitle>
                  <CardDescription>Setări comune pentru structură, ordonare și disponibilitate.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 pt-6">
                  <FormField
                    label="Taxonomy type"
                    type="select"
                    value={formState.type}
                    onChange={(event) => {
                      const nextType = event.target.value as TaxonomyType;
                      setFormState((current) => ({
                        ...current,
                        type: nextType,
                        color: nextType === "badge" ? current.color || "#0F172A" : current.color,
                      }));
                    }}
                    options={typeOptions.map((type) => ({ label: type.label, value: type.value }))}
                  />

                  <FormField
                    label="Slug"
                    value={formState.slug}
                    error={getFieldError("slug")}
                    onChange={(event) =>
                      setFormState((current) => ({
                        ...current,
                        slug: slugify(event.target.value),
                      }))
                    }
                    helperText="Slug-ul este folosit în API, filtre și URL-uri."
                  />

                  <FormField
                    label="Sort order"
                    type="number"
                    min={0}
                    value={formState.sort_order}
                    error={getFieldError("sort_order")}
                    onChange={(event) =>
                      setFormState((current) => ({
                        ...current,
                        sort_order: Number(event.target.value || 0),
                      }))
                    }
                    helperText="Intrările cu valoare mai mică apar primele."
                  />

                  <FormField
                    label="Active"
                    type="toggle"
                    checked={formState.active}
                    helperText="Doar intrările active vor putea fi afișate în storefront."
                    onChange={(event) =>
                      setFormState((current) => ({
                        ...current,
                        active: event.target.checked,
                      }))
                    }
                  />
                </CardContent>
              </Card>

              {formState.type === "badge" ? (
                <Card>
                  <CardHeader className="border-b pb-4">
                    <CardTitle className="text-lg">Badge color</CardTitle>
                    <CardDescription>Culoarea este folosită direct în UI pentru badges și highlight-uri.</CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4 pt-6">
                    <div className="grid grid-cols-[88px_minmax(0,1fr)] gap-3">
                      <div className="space-y-2">
                        <label className="text-sm font-medium">Picker</label>
                        <input
                          type="color"
                          value={formState.color || "#0F172A"}
                          onChange={(event) =>
                            setFormState((current) => ({
                              ...current,
                              color: event.target.value,
                            }))
                          }
                          className="h-11 w-full rounded-md border border-input bg-background p-1"
                        />
                      </div>

                      <div className="space-y-2">
                        <label className="text-sm font-medium">Hex value</label>
                        <Input
                          value={formState.color}
                          onChange={(event) =>
                            setFormState((current) => ({
                              ...current,
                              color: event.target.value.toUpperCase(),
                            }))
                          }
                          placeholder="#0F172A"
                        />
                      </div>
                    </div>

                    {getFieldError("color") ? <p className="text-sm text-destructive">{getFieldError("color")}</p> : null}

                    <div className="rounded-lg border bg-muted/30 p-4">
                      <div className="flex items-center justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">Live preview</p>
                          <p className="text-xs text-muted-foreground">Cum poate arăta badge-ul pe storefront.</p>
                        </div>
                        <TaxonomyColorBadge
                          label={formState.name[formLocale].trim() || formState.name.ro.trim() || "Badge preview"}
                          color={formState.color}
                        />
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ) : null}
            </div>
          </div>
        </div>
      </Modal>
    </div>
  );
}
