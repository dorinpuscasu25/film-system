import React, { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { AnimatePresence, motion } from "framer-motion";
import { InfoIcon, PlayIcon } from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";
import { Badge } from "../components/Badge";
import { Carousel } from "../components/Carousel";
import { HomeHeroSlide, HomeSections, getHomeSections } from "../lib/storefront";
import { fetchContinueWatching } from "../lib/session";

const EMPTY_HOME: HomeSections = {
  hero: null,
  heroSlides: [],
  sections: [],
  featured: [],
  freeToWatch: [],
  latest: [],
  movies: [],
  series: [],
};

export function HomePage() {
  const { isAuthenticated, openAuthModal } = useAuth();
  const { t, currentLanguage } = useLanguage();
  const navigate = useNavigate();
  const [currentHeroIndex, setCurrentHeroIndex] = useState(0);
  const [homeSections, setHomeSections] = useState<HomeSections>(EMPTY_HOME);
  const [continueWatching, setContinueWatching] = useState<Array<{
    contentSlug: string;
    title: string;
    posterUrl: string;
    progressPercent: number;
    positionSeconds: number;
    durationSeconds: number;
  }>>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    async function loadHome() {
      setIsLoading(true);
      setError(null);

      try {
        const response = await getHomeSections(currentLanguage.code);
        if (!active) {
          return;
        }

        setHomeSections(response);
        setCurrentHeroIndex(0);
      } catch (loadError) {
        if (!active) {
          return;
        }

        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca homepage-ul.");
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void loadHome();

    return () => {
      active = false;
    };
  }, [currentLanguage.code]);

  useEffect(() => {
    if (!isAuthenticated) {
      setContinueWatching([]);
      return;
    }

    let active = true;

    async function loadContinueWatching() {
      try {
        const response = await fetchContinueWatching(currentLanguage.code);
        if (!active) {
          return;
        }

        setContinueWatching(
          (response.items ?? []).map((item) => ({
            contentSlug: item.content_slug,
            title: item.title ?? item.content_slug,
            posterUrl: item.poster_url ?? "",
            progressPercent: Number(item.progress_percent ?? 0),
            positionSeconds: Number(item.position_seconds ?? 0),
            durationSeconds: Number(item.duration_seconds ?? 0),
          })),
        );
      } catch {
        if (active) {
          setContinueWatching([]);
        }
      }
    }

    void loadContinueWatching();

    return () => {
      active = false;
    };
  }, [currentLanguage.code, isAuthenticated]);

  const heroSlides = useMemo<HomeHeroSlide[]>(() => {
    if (homeSections.heroSlides.length > 0) {
      return homeSections.heroSlides;
    }

    const base = homeSections.featured.length > 0
      ? homeSections.featured
      : [
          ...homeSections.latest,
          ...homeSections.movies,
          ...homeSections.series,
        ];

    return base.slice(0, 5).map((movie) => ({
      id: `fallback-${movie.id}`,
      desktopImageUrl: movie.heroDesktopUrl || movie.backdropUrl,
      mobileImageUrl: movie.heroMobileUrl || movie.posterUrl,
      eyebrow: movie.isTrending ? "Trending now" : undefined,
      title: movie.title,
      description: movie.shortDescription || movie.tagline || movie.description,
      primaryCtaLabel: movie.price === 0 ? "Watch Free" : undefined,
      secondaryCtaLabel: "More Info",
      content: movie,
    }));
  }, [homeSections.featured, homeSections.heroSlides, homeSections.latest, homeSections.movies, homeSections.series]);

  useEffect(() => {
    if (heroSlides.length <= 1) {
      return;
    }

    const timer = setInterval(() => {
      setCurrentHeroIndex((prev) => (prev + 1) % heroSlides.length);
    }, 8000);

    return () => clearInterval(timer);
  }, [heroSlides.length]);

  if (isLoading) {
    return (
      <div className="flex min-h-[70vh] items-center justify-center bg-background text-gray-400">
        Loading storefront...
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-background px-4 pb-20 pt-32">
        <div className="mx-auto max-w-3xl rounded-2xl border border-white/10 bg-surface p-8 text-center">
          <h1 className="mb-3 text-2xl font-bold text-white">Storefront unavailable</h1>
          <p className="text-gray-400">{error}</p>
        </div>
      </div>
    );
  }

  const activeHeroSlide = heroSlides[currentHeroIndex];
  const featuredMovie = activeHeroSlide?.content ?? homeSections.hero;

  if (!featuredMovie) {
    return (
      <div className="flex min-h-[70vh] items-center justify-center bg-background text-gray-400">
        No published content yet.
      </div>
    );
  }

  const featuredPrice = featuredMovie.offers && featuredMovie.offers.length > 0
    ? Math.min(...featuredMovie.offers.map((offer) => offer.price))
    : featuredMovie.price;

  const handlePrimaryAction = () => {
    if (!isAuthenticated && featuredPrice > 0) {
      openAuthModal();
      return;
    }

    navigate(`/movie/${featuredMovie.id}`);
  };

  return (
    <div className="min-h-screen bg-background pb-20">
      <div className="relative h-[85vh] w-full overflow-hidden">
        <AnimatePresence mode="wait">
          <motion.div
            key={activeHeroSlide?.id ?? currentHeroIndex}
            initial={{ opacity: 0, scale: 1.05 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 1 }}
            className="absolute inset-0"
          >
            <picture>
              <source
                media="(max-width: 767px)"
                srcSet={activeHeroSlide?.mobileImageUrl || featuredMovie.heroMobileUrl || featuredMovie.posterUrl}
              />
              <img
                src={activeHeroSlide?.desktopImageUrl || featuredMovie.heroDesktopUrl || featuredMovie.backdropUrl}
                alt={activeHeroSlide?.title || featuredMovie.title}
                className="h-full w-full object-cover"
              />
            </picture>

            <div className="absolute inset-0 bg-gradient-to-r from-background via-background/85 to-transparent" />
            <div className="absolute inset-0 bg-gradient-to-t from-background via-background/40 to-transparent" />
          </motion.div>
        </AnimatePresence>

        <div className="absolute bottom-0 left-0 z-10 w-full px-4 pb-32 pt-32 md:px-12">
          <motion.div
            key={`content-${activeHeroSlide?.id ?? currentHeroIndex}`}
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.2 }}
            className="max-w-2xl"
          >
            <div className="mb-4 flex flex-wrap items-center gap-3">
              {activeHeroSlide?.eyebrow ? <Badge variant="new" text={activeHeroSlide.eyebrow} /> : null}
              {featuredMovie.isNew ? <Badge variant="new" text="New Release" /> : null}
              {featuredMovie.isTrending ? <Badge variant="trending" text="Trending" /> : null}
              <Badge variant="price" text={featuredPrice === 0 ? "Free" : `$${featuredPrice}`} />
            </div>

            <h1 className="mb-4 text-5xl font-bold leading-tight text-white drop-shadow-lg md:text-7xl">
              {activeHeroSlide?.title || featuredMovie.title}
            </h1>

            <div className="mb-6 flex flex-wrap items-center gap-4 text-sm font-medium text-gray-300 md:text-base">
              <span className="font-bold text-accentGold drop-shadow">★ {featuredMovie.rating.toFixed(1)}</span>
              <span>{featuredMovie.year}</span>
              <span>{featuredMovie.genres.join(" • ")}</span>
              {featuredMovie.offers?.[0]?.quality ? (
                <span className="rounded border border-gray-500 bg-black/50 px-1.5 text-xs">
                  {featuredMovie.offers[0].quality}
                </span>
              ) : null}
            </div>

            <p className="mb-8 max-w-xl line-clamp-3 text-lg text-gray-300 drop-shadow">
              {activeHeroSlide?.description || featuredMovie.shortDescription || featuredMovie.description}
            </p>

            <div className="flex items-center space-x-4">
              <button
                onClick={handlePrimaryAction}
                className="flex items-center space-x-2 rounded-lg bg-accent px-8 py-3 font-bold text-white shadow-lg shadow-accent/20 transition-colors hover:bg-red-700"
              >
                <PlayIcon className="h-5 w-5 fill-current" />
                <span>
                  {activeHeroSlide?.primaryCtaLabel || (featuredPrice === 0 ? "Watch Free" : `${t("btn.buy")} - $${featuredPrice}`)}
                </span>
              </button>
              <button
                onClick={() => navigate(`/movie/${featuredMovie.id}`)}
                className="flex items-center space-x-2 rounded-lg bg-white/20 px-8 py-3 font-bold text-white backdrop-blur-md transition-colors hover:bg-white/30"
              >
                <InfoIcon className="h-5 w-5" />
                <span>{activeHeroSlide?.secondaryCtaLabel || "More Info"}</span>
              </button>
            </div>
          </motion.div>
        </div>

        <div className="absolute bottom-12 right-12 z-20 flex space-x-2">
          {heroSlides.map((slide, index) => (
            <button
              key={slide.id}
              onClick={() => setCurrentHeroIndex(index)}
              className={`h-1.5 rounded-full transition-all duration-300 ${index === currentHeroIndex ? "w-8 bg-accent" : "w-4 bg-white/30 hover:bg-white/50"}`}
            />
          ))}
        </div>
      </div>

      <div className="relative z-10 mt-12 space-y-10">
        {continueWatching.length > 0 ? (
          <section className="px-4 md:px-8">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold text-white">Continue Watching</h2>
                <p className="text-sm text-gray-400">Reia rapid exact de unde ai rămas.</p>
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {continueWatching.map((item) => (
                <button
                  key={item.contentSlug}
                  onClick={() => navigate(`/watch/${item.contentSlug}`)}
                  className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 text-left transition hover:border-white/20 hover:bg-white/10"
                >
                  <div className="flex gap-4 p-4">
                    <div className="h-24 w-16 shrink-0 overflow-hidden rounded-lg bg-surface">
                      {item.posterUrl ? (
                        <img src={item.posterUrl} alt={item.title} className="h-full w-full object-cover" />
                      ) : null}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="line-clamp-2 font-semibold text-white">{item.title}</p>
                      <p className="mt-2 text-xs text-gray-400">
                        {Math.floor(item.positionSeconds / 60)}m din {Math.max(1, Math.floor(item.durationSeconds / 60))}m
                      </p>
                      <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-white/10">
                        <div
                          className="h-full rounded-full bg-accent"
                          style={{ width: `${Math.min(100, Math.max(0, item.progressPercent))}%` }}
                        />
                      </div>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          </section>
        ) : null}

        {homeSections.sections.length > 0 ? (
          homeSections.sections.map((section) => (
            <Carousel
              key={section.id}
              title={section.title}
              subtitle={section.subtitle}
              movies={section.items}
            />
          ))
        ) : (
          <>
            <Carousel title={t("home.trending")} movies={homeSections.featured.length > 0 ? homeSections.featured : homeSections.movies} />
            <Carousel title={t("home.new")} movies={homeSections.latest} />
            <Carousel title="Free to Watch" movies={homeSections.freeToWatch} />
            <Carousel title={t("nav.movies")} movies={homeSections.movies} onSeeAll={() => navigate("/search?type=movie")} />
            <Carousel title={t("nav.series")} movies={homeSections.series} onSeeAll={() => navigate("/search?type=series")} />
          </>
        )}
      </div>
    </div>
  );
}
