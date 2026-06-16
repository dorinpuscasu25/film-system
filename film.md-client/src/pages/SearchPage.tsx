import React, { useDeferredValue, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { ChevronDownIcon, FilterIcon, SearchIcon } from "lucide-react";
import { MovieCard } from "../components/MovieCard";
import { useLanguage } from "../contexts/LanguageContext";
import { getCatalogPage } from "../lib/storefront";
import type { CatalogFilterOption, CatalogFilters, CatalogQuery } from "../lib/storefront";
import type { Movie } from "../types";

type ContentType = NonNullable<CatalogQuery["type"]>;

const CONTENT_TYPE_VALUES: ContentType[] = ["movie", "documentary", "short", "animation", "series"];

const EMPTY_FILTERS: CatalogFilters = {
  genres: [],
  years: [],
  countries: [],
  types: [],
  access: [],
};

interface FilterOptionState {
  filters: CatalogFilters;
  genresScope: string;
  yearsScope: string;
  countriesScope: string;
}

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
  const [filterOptions, setFilterOptions] = useState<FilterOptionState>({
    filters: EMPTY_FILTERS,
    genresScope: "",
    yearsScope: "",
    countriesScope: "",
  });
  const [isFiltersOpen, setIsFiltersOpen] = useState(false);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const deferredQuery = useDeferredValue(query);
  const normalizedQuery = deferredQuery.trim();
  const normalizedType = isContentType(selectedType) ? selectedType : null;
  const normalizedAccess = selectedPrice === "free" || selectedPrice === "paid" ? selectedPrice : null;
  const normalizedMinRating = minRating > 0 ? minRating : 0;
  const genresScope = useMemo(
    () =>
      JSON.stringify({
        locale: currentLanguage.code,
        query: normalizedQuery,
        type: normalizedType,
        country: selectedCountry,
        access: normalizedAccess,
        year: selectedYear,
        minRating: normalizedMinRating,
      }),
    [currentLanguage.code, normalizedAccess, normalizedMinRating, normalizedQuery, normalizedType, selectedCountry, selectedYear],
  );
  const yearsScope = useMemo(
    () =>
      JSON.stringify({
        locale: currentLanguage.code,
        query: normalizedQuery,
        type: normalizedType,
        genre: selectedGenre,
        country: selectedCountry,
        access: normalizedAccess,
        minRating: normalizedMinRating,
      }),
    [currentLanguage.code, normalizedAccess, normalizedMinRating, normalizedQuery, normalizedType, selectedCountry, selectedGenre],
  );
  const countriesScope = useMemo(
    () =>
      JSON.stringify({
        locale: currentLanguage.code,
        query: normalizedQuery,
        type: normalizedType,
        genre: selectedGenre,
        access: normalizedAccess,
        year: selectedYear,
        minRating: normalizedMinRating,
      }),
    [currentLanguage.code, normalizedAccess, normalizedMinRating, normalizedQuery, normalizedType, selectedGenre, selectedYear],
  );

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
          query: normalizedQuery || undefined,
          type: normalizedType ?? undefined,
          genre: selectedGenre ?? undefined,
          access: normalizedAccess ?? undefined,
          year: selectedYear ?? undefined,
          country: selectedCountry ?? undefined,
          minRating: normalizedMinRating > 0 ? normalizedMinRating : undefined,
          page: 1,
          pageSize: 100,
        });

        if (!active) {
          return;
        }

        setResults(response.items);
        setFilterOptions((previous) => ({
          filters: {
            genres:
              previous.genresScope === genresScope
                ? mergeFilterOptions(previous.filters.genres, response.filters.genres)
                : response.filters.genres,
            years:
              previous.yearsScope === yearsScope
                ? mergeFilterOptions(previous.filters.years, response.filters.years)
                : response.filters.years,
            countries:
              previous.countriesScope === countriesScope
                ? mergeFilterOptions(previous.filters.countries, response.filters.countries)
                : response.filters.countries,
            types: response.filters.types,
            access: response.filters.access,
          },
          genresScope,
          yearsScope,
          countriesScope,
        }));
        setTotal(response.total);
      } catch (loadError) {
        if (!active) {
          return;
        }

        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca catalogul.");
        setResults([]);
        setFilterOptions({
          filters: EMPTY_FILTERS,
          genresScope: "",
          yearsScope: "",
          countriesScope: "",
        });
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
    countriesScope,
    genresScope,
    minRating,
    normalizedAccess,
    normalizedMinRating,
    normalizedQuery,
    normalizedType,
    selectedCountry,
    selectedGenre,
    selectedYear,
    yearsScope,
  ]);

  function clearFilters() {
    setQuery("");
    setSelectedGenre(null);
    const routeType = searchParams.get("type");
    setSelectedType(isContentType(routeType) ? routeType : null);
    setSelectedYear(null);
    setSelectedCountry(null);
    setSelectedPrice(null);
    setMinRating(0);
  }

  const activeFilterCount = useMemo(
    () =>
      [
        selectedGenre,
        normalizedType,
        selectedYear,
        selectedCountry,
        selectedPrice,
        minRating > 0 ? String(minRating) : null,
      ].filter(Boolean).length,
    [minRating, normalizedType, selectedCountry, selectedGenre, selectedPrice, selectedYear],
  );

  const typeOptions = useMemo(() => {
    const optionsByValue = new Map(filterOptions.filters.types.map((option) => [option.value, option]));

    return [
      { value: null, label: t("common.all") },
      ...CONTENT_TYPE_VALUES.map((value) => {
        const apiOption = optionsByValue.get(value);

        return {
          value,
          label: apiOption?.label || t(`content_types.${value}`),
        };
      }),
    ];
  }, [filterOptions.filters.types, t]);
  const selectedTypeLabel = typeOptions.find((option) => option.value === normalizedType)?.label;
  const selectedGenreLabel = filterOptions.filters.genres.find((genre) => genre.value === selectedGenre)?.label;
  const selectedYearLabel = filterOptions.filters.years.find((year) => year.value === selectedYear)?.label;
  const countryDisplayNames = useMemo(() => {
    if (typeof Intl === "undefined" || typeof Intl.DisplayNames !== "function") {
      return null;
    }

    return new Intl.DisplayNames([currentLanguage.code], { type: "region" });
  }, [currentLanguage.code]);
  const countryOptions = useMemo(
    () =>
      filterOptions.filters.countries.map((country) => ({
        ...country,
        label: getCountryLabel(country.value, country.label, countryDisplayNames),
      })),
    [countryDisplayNames, filterOptions.filters.countries],
  );
  const selectedCountryLabel = countryOptions.find((country) => country.value === selectedCountry)?.label;

  const selectClassName =
    "h-8 min-w-[126px] appearance-none rounded-md border border-white/10 bg-white/[0.03] px-2.5 pr-7 text-[11px] font-semibold text-white outline-none transition hover:border-white/30 hover:bg-white/[0.06] focus:border-white/50";

  return (
    <div className="min-h-screen bg-background pt-24 pb-20">
      <div className="container mx-auto px-4 md:px-8">
        <div className="mx-auto mb-7 max-w-2xl">
          <div className="relative">
            <SearchIcon className="absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t("search.placeholder")}
              className="h-12 w-full rounded-xl border border-white/10 bg-surface pl-11 pr-4 text-base text-white shadow-lg shadow-black/20 transition-colors placeholder:text-gray-500 focus:border-accent focus:outline-none"
              autoFocus
            />
          </div>
        </div>

        <div className="mb-5 flex flex-col gap-3 border-b border-white/10 pb-4 md:flex-row md:items-center md:justify-between">
          <p className="text-sm text-gray-400">
            {isLoading ? t("search.loading") : t("search.found_results", { count: total })}
          </p>

          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setIsFiltersOpen((value) => !value)}
              className={`inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[11px] font-semibold transition ${
                isFiltersOpen
                  ? "border-white/70 bg-white text-background"
                  : "border-white/20 bg-white/5 text-white hover:border-white/50 hover:bg-white/10"
              }`}
            >
              <FilterIcon className="h-3.5 w-3.5" />
              {isFiltersOpen ? t("search.hide_filters") : t("search.filter_button")}
              {activeFilterCount > 0 ? (
                <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${isFiltersOpen ? "bg-background text-white" : "bg-accent text-white"}`}>
                  {activeFilterCount}
                </span>
              ) : null}
            </button>

          </div>
        </div>

        {isFiltersOpen ? (
          <div className="mb-7 rounded-lg border border-white/10 bg-white/[0.02] px-3 py-3 shadow-lg shadow-black/10">
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex h-8 max-w-full overflow-x-auto rounded-md border border-white/10 bg-white/[0.03] p-0.5">
                {typeOptions.map((option) => (
                  <button
                    key={option.label}
                    type="button"
                    onClick={() => setSelectedType(option.value)}
                    className={`shrink-0 rounded px-2.5 text-[11px] font-semibold transition ${
                      normalizedType === option.value
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
                  {filterOptions.filters.genres.map((genre) => (
                    <option key={genre.value} value={genre.value}>
                      {genre.label} ({genre.count})
                    </option>
                  ))}
                </select>
                <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
              </div>

              <div className="relative">
                <select
                  value={selectedCountry || ""}
                  onChange={(e) => setSelectedCountry(e.target.value || null)}
                  className={selectClassName}
                  aria-label={t("search.country")}
                >
                  <option value="">{t("search.all_countries")}</option>
                  {countryOptions.map((country) => (
                    <option key={country.value} value={country.value}>
                      {country.label} ({country.count})
                    </option>
                  ))}
                </select>
                <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
              </div>

              <div className="relative">
                <select
                  value={selectedYear || ""}
                  onChange={(e) => setSelectedYear(e.target.value || null)}
                  className="h-8 min-w-[98px] appearance-none rounded-md border border-white/10 bg-white/[0.03] px-2.5 pr-7 text-[11px] font-semibold text-white outline-none transition hover:border-white/30 hover:bg-white/[0.06] focus:border-white/50"
                  aria-label={t("search.release_year")}
                >
                  <option value="">{t("search.all_years")}</option>
                  {filterOptions.filters.years.map((year) => (
                    <option key={year.value} value={year.value}>
                      {year.label}
                    </option>
                  ))}
                </select>
                <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
              </div>

              <div className="flex h-8 rounded-md border border-white/10 bg-white/[0.03] p-0.5">
                {[
                  { value: null, label: t("common.all") },
                  { value: "free", label: t("common.free") },
                  { value: "paid", label: t("common.paid") },
                ].map((option) => (
                  <button
                    key={option.label}
                    type="button"
                    onClick={() => setSelectedPrice(option.value)}
                    className={`rounded px-2.5 text-[11px] font-semibold transition ${
                      selectedPrice === option.value
                        ? "bg-white text-background"
                        : "text-gray-400 hover:text-white"
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>

              <label className="flex h-8 min-w-[190px] items-center gap-2 rounded-md border border-white/10 bg-white/[0.03] px-2.5 text-[11px] font-semibold text-white">
                <span className="whitespace-nowrap">{t("search.min_rating")}</span>
                <input
                  type="range"
                  min="0"
                  max="10"
                  step="0.5"
                  value={minRating}
                  onChange={(e) => setMinRating(Number(e.target.value))}
                  className="h-1 min-w-16 cursor-pointer appearance-none rounded-lg bg-white/20 accent-accentGold"
                />
                <span className="min-w-[3ch] text-accentGold">{minRating}+</span>
              </label>

              <button
                type="button"
                onClick={clearFilters}
                className="ml-auto h-8 rounded-md bg-white px-3 text-[11px] font-bold text-background transition hover:bg-gray-200"
              >
                {t("common.clear_filters")}
              </button>
            </div>

            {activeFilterCount > 0 ? (
              <div className="mt-2.5 flex flex-wrap gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-white/55">
                {normalizedType ? <span className="rounded-full bg-white/10 px-2 py-0.5">{selectedTypeLabel ?? normalizedType}</span> : null}
                {selectedGenre ? <span className="rounded-full bg-white/10 px-2 py-0.5">{selectedGenreLabel ?? selectedGenre}</span> : null}
                {selectedCountry ? <span className="rounded-full bg-white/10 px-2 py-0.5">{selectedCountryLabel ?? selectedCountry}</span> : null}
                {selectedYear ? <span className="rounded-full bg-white/10 px-2 py-0.5">{selectedYearLabel ?? selectedYear}</span> : null}
                {selectedPrice ? <span className="rounded-full bg-white/10 px-2 py-0.5">{selectedPrice === "free" ? t("common.free") : t("common.paid")}</span> : null}
                {minRating > 0 ? <span className="rounded-full bg-white/10 px-2 py-0.5">IMDb {minRating}+</span> : null}
              </div>
            ) : null}
          </div>
        ) : null}

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

function getCountryLabel(
  value: string,
  fallbackLabel: string,
  displayNames: Intl.DisplayNames | null,
): string {
  const countryCode = value.trim().toUpperCase();
  const localizedLabel = /^[A-Z]{2}$/.test(countryCode) ? displayNames?.of(countryCode) : null;

  return localizedLabel && localizedLabel !== countryCode ? localizedLabel : fallbackLabel;
}

function isContentType(value: string | null): value is ContentType {
  return value !== null && CONTENT_TYPE_VALUES.includes(value as ContentType);
}

function mergeFilterOptions(
  previousOptions: CatalogFilterOption[],
  incomingOptions: CatalogFilterOption[],
): CatalogFilterOption[] {
  const incomingByValue = new Map(incomingOptions.map((option) => [option.value, option]));
  const merged = previousOptions.map((option) => incomingByValue.get(option.value) ?? option);
  const previousValues = new Set(previousOptions.map((option) => option.value));
  const appendedOptions = incomingOptions.filter((option) => !previousValues.has(option.value));

  return [...merged, ...appendedOptions];
}
