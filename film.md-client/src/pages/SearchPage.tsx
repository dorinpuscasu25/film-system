import React, { useDeferredValue, useEffect, useState } from "react";
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
  const { currentLanguage } = useLanguage();
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
              placeholder="Search movies, series, actors..."
              className="w-full rounded-2xl border border-white/10 bg-surface py-4 pl-14 pr-6 text-xl text-white shadow-lg transition-colors placeholder:text-gray-500 focus:border-accent focus:outline-none"
              autoFocus
            />
          </div>
        </div>

        <div className="flex flex-col gap-8 md:flex-row">
          <div className="w-full flex-shrink-0 md:w-64">
            <div className="glass-panel sticky top-24 max-h-[80vh] space-y-8 overflow-y-auto rounded-xl p-6 custom-scrollbar">
              <div className="flex items-center space-x-2 border-b border-white/10 pb-4 font-bold text-white">
                <FilterIcon className="h-5 w-5" />
                <span>Advanced Filters</span>
              </div>

              <div>
                <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-400">Type</h3>
                <div className="flex rounded-lg bg-surfaceHover p-1">
                  {[
                    { value: null, label: "All" },
                    { value: "movie", label: "Movies" },
                    { value: "series", label: "Series" },
                  ].map((option) => (
                    <button
                      key={option.label}
                      onClick={() => setSelectedType(option.value)}
                      className={`flex-1 rounded-md py-1.5 text-sm transition-colors ${selectedType === option.value ? "bg-white/20 text-white shadow" : "text-gray-400 hover:text-white"}`}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-400">Access</h3>
                <div className="flex rounded-lg bg-surfaceHover p-1">
                  {[
                    { value: null, label: "All" },
                    { value: "free", label: "Free" },
                    { value: "paid", label: "Paid" },
                  ].map((option) => (
                    <button
                      key={option.label}
                      onClick={() => setSelectedPrice(option.value)}
                      className={`flex-1 rounded-md py-1.5 text-sm transition-colors ${selectedPrice === option.value ? "bg-white/20 text-white shadow" : "text-gray-400 hover:text-white"}`}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-400">Genres</h3>
                <div className="max-h-48 space-y-1 overflow-y-auto pr-2 custom-scrollbar">
                  <button
                    onClick={() => setSelectedGenre(null)}
                    className={`block w-full rounded px-3 py-1.5 text-left text-sm transition-colors ${!selectedGenre ? "bg-accent/20 text-accent" : "text-gray-300 hover:bg-white/5"}`}
                  >
                    All Genres
                  </button>
                  {filters.genres.map((genre) => (
                    <button
                      key={genre.value}
                      onClick={() => setSelectedGenre(genre.value)}
                      className={`block w-full rounded px-3 py-1.5 text-left text-sm transition-colors ${selectedGenre === genre.value ? "bg-accent/20 text-accent" : "text-gray-300 hover:bg-white/5"}`}
                    >
                      {genre.label} ({genre.count})
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-400">Release Year</h3>
                <div className="relative">
                  <select
                    value={selectedYear || ""}
                    onChange={(e) => setSelectedYear(e.target.value || null)}
                    className="w-full appearance-none rounded-lg border border-white/10 bg-surfaceHover px-3 py-2 text-sm text-white focus:border-accent focus:outline-none"
                  >
                    <option value="">All Years</option>
                    {filters.years.map((year) => (
                      <option key={year.value} value={year.value}>
                        {year.label}
                      </option>
                    ))}
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                </div>
              </div>

              <div>
                <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-400">Country</h3>
                <div className="relative">
                  <select
                    value={selectedCountry || ""}
                    onChange={(e) => setSelectedCountry(e.target.value || null)}
                    className="w-full appearance-none rounded-lg border border-white/10 bg-surfaceHover px-3 py-2 text-sm text-white focus:border-accent focus:outline-none"
                  >
                    <option value="">All Countries</option>
                    {filters.countries.map((country) => (
                      <option key={country.value} value={country.value}>
                        {country.label}
                      </option>
                    ))}
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                </div>
              </div>

              <div>
                <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-gray-400">Min Rating (IMDb)</h3>
                <div className="flex items-center space-x-4">
                  <input
                    type="range"
                    min="0"
                    max="10"
                    step="0.5"
                    value={minRating}
                    onChange={(e) => setMinRating(Number(e.target.value))}
                    className="h-1 w-full cursor-pointer appearance-none rounded-lg bg-white/20 accent-accentGold"
                  />
                  <span className="min-w-[3ch] font-bold text-accentGold">{minRating}+</span>
                </div>
              </div>
            </div>
          </div>

          <div className="flex-1">
            <div className="mb-6 flex items-center justify-between text-gray-400">
              <span>{isLoading ? "Loading..." : `Found ${total} results`}</span>
              {!isLoading ? (
                <button onClick={clearFilters} className="text-sm text-accent transition-colors hover:text-white">
                  Clear filters
                </button>
              ) : null}
            </div>

            {error ? (
              <div className="glass-panel rounded-2xl py-20 text-center">
                <h2 className="mb-2 text-2xl font-bold text-white">Could not load catalog</h2>
                <p className="text-gray-400">{error}</p>
              </div>
            ) : null}

            {isLoading ? (
              <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 md:gap-6">
                {Array.from({ length: 8 }).map((_, index) => (
                  <div key={index} className="aspect-[2/3] animate-pulse rounded-lg bg-surface" />
                ))}
              </div>
            ) : null}

            {!isLoading && !error && results.length > 0 ? (
              <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 md:gap-6">
                {results.map((movie) => (
                  <MovieCard key={movie.id} movie={movie} />
                ))}
              </div>
            ) : null}

            {!isLoading && !error && results.length === 0 ? (
              <div className="glass-panel rounded-2xl py-20 text-center">
                <h2 className="mb-2 text-2xl font-bold text-white">No results found</h2>
                <p className="text-gray-400">Try adjusting your search or filters.</p>
                <button onClick={clearFilters} className="mt-6 text-accent transition-colors hover:text-white">
                  Clear all filters
                </button>
              </div>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  );
}
