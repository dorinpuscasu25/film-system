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
import { HomeSkeleton } from "../components/HomeSkeleton";
import { imageSrcSet, resizedImageUrl } from "../lib/images";

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

function labelKey(label?: string | null): string {
  return (label ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim()
    .toLowerCase();
}

function heroGenreLabels(movie: HomeHeroSlide["content"]): string[] {
  const typeLabel = labelKey(movie.typeLabel);
  const type = labelKey(movie.type);
  const seen = new Set<string>();

  return movie.genres.filter((genre) => {
    const key = labelKey(genre);
    if (!key || key === typeLabel || key === type || seen.has(key)) {
      return false;
    }

    seen.add(key);
    return true;
  });
}

export function HomePage() {
  const { isAuthenticated, activeProfile, openAuthModal } = useAuth();
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
    if (!isAuthenticated || !activeProfile) {
      setContinueWatching([]);
      return;
    }

    let active = true;

    async function loadContinueWatching() {
      try {
        const response = await fetchContinueWatching(currentLanguage.code, activeProfile.id);
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
  }, [activeProfile, currentLanguage.code, isAuthenticated]);

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
      eyebrow: movie.isTrending ? t("movie.trending") : undefined,
      title: movie.title,
      description: movie.shortDescription || movie.description,
      primaryCtaLabel: movie.price === 0 ? t("common.watch_free") : undefined,
      secondaryCtaLabel: t("common.more_info"),
      content: movie,
    }));
  }, [homeSections.featured, homeSections.heroSlides, homeSections.latest, homeSections.movies, homeSections.series, t]);

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
    return <HomeSkeleton />;
  }

  if (error) {
    return (
      <div className="min-h-screen bg-background px-4 pb-20 pt-32">
        <div className="mx-auto max-w-3xl rounded-2xl border border-white/10 bg-surface p-8 text-center">
          <h1 className="mb-3 text-2xl font-bold text-white">{t("movie.storefront_unavailable")}</h1>
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
        {t("movie.no_published")}
      </div>
    );
  }

  const featuredPrice = featuredMovie.offers && featuredMovie.offers.length > 0
    ? Math.min(...featuredMovie.offers.map((offer) => offer.price))
    : featuredMovie.price;
  const featuredGenres = heroGenreLabels(featuredMovie);

  const handlePrimaryAction = () => {
    if (!isAuthenticated && featuredPrice > 0) {
      openAuthModal();
      return;
    }

    navigate(`/movie/${featuredMovie.id}`);
  };

  return (
    <div className="min-h-screen bg-background pb-20">
      <div className="relative h-[82vh] min-h-[640px] w-full overflow-hidden min-[2200px]:min-h-[820px]">
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
                srcSet={imageSrcSet(activeHeroSlide?.mobileImageUrl || featuredMovie.heroMobileUrl || featuredMovie.posterUrl, [
                  { width: 480, height: 720, descriptor: "480w" },
                  { width: 720, height: 1080, descriptor: "720w" },
                ])}
                sizes="100vw"
              />
              <img
                src={resizedImageUrl(activeHeroSlide?.desktopImageUrl || featuredMovie.heroDesktopUrl || featuredMovie.backdropUrl, { width: 1440, height: 810 })}
                srcSet={imageSrcSet(activeHeroSlide?.desktopImageUrl || featuredMovie.heroDesktopUrl || featuredMovie.backdropUrl, [
                  { width: 960, height: 540, descriptor: "960w" },
                  { width: 1440, height: 810, descriptor: "1440w" },
                  { width: 1920, height: 1080, descriptor: "1920w" },
                ])}
                sizes="100vw"
                alt={activeHeroSlide?.title || featuredMovie.title}
                className="h-full w-full object-cover"
                decoding="async"
                fetchPriority="high"
              />
            </picture>

            <div className="absolute inset-0 bg-gradient-to-r from-background via-background/85 to-transparent" />
            <div className="absolute inset-0 bg-gradient-to-t from-background via-background/40 to-transparent" />
          </motion.div>
        </AnimatePresence>

        <div className="absolute inset-x-0 bottom-0 z-10 w-full">
          <div className="mx-auto max-w-[2200px] px-4 pb-28 pt-32 sm:px-6 md:px-10 md:pb-32 2xl:px-12 min-[2200px]:pb-40">
          <motion.div
            key={`content-${activeHeroSlide?.id ?? currentHeroIndex}`}
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay: 0.2 }}
            className="max-w-2xl 2xl:max-w-3xl min-[2200px]:max-w-4xl"
          >
            <div className="mb-4 flex flex-wrap items-center gap-3">
              {activeHeroSlide?.eyebrow ? <Badge variant="new" text={activeHeroSlide.eyebrow} /> : null}
              {featuredMovie.isNew ? <Badge variant="new" text={t("movie.new_release")} /> : null}
              {featuredMovie.isTrending ? <Badge variant="trending" text={t("movie.trending")} /> : null}
              <Badge variant="price" text={featuredPrice === 0 ? t("common.free") : `${featuredPrice} MDL`} />
            </div>

            <h1 className="mb-4 text-4xl font-bold leading-tight text-white drop-shadow-lg sm:text-5xl md:text-7xl 2xl:text-8xl min-[2200px]:text-9xl">
              {activeHeroSlide?.title || featuredMovie.title}
            </h1>

            {featuredMovie.tagline ? (
              <p className="mb-5 max-w-xl text-xl font-semibold leading-snug text-white drop-shadow md:text-2xl 2xl:max-w-2xl min-[2200px]:max-w-3xl min-[2200px]:text-3xl">
                {featuredMovie.tagline}
              </p>
            ) : null}

            <div className="mb-6 flex flex-wrap items-center gap-4 text-sm font-medium text-gray-300 md:text-base min-[2200px]:text-lg">
              <span className="font-bold text-accentGold drop-shadow">★ {featuredMovie.rating.toFixed(1)}</span>
              <span>{featuredMovie.year}</span>
              {featuredGenres.length > 0 ? <span>{featuredGenres.join(" • ")}</span> : null}
              {featuredMovie.offers?.[0]?.quality ? (
                <span className="rounded border border-gray-500 bg-black/50 px-1.5 text-xs">
                  {featuredMovie.offers[0].quality}
                </span>
              ) : null}
            </div>

            <p className="mb-8 max-w-xl line-clamp-3 text-lg text-gray-300 drop-shadow 2xl:max-w-2xl min-[2200px]:max-w-3xl min-[2200px]:text-2xl">
              {activeHeroSlide?.description || featuredMovie.shortDescription || featuredMovie.description}
            </p>

            <div className="flex flex-wrap items-center gap-3 sm:gap-4">
              <button
                onClick={handlePrimaryAction}
                className="flex items-center space-x-2 rounded-lg bg-accent px-6 py-3 font-bold text-white shadow-lg shadow-accent/20 transition-colors hover:bg-red-700 sm:px-8 min-[2200px]:px-10 min-[2200px]:py-4 min-[2200px]:text-lg"
              >
                <PlayIcon className="h-5 w-5 fill-current" />
                <span>
                  {activeHeroSlide?.primaryCtaLabel || (featuredPrice === 0 ? t("common.watch_free") : `${t("btn.buy")} - ${featuredPrice} MDL`)}
                </span>
              </button>
              <button
                onClick={() => navigate(`/movie/${featuredMovie.id}`)}
                className="flex items-center space-x-2 rounded-lg bg-white/20 px-6 py-3 font-bold text-white backdrop-blur-md transition-colors hover:bg-white/30 sm:px-8 min-[2200px]:px-10 min-[2200px]:py-4 min-[2200px]:text-lg"
              >
                <InfoIcon className="h-5 w-5" />
                <span>{activeHeroSlide?.secondaryCtaLabel || t("common.more_info")}</span>
              </button>
            </div>
          </motion.div>
          </div>
        </div>

        <div className="absolute inset-x-0 bottom-12 z-20 hidden px-4 sm:block sm:px-6 md:px-10 2xl:px-12">
          <div className="mx-auto flex max-w-[2200px] justify-end space-x-2">
          {heroSlides.map((slide, index) => (
            <button
              key={slide.id}
              onClick={() => setCurrentHeroIndex(index)}
              className={`h-1.5 rounded-full transition-all duration-300 ${index === currentHeroIndex ? "w-8 bg-accent" : "w-4 bg-white/30 hover:bg-white/50"}`}
            />
          ))}
          </div>
        </div>
      </div>

      <div className="relative z-10 mt-12 space-y-10">
        {continueWatching.length > 0 ? (
          <section className="mx-auto max-w-[2200px] px-4 sm:px-6 md:px-10 2xl:px-12">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold text-white 2xl:text-3xl">{t("movie.continue_watching")}</h2>
                <p className="text-sm text-gray-400">{t("movie.continue_hint")}</p>
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-5 min-[2200px]:grid-cols-6">
              {continueWatching.map((item) => (
                <button
                  key={item.contentSlug}
                  onClick={() => navigate(`/watch/${item.contentSlug}`)}
                  className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 text-left transition hover:border-white/20 hover:bg-white/10"
                >
                  <div className="flex gap-4 p-4">
                    <div className="h-24 w-16 shrink-0 overflow-hidden rounded-lg bg-surface">
                      {item.posterUrl ? (
                        <img
                          src={resizedImageUrl(item.posterUrl, { width: 160, height: 240 })}
                          alt={item.title}
                          className="h-full w-full object-cover"
                          loading="lazy"
                          decoding="async"
                        />
                      ) : null}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="line-clamp-2 font-semibold text-white">{item.title}</p>
                      <p className="mt-2 text-xs text-gray-400">
                        {t("movie.minutes_from_total", {
                          position: Math.floor(item.positionSeconds / 60),
                          duration: Math.max(1, Math.floor(item.durationSeconds / 60)),
                        })}
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
            <Carousel title={t("movie.free_to_watch")} movies={homeSections.freeToWatch} />
            <Carousel title={t("nav.movies")} movies={homeSections.movies} onSeeAll={() => navigate("/search?type=movie")} />
            <Carousel title={t("nav.series")} movies={homeSections.series} onSeeAll={() => navigate("/search?type=series")} />
          </>
        )}
      </div>
    </div>
  );
}
