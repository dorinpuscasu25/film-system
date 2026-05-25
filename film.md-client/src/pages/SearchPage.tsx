import React, { useDeferredValue, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { ChevronDownIcon, FilterIcon, SearchIcon } from "lucide-react";
import { MovieCard } from "../components/MovieCard";
import { useLanguage } from "../contexts/LanguageContext";
import { CatalogFilters, getCatalogPage } from "../lib/storefront";
import { Movie } from "../types";

const EMPTY_FILTERS: CatalogFilters = {
  genres: [],
  years: [],
  countries: [],
  types: [],
  access: [],
};

export function SearchPage() {
  const { currentLanguage, t } = useLanguage();
  const [searchParams] = useSearchParams();
  const [query, setQuery] = useState(searchParams.get("q") ?? "");
  const [selectedGenre, setSelectedGenre] = useState<string | null>(null);
  const [selectedType, setSelectedType] = useState<string | null>(searchParams.get("type"));
  const [selectedYear, setSelectedYear] = useState<string | null>(null);
  const [selectedCountry, setSelectedCountry] = useState<string | null>(null);
  const [selectedPrice, setSelectedPrice] = useState<string | null>(null);
  const [minRating, setMinRating] = useState<number>(0);
  const [results, setResults] = useState<Movie[]>([]);
  const [filters, setFilters] = useState<CatalogFilters>(EMPTY_FILTERS);
  const [isFiltersOpen, setIsFiltersOpen] = useState(false);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const deferredQuery = useDeferredValue(query);

  useEffect(() => {
    setSelectedType(searchParams.get("type"));
  }, [searchParams]);

  useEffect(() => {
    let active = true;

    async function loadCatalog() {
      setIsLoading(true);
      setError(null);

      try {
        const response = await getCatalogPage(currentLanguage.code, {
          query: deferredQuery.trim() || undefined,
          type: selectedType === "movie" || selectedType === "series" ? selectedType : undefined,
          genre: selectedGenre ?? undefined,
          access: selectedPrice === "free" || selectedPrice === "paid" ? selectedPrice : undefined,
          year: selectedYear ?? undefined,
          country: selectedCountry ?? undefined,
          minRating: minRating > 0 ? minRating : undefined,
          page: 1,
          pageSize: 100,
        });

        if (!active) {
          return;
        }

        setResults(response.items);
        setFilters(response.filters);
        setTotal(response.total);
      } catch (loadError) {
        if (!active) {
          return;
        }

        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca catalogul.");
        setResults([]);
        setFilters(EMPTY_FILTERS);
        setTotal(0);
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void loadCatalog();

    return () => {
      active = false;
    };
  }, [
    currentLanguage.code,
    deferredQuery,
    minRating,
    selectedCountry,
    selectedGenre,
    selectedPrice,
    selectedType,
    selectedYear,
  ]);

  function clearFilters() {
    setQuery("");
    setSelectedGenre(null);
    setSelectedType(searchParams.get("type"));
    setSelectedYear(null);
    setSelectedCountry(null);
    setSelectedPrice(null);
    setMinRating(0);
  }

  const activeFilterCount = useMemo(
    () =>
      [
        selectedGenre,
        selectedType,
        selectedYear,
        selectedCountry,
        selectedPrice,
        minRating > 0 ? String(minRating) : null,
      ].filter(Boolean).length,
    [minRating, selectedCountry, selectedGenre, selectedPrice, selectedType, selectedYear],
  );

  const selectedGenreLabel = filters.genres.find((genre) => genre.value === selectedGenre)?.label;
  const selectedYearLabel = filters.years.find((year) => year.value === selectedYear)?.label;
  const selectedCountryLabel = filters.countries.find((country) => country.value === selectedCountry)?.label;

  const selectClassName =
    "h-9 min-w-[138px] appearance-none rounded-md border border-white/10 bg-white/[0.03] px-3 pr-8 text-xs font-semibold text-white outline-none transition hover:border-white/30 hover:bg-white/[0.06] focus:border-white/50";

  return (
    <div className="min-h-screen bg-background pt-24 pb-20">
      <div className="container mx-auto px-4 md:px-8">
        <div className="mx-auto mb-12 max-w-3xl">
          <div className="relative">
            <SearchIcon className="absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t("search.placeholder")}
              className="w-full rounded-2xl border border-white/10 bg-surface py-4 pl-14 pr-6 text-xl text-white shadow-lg transition-colors placeholder:text-gray-500 focus:border-accent focus:outline-none"
              autoFocus
            />
          </div>
        </div>

        <div className="mb-8 flex flex-col gap-4 border-b border-white/10 pb-5 md:flex-row md:items-center md:justify-between">
          <div>
            <div className="flex items-center gap-4 text-3xl font-bold uppercase tracking-wide md:text-4xl">
              <span className="text-white/35">{t("nav.search")}</span>
              <span className={isFiltersOpen ? "text-white" : "text-white/35"}>
                {t("search.advanced_filters")}
              </span>
            </div>
            <p className="mt-2 text-sm text-gray-400">
              {isLoading ? t("search.loading") : t("search.found_results", { count: total })}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={() => setIsFiltersOpen((value) => !value)}
              className={`inline-flex h-9 items-center gap-2 rounded-md border px-3 text-xs font-semibold transition ${
                isFiltersOpen
                  ? "border-white/70 bg-white text-background"
                  : "border-white/20 bg-white/5 text-white hover:border-white/50 hover:bg-white/10"
              }`}
            >
              <FilterIcon className="h-4 w-4" />
              {isFiltersOpen ? t("search.hide_filters") : t("search.filter_button")}
              {activeFilterCount > 0 ? (
                <span className={`rounded-full px-2 py-0.5 text-xs ${isFiltersOpen ? "bg-background text-white" : "bg-accent text-white"}`}>
                  {activeFilterCount}
                </span>
              ) : null}
            </button>

          </div>
        </div>

        {isFiltersOpen ? (
          <div className="mb-8 rounded-xl border border-white/10 bg-white/[0.025] px-3 py-3 md:px-4">
            <div className="flex flex-wrap items-center gap-2.5">
              <div className="flex h-9 rounded-md border border-white/10 bg-white/[0.03] p-0.5">
                {[
                  { value: null, label: t("common.all") },
                  { value: "movie", label: t("nav.movies") },
                  { value: "series", label: t("nav.series") },
                ].map((option) => (
                  <button
                    key={option.label}
                    type="button"
                    onClick={() => setSelectedType(option.value)}
                    className={`rounded px-3 text-xs font-semibold transition ${
                      selectedType === option.value
                        ? "bg-white text-background"
                        : "text-gray-400 hover:text-white"
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>

              <div className="relative">
                <select
                  value={selectedGenre || ""}
                  onChange={(e) => setSelectedGenre(e.target.value || null)}
                  className={selectClassName}
                  aria-label={t("search.genres")}
                >
                  <option value="">{t("search.all_genres")}</option>
                  {filters.genres.map((genre) => (
                    <option key={genre.value} value={genre.value}>
                      {genre.label} ({genre.count})
                    </option>
                  ))}
                </select>
                <ChevronDownIcon className="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
              </div>

              <div className="relative">
                <select
                  value={selectedCountry || ""}
                  onChange={(e) => setSelectedCountry(e.target.value || null)}
                  className={selectClassName}
                  aria-label={t("search.country")}
                >
                  <option value="">{t("search.all_countries")}</option>
                  {filters.countries.map((country) => (
                    <option key={country.value} value={country.value}>
                      {country.label} ({country.count})
                    </option>
                  ))}
                </select>
                <ChevronDownIcon className="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
              </div>

              <div className="relative">
                <select
                  value={selectedYear || ""}
                  onChange={(e) => setSelectedYear(e.target.value || null)}
                  className="h-9 min-w-[110px] appearance-none rounded-md border border-white/10 bg-white/[0.03] px-3 pr-8 text-xs font-semibold text-white outline-none transition hover:border-white/30 hover:bg-white/[0.06] focus:border-white/50"
                  aria-label={t("search.release_year")}
                >
                  <option value="">{t("search.all_years")}</option>
                  {filters.years.map((year) => (
                    <option key={year.value} value={year.value}>
                      {year.label}
                    </option>
                  ))}
                </select>
                <ChevronDownIcon className="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
              </div>

              <div className="flex h-9 rounded-md border border-white/10 bg-white/[0.03] p-0.5">
                {[
                  { value: null, label: t("common.all") },
                  { value: "free", label: t("common.free") },
                  { value: "paid", label: t("common.paid") },
                ].map((option) => (
                  <button
                    key={option.label}
                    type="button"
                    onClick={() => setSelectedPrice(option.value)}
                    className={`rounded px-3 text-xs font-semibold transition ${
                      selectedPrice === option.value
                        ? "bg-white text-background"
                        : "text-gray-400 hover:text-white"
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>

              <label className="flex h-9 min-w-[210px] items-center gap-2.5 rounded-md border border-white/10 bg-white/[0.03] px-3 text-xs font-semibold text-white">
                <span className="whitespace-nowrap">{t("search.min_rating")}</span>
                <input
                  type="range"
                  min="0"
                  max="10"
                  step="0.5"
                  value={minRating}
                  onChange={(e) => setMinRating(Number(e.target.value))}
                  className="h-1 min-w-20 cursor-pointer appearance-none rounded-lg bg-white/20 accent-accentGold"
                />
                <span className="min-w-[3ch] text-accentGold">{minRating}+</span>
              </label>

              <button
                type="button"
                onClick={clearFilters}
                className="ml-auto h-9 rounded-md bg-white px-4 text-xs font-bold text-background transition hover:bg-gray-200"
              >
                {t("common.clear_filters")}
              </button>
            </div>

            {activeFilterCount > 0 ? (
              <div className="mt-3 flex flex-wrap gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-white/60">
                {selectedType ? <span className="rounded-full bg-white/10 px-2.5 py-0.5">{selectedType === "movie" ? t("nav.movies") : t("nav.series")}</span> : null}
                {selectedGenre ? <span className="rounded-full bg-white/10 px-2.5 py-0.5">{selectedGenreLabel ?? selectedGenre}</span> : null}
                {selectedCountry ? <span className="rounded-full bg-white/10 px-2.5 py-0.5">{selectedCountryLabel ?? selectedCountry}</span> : null}
                {selectedYear ? <span className="rounded-full bg-white/10 px-2.5 py-0.5">{selectedYearLabel ?? selectedYear}</span> : null}
                {selectedPrice ? <span className="rounded-full bg-white/10 px-2.5 py-0.5">{selectedPrice === "free" ? t("common.free") : t("common.paid")}</span> : null}
                {minRating > 0 ? <span className="rounded-full bg-white/10 px-2.5 py-0.5">IMDb {minRating}+</span> : null}
              </div>
            ) : null}
          </div>
        ) : null}

        <div className="mb-6 flex items-center justify-between text-gray-400">
          <span>{isLoading ? t("search.loading") : t("search.found_results", { count: total })}</span>
        </div>

        {error ? (
          <div className="glass-panel rounded-2xl py-20 text-center">
            <h2 className="mb-2 text-2xl font-bold text-white">{t("search.could_not_load")}</h2>
            <p className="text-gray-400">{error}</p>
          </div>
        ) : null}

        {isLoading ? (
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 md:gap-6">
            {Array.from({ length: 10 }).map((_, index) => (
              <div key={index} className="aspect-[2/3] animate-pulse rounded-lg bg-surface" />
            ))}
          </div>
        ) : null}

        {!isLoading && !error && results.length > 0 ? (
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 md:gap-6">
            {results.map((movie) => (
              <MovieCard key={movie.id} movie={movie} />
            ))}
          </div>
        ) : null}

        {!isLoading && !error && results.length === 0 ? (
          <div className="glass-panel rounded-2xl py-20 text-center">
            <h2 className="mb-2 text-2xl font-bold text-white">{t("search.no_results")}</h2>
            <p className="text-gray-400">{t("search.try_adjusting")}</p>
            <button onClick={clearFilters} className="mt-6 text-accent transition-colors hover:text-white">
              {t("common.clear_all_filters")}
            </button>
          </div>
        ) : null}
      </div>
    </div>
  );
}
