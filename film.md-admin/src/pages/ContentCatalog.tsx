import React, { useEffect, useMemo, useState } from "react";
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
    return "Free";
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
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca catalogul.");
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
    const confirmed = window.confirm(`Ștergi titlul "${item.localized_title}"?`);

    if (!confirmed) {
      return;
    }

    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.deleteContent(item.id);
      setSuccessMessage("Titlul a fost șters.");
      await loadData();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Nu am putut șterge titlul.");
    }
  }

  const columns = [
    {
      key: "title",
      header: "Title",
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
      header: "Type",
      render: (item: ContentRow) => (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          {item.type === "movie" ? <FilmIcon className="h-4 w-4" /> : <TvIcon className="h-4 w-4" />}
          <span className="capitalize">{item.type}</span>
        </div>
      ),
    },
    {
      key: "flags",
      header: "Flags",
      render: (item: ContentRow) => (
        <div className="flex flex-wrap gap-2">
          {item.is_featured ? <Badge variant="featured">Featured</Badge> : null}
          {item.is_trending ? <Badge variant="ready">Trending</Badge> : null}
          {item.is_free ? <Badge variant="free">Free</Badge> : <Badge variant="paid">{formatMoney(item)}</Badge>}
          {item.offers_count > 0 ? <Badge variant="draft">{item.offers_count} offers</Badge> : null}
        </div>
      ),
    },
    {
      key: "release_year",
      header: "Year",
      render: (item: ContentRow) => item.release_year ?? "N/A",
    },
    {
      key: "genres",
      header: "Genres",
      render: (item: ContentRow) => (
        <span className="text-sm text-muted-foreground">
          {item.genres.map((genre) => genre.localized_name).join(", ") || "No genres"}
        </span>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: (item: ContentRow) => <Badge variant={statusVariant(item.status)}>{item.status}</Badge>,
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
              navigate("editor", String(item.id), ["Catalog", item.localized_title]);
            }}
            title="Edit content"
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
            title="Preview content"
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
              title="Delete content"
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
          <h1 className="page-title">Content Catalog</h1>
          <p className="page-description">
            Titluri reale din backend, cu localizări, bannere desktop/mobile și taxonomii pregătite pentru storefront.
          </p>
        </div>

        {can("content.create") ? (
          <Button onClick={() => navigate("editor", "new", ["Catalog", "New content"])}>
            <PlusIcon className="h-4 w-4" />
            Add content
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
              { id: "all", label: "All content", count: items.length },
              { id: "movie", label: "Movies", count: items.filter((item) => item.type === "movie").length },
              { id: "series", label: "Series", count: items.filter((item) => item.type === "series").length },
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
              <option value="all">All statuses</option>
              <option value="draft">Draft</option>
              <option value="ready">Ready</option>
              <option value="published">Published</option>
              <option value="archived">Archived</option>
            </select>

            <select
              value={countryFilter}
              onChange={(event) => setCountryFilter(event.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="all">All countries</option>
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
              <option value="all">All genres</option>
              {genreOptions.map((genre) => (
                <option key={genre} value={genre}>
                  {genre}
                </option>
              ))}
            </select>
          </div>

          {isLoading ? (
            <div className="py-16 text-center text-sm text-muted-foreground">Se încarcă titlurile...</div>
          ) : (
            <DataTable
              data={tableRows}
              columns={columns}
              keyExtractor={(item) => String(item.id)}
              searchPlaceholder="Search by title, slug, genre or country..."
              onRowClick={(item) => navigate("editor", String(item.id), ["Catalog", item.localized_title])}
            />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
