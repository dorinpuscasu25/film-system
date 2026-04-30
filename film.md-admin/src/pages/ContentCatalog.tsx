import React, { useEffect, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { EditIcon, EyeIcon, FilmIcon, PlusIcon, TvIcon, TrashIcon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { DataTable } from "../components/shared/DataTable";
import { Tabs } from "../components/shared/Tabs";
import { Button } from "../components/ui/button";
import { Card, CardContent } from "../components/ui/card";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import { AdminContent, AdminContentStatus } from "../types";

type ContentRow = AdminContent & {
  search_index: string;
};

function formatMoney(content: AdminContent) {
  if (content.is_free) {
    return "Gratuit";
  }

  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: content.currency || "USD",
  }).format(content.lowest_price || content.price_amount || 0);
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

export function ContentCatalog() {
  const { can, navigate } = useAdmin();
  const { t } = useTranslation();
  const [items, setItems] = useState<AdminContent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<"all" | "movie" | "series">("all");
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [countryFilter, setCountryFilter] = useState<string>("all");
  const [genreFilter, setGenreFilter] = useState<string>("all");

  async function loadData() {
    setIsLoading(true);
    setError(null);

    try {
      const response = await adminApi.getContentIndex();
      setItems(response.items);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : t("movies.messages.load_error"));
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  const genreOptions = useMemo(
    () =>
      Array.from(new Set(items.flatMap((item) => item.genres.map((genre) => genre.localized_name))))
        .filter(Boolean)
        .sort((left, right) => left.localeCompare(right)),
    [items],
  );

  const filteredItems = useMemo(() => {
    return items.filter((item) => {
      const matchesType = activeTab === "all" ? true : item.type === activeTab;
      const matchesStatus = statusFilter === "all" ? true : item.status === statusFilter;
      const matchesCountry = countryFilter === "all" ? true : item.country_code === countryFilter;
      const matchesGenre =
        genreFilter === "all" ? true : item.genres.some((genre) => genre.localized_name === genreFilter);

      return matchesType && matchesStatus && matchesCountry && matchesGenre;
    });
  }, [activeTab, countryFilter, genreFilter, items, statusFilter]);

  const tableRows = useMemo<ContentRow[]>(
    () =>
      filteredItems.map((item) => ({
        ...item,
        search_index: [
          item.slug,
          item.localized_title,
          item.original_title,
          item.country_name ?? "",
          ...item.genres.map((genre) => genre.localized_name),
          ...item.tags.map((tag) => tag.localized_name),
          ...item.badges.map((badge) => badge.localized_name),
        ].join(" "),
      })),
    [filteredItems],
  );

  async function handleDelete(item: AdminContent) {
    const confirmed = window.confirm(t("movies.messages.delete_confirm", { title: item.localized_title }));

    if (!confirmed) {
      return;
    }

    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.deleteContent(item.id);
      setSuccessMessage(t("movies.messages.delete_success"));
      await loadData();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : t("movies.messages.delete_error"));
    }
  }

  const columns = [
    {
      key: "title",
      header: t("movies.table.title"),
      render: (item: ContentRow) => (
        <div className="flex items-center gap-3">
          <img
            src={item.poster_url}
            alt={item.localized_title}
            className="h-16 w-11 rounded-md border object-cover"
          />
          <div className="space-y-1">
            <div className="font-medium">{item.localized_title}</div>
            <div className="text-xs text-muted-foreground">{item.original_title}</div>
            <div className="text-xs uppercase tracking-wide text-muted-foreground">{item.slug}</div>
          </div>
        </div>
      ),
    },
    {
      key: "type",
      header: t("movies.table.type"),
      render: (item: ContentRow) => (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          {item.type === "movie" ? <FilmIcon className="h-4 w-4" /> : <TvIcon className="h-4 w-4" />}
          <span>{t(`movies.type.${item.type}`)}</span>
        </div>
      ),
    },
    {
      key: "flags",
      header: t("movies.table.flags"),
      render: (item: ContentRow) => (
        <div className="flex flex-wrap gap-2">
          {item.is_featured ? <Badge variant="featured">{t("movies.badges.featured")}</Badge> : null}
          {item.is_trending ? <Badge variant="ready">{t("movies.badges.trending")}</Badge> : null}
          {item.is_free ? <Badge variant="free">{t("movies.badges.free")}</Badge> : <Badge variant="paid">{formatMoney(item)}</Badge>}
          {item.offers_count > 0 ? <Badge variant="draft">{t("movies.badges.offers", { count: item.offers_count })}</Badge> : null}
        </div>
      ),
    },
    {
      key: "release_year",
      header: t("movies.table.year"),
      render: (item: ContentRow) => item.release_year ?? "N/A",
    },
    {
      key: "genres",
      header: t("movies.table.genres"),
      render: (item: ContentRow) => (
        <span className="text-sm text-muted-foreground">
          {item.genres.map((genre) => genre.localized_name).join(", ") || t("movies.messages.no_genres")}
        </span>
      ),
    },
    {
      key: "status",
      header: t("movies.table.status"),
      render: (item: ContentRow) => <Badge variant={statusVariant(item.status)}>{t(`movies.status.${item.status}`)}</Badge>,
    },
    {
      key: "actions",
      header: "",
      render: (item: ContentRow) => (
        <div className="flex items-center justify-end gap-2">
          <Button
            variant="ghost"
            size="icon"
            onClick={(event) => {
              event.stopPropagation();
              navigate("editor", String(item.id), [t("movies.breadcrumb"), item.localized_title]);
            }}
            title={t("movies.actions.edit")}
          >
            <EditIcon className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={(event) => {
              event.stopPropagation();
              window.open(`/movie/${item.slug}`, "_blank");
            }}
            title={t("movies.actions.preview")}
          >
            <EyeIcon className="h-4 w-4" />
          </Button>
          {can("content.delete") ? (
            <Button
              variant="ghost"
              size="icon"
              onClick={(event) => {
                event.stopPropagation();
                void handleDelete(item);
              }}
              title={t("movies.actions.delete")}
            >
              <TrashIcon className="h-4 w-4" />
            </Button>
          ) : null}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div className="page-header">
          <h1 className="page-title">{t("movies.title")}</h1>
          <p className="page-description">
            {t("movies.description")}
          </p>
        </div>

        {can("content.create") ? (
          <Button onClick={() => navigate("editor", "new", [t("movies.breadcrumb"), t("movies.new_title")])}>
            <PlusIcon className="h-4 w-4" />
            {t("movies.create")}
          </Button>
        ) : null}
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
        <CardContent className="space-y-4 p-4">
          <Tabs
            tabs={[
              { id: "all", label: t("movies.all"), count: items.length },
              { id: "movie", label: t("movies.movies"), count: items.filter((item) => item.type === "movie").length },
              { id: "series", label: t("movies.series"), count: items.filter((item) => item.type === "series").length },
            ]}
            activeTab={activeTab}
            onChange={(value) => setActiveTab(value as "all" | "movie" | "series")}
          />

          <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
            <select
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="all">{t("movies.filters.all_statuses")}</option>
              <option value="draft">{t("movies.status.draft")}</option>
              <option value="ready">{t("movies.status.ready")}</option>
              <option value="published">{t("movies.status.published")}</option>
              <option value="archived">{t("movies.status.archived")}</option>
            </select>

            <select
              value={countryFilter}
              onChange={(event) => setCountryFilter(event.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="all">{t("movies.filters.all_countries")}</option>
              {Array.from(new Set(items.map((item) => `${item.country_code ?? ""}|${item.country_name ?? ""}`)))
                .filter((value) => value !== "|")
                .map((value) => {
                  const [code, name] = value.split("|");
                  return (
                    <option key={code} value={code}>
                      {name}
                    </option>
                  );
                })}
            </select>

            <select
              value={genreFilter}
              onChange={(event) => setGenreFilter(event.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="all">{t("movies.filters.all_genres")}</option>
              {genreOptions.map((genre) => (
                <option key={genre} value={genre}>
                  {genre}
                </option>
              ))}
            </select>
          </div>

          {isLoading ? (
            <div className="py-16 text-center text-sm text-muted-foreground">{t("movies.messages.loading")}</div>
          ) : (
            <DataTable
              data={tableRows}
              columns={columns}
              keyExtractor={(item) => String(item.id)}
              searchPlaceholder={t("movies.search_placeholder")}
              onRowClick={(item) => navigate("editor", String(item.id), [t("movies.breadcrumb"), item.localized_title])}
            />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
