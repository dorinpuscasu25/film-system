import React, { useEffect, useMemo, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { motion, AnimatePresence } from "framer-motion";
import {
  PlayIcon,
  Share2Icon,
  StarIcon,
  ClockIcon,
  HeartIcon,
  XIcon,
} from "lucide-react";
import { MOCK_REVIEWS } from "../data/mockData";
import { useWallet } from "../contexts/WalletContext";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";
import { Tabs } from "../components/Tabs";
import { ReviewCard } from "../components/ReviewCard";
import { PurchaseModal } from "../components/PurchaseModal";
import { Carousel } from "../components/Carousel";
import { getCatalogPage, getContentDetail } from "../lib/storefront";
import { fetchStorefrontRecommendations } from "../lib/session";
import { Movie } from "../types";

function formatCountdown(targetDate?: string) {
  if (!targetDate) {
    return null;
  }

  const now = Date.now();
  const target = new Date(targetDate).getTime();
  const diff = target - now;

  if (diff <= 0) {
    return "Now live";
  }

  const totalMinutes = Math.floor(diff / 60000);
  const days = Math.floor(totalMinutes / (60 * 24));
  const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
  const minutes = totalMinutes % 60;

  if (days > 0) {
    return `${days}d ${hours}h ${minutes}m`;
  }

  if (hours > 0) {
    return `${hours}h ${minutes}m`;
  }

  return `${minutes}m`;
}

export function MovieDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasAccess, getTimeRemaining, toggleFavorite, isFavorite } = useWallet();
  const { isAuthenticated, openAuthModal, isLoading: isAuthLoading } = useAuth();
  const { currentLanguage } = useLanguage();
  const [activeTab, setActiveTab] = useState("description");
  const [isPurchaseModalOpen, setIsPurchaseModalOpen] = useState(false);
  const [showTrailerModal, setShowTrailerModal] = useState(false);
  const [activeSeason, setActiveSeason] = useState(1);
  const [movie, setMovie] = useState<Movie | null>(null);
  const [catalog, setCatalog] = useState<Movie[]>([]);
  const [recommendedSlugs, setRecommendedSlugs] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    window.scrollTo(0, 0);
  }, [id]);

  useEffect(() => {
    if (!id) {
      return;
    }

    let active = true;

    async function loadMovie() {
      setIsLoading(true);
      setError(null);

      try {
        const detail = await getContentDetail(currentLanguage.code, id);

        if (!active) {
          return;
        }

        setMovie(detail);
        setRecommendedSlugs([]);
        setActiveSeason(detail.seasonsData?.[0]?.seasonNumber ?? 1);

        try {
          const relatedContent = await getCatalogPage(currentLanguage.code, {
            page: 1,
            pageSize: 24,
          });

          if (active) {
            setCatalog(relatedContent.items);
          }
        } catch {
          if (active) {
            setCatalog([]);
          }
        }
      } catch (loadError) {
        if (!active) {
          return;
        }

        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca titlul.");
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void loadMovie();

    return () => {
      active = false;
    };
  }, [currentLanguage.code, id]);

  useEffect(() => {
    if (!id || !isAuthenticated) {
      setRecommendedSlugs([]);
      return;
    }

    let active = true;

    async function loadRecommendations() {
      try {
        const response = await fetchStorefrontRecommendations(id, currentLanguage.code);
        if (!active) {
          return;
        }

        setRecommendedSlugs((response.items ?? []).map((item) => item.slug));
      } catch {
        if (active) {
          setRecommendedSlugs([]);
        }
      }
    }

    void loadRecommendations();

    return () => {
      active = false;
    };
  }, [currentLanguage.code, id, isAuthenticated]);

  const relatedMovies = useMemo(() => {
    if (!movie) {
      return [];
    }

    if (recommendedSlugs.length > 0) {
      const recommendedSet = new Set(recommendedSlugs);
      const recommendedItems = catalog.filter((item) => item.id !== movie.id && recommendedSet.has(item.id));
      if (recommendedItems.length > 0) {
        return recommendedItems;
      }
    }

    return catalog.filter(
      (item) => item.id !== movie.id && item.genres.some((genre) => movie.genres.includes(genre)),
    );
  }, [catalog, movie, recommendedSlugs]);

  if (isLoading) {
    return (
      <div className="flex min-h-[70vh] items-center justify-center bg-background text-gray-400">
        Loading title...
      </div>
    );
  }

  if (error || !movie) {
    return (
      <div className="min-h-screen bg-background px-4 pt-32 pb-20">
        <div className="mx-auto max-w-3xl rounded-2xl border border-white/10 bg-surface p-8 text-center">
          <h1 className="mb-3 text-2xl font-bold text-white">Movie not found</h1>
          <p className="text-gray-400">{error ?? "This title is not available right now."}</p>
        </div>
      </div>
    );
  }

  const access = isAuthenticated && hasAccess(movie.id);
  const timeRemaining = access ? getTimeRemaining(movie.id) : null;
  const isFav = isFavorite(movie.id);
  const priceFrom = movie.offers && movie.offers.length > 0
    ? Math.min(...movie.offers.map((offer) => offer.price))
    : movie.price;
  const videos = movie.videos && movie.videos.length > 0
    ? movie.videos
    : [{
        id: `${movie.id}-trailer`,
        type: "trailer" as const,
        title: "Official Trailer",
        videoUrl: movie.trailerUrl,
        thumbnailUrl: movie.backdropUrl,
        isPrimary: true,
      }];
  const primaryVideo = videos.find((video) => video.isPrimary) || videos[0];
  const premiereCountdown = formatCountdown(movie.premiereEvent?.startsAt);
  const seasonsData = movie.seasonsData && movie.seasonsData.length > 0
    ? movie.seasonsData
    : movie.type === "series"
      ? [{
          id: `${movie.id}-season-1`,
          seasonNumber: 1,
          title: "Season 1",
          episodes: Array.from({ length: movie.episodes || 0 }).map((_, index) => ({
            id: `ep-${index + 1}`,
            episodeNumber: index + 1,
            title: `Episode ${index + 1}`,
            runtimeMinutes: 45,
            thumbnailUrl: movie.backdropUrl,
            videoUrl: movie.trailerUrl,
          })),
        }]
      : [];

  const handleBuyAccess = () => {
    if (!isAuthenticated) {
      openAuthModal();
      return;
    }

    if (priceFrom === 0) {
      navigate(`/watch/${movie.id}`);
      return;
    }

    setIsPurchaseModalOpen(true);
  };

  const handleWatch = () => {
    if (!isAuthenticated) {
      openAuthModal();
      return;
    }

    navigate(`/watch/${movie.id}`);
  };

  const handleFavorite = () => {
    if (!isAuthenticated) {
      openAuthModal();
      return;
    }

    void toggleFavorite(movie.id);
  };

  const tabs = [
    { id: "description", label: "Description" },
    ...(movie.type === "series" ? [{ id: "episodes", label: "Episodes" }] : []),
    { id: "cast", label: "Cast & Crew" },
    { id: "trailers", label: "Trailers & Extras" },
    { id: "reviews", label: "Reviews" },
  ];

  const currentSeason = seasonsData.find((season) => season.seasonNumber === activeSeason) || seasonsData[0];

  return (
    <div className="min-h-screen bg-background pb-20">
      <div className="group relative h-[70vh] w-full">
        <div className="absolute inset-0">
          <img src={movie.backdropUrl} alt={movie.title} className="h-full w-full object-cover opacity-60" />
          <div className="absolute inset-0 bg-gradient-to-t from-background via-background/80 to-transparent" />
        </div>

        <div className="absolute inset-0 z-10 flex items-center justify-center">
          <button
            onClick={() => setShowTrailerModal(true)}
            className="flex h-20 w-20 scale-90 items-center justify-center rounded-full border border-white/20 bg-white/10 opacity-0 backdrop-blur-md transition-all duration-300 group-hover:scale-100 group-hover:opacity-100 hover:bg-accent/90"
          >
            <PlayIcon className="ml-1 h-8 w-8 fill-current text-white" />
          </button>
        </div>
      </div>

      <div className="container relative z-20 mx-auto -mt-64 px-4 md:px-8">
        <div className="flex flex-col gap-8 md:flex-row">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="hidden w-64 flex-shrink-0 md:block"
          >
            <img src={movie.posterUrl} alt={movie.title} className="w-full rounded-xl border border-white/10 shadow-2xl" />
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 }}
            className="flex-1 pt-8 md:pt-32"
          >
            <h1 className="mb-2 text-4xl font-bold text-white md:text-5xl">{movie.title}</h1>
            <p className="mb-6 text-gray-400">Original title: {movie.originalTitle}</p>

            <div className="mb-8 flex flex-wrap items-center gap-4">
              <div className="flex items-center space-x-1 rounded-full bg-surfaceHover px-3 py-1 text-sm">
                <StarIcon className="h-4 w-4 fill-current text-accentGold" />
                <span className="font-bold text-white">{movie.rating.toFixed(1)}</span>
                <span className="text-gray-400">IMDb</span>
              </div>
              <span className="text-gray-300">{movie.year}</span>
              <span className="text-gray-500">•</span>
              <span className="text-gray-300">{movie.country}</span>
              <span className="text-gray-500">•</span>
              <span className="capitalize text-gray-300">{movie.type}</span>
              <span className="text-gray-500">•</span>
              <div className="flex flex-wrap gap-2">
                {movie.genres.map((genre) => (
                  <span key={genre} className="rounded border border-white/20 px-2 py-0.5 text-sm text-gray-300">
                    {genre}
                  </span>
                ))}
              </div>
            </div>

            {movie.premiereEvent ? (
              <div className="mb-8 rounded-2xl border border-amber-400/20 bg-amber-400/10 p-4 text-amber-50">
                <p className="text-xs font-semibold uppercase tracking-[0.25em] text-amber-200">Digital Premiere</p>
                <p className="mt-2 text-lg font-semibold">{movie.premiereEvent.title}</p>
                <p className="mt-1 text-sm text-amber-100/80">
                  Starts at {new Date(movie.premiereEvent.startsAt).toLocaleString()}
                </p>
                {premiereCountdown ? (
                  <p className="mt-3 inline-flex rounded-full border border-amber-200/20 bg-black/20 px-3 py-1 text-sm font-medium">
                    {premiereCountdown}
                  </p>
                ) : null}
              </div>
            ) : null}

            <div className="mb-12 flex flex-wrap items-center gap-4">
              {access ? (
                <div className="flex items-center gap-4">
                  <button
                    onClick={handleWatch}
                    className="flex items-center space-x-2 rounded-lg bg-accent px-8 py-3 font-bold text-white transition-colors hover:bg-red-700"
                  >
                    <PlayIcon className="h-5 w-5 fill-current" />
                    <span>Watch Now</span>
                  </button>
                  {timeRemaining ? (
                    <div className="flex items-center space-x-2 rounded-lg border border-white/10 bg-surface px-4 py-2 text-sm text-gray-400">
                      <ClockIcon className="h-4 w-4" />
                      <span>
                        {timeRemaining === 'Lifetime' ? 'Access:' : 'Access ends in:'}{' '}
                        <strong className="text-white">{timeRemaining}</strong>
                      </span>
                    </div>
                  ) : null}
                </div>
              ) : (
                <button
                  onClick={handleBuyAccess}
                  className="flex items-center space-x-2 rounded-lg bg-accent px-8 py-3 font-bold text-white transition-colors hover:bg-red-700"
                >
                  <PlayIcon className="h-5 w-5 fill-current" />
                  <span>{priceFrom === 0 ? "Watch Free" : `Buy Access - $${priceFrom}`}</span>
                </button>
              )}

              <button
                onClick={handleFavorite}
                className={`flex h-12 w-12 items-center justify-center rounded-lg border transition-colors ${isFav ? "border-accent bg-accent/20 text-accent" : "border-white/10 bg-surfaceHover text-white hover:bg-white/10"}`}
              >
                <HeartIcon className={`h-6 w-6 ${isFav ? "fill-current" : ""}`} />
              </button>
              <button className="flex h-12 w-12 items-center justify-center rounded-lg border border-white/10 bg-surfaceHover text-white transition-colors hover:bg-white/10">
                <Share2Icon className="h-5 w-5" />
              </button>
            </div>

            <div className="mb-12">
              <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

              <div className="mt-8 min-h-[200px]">
                {activeTab === "description" ? (
                  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="max-w-3xl text-lg leading-relaxed text-gray-300">
                    {movie.description}
                  </motion.div>
                ) : null}

                {activeTab === "episodes" && movie.type === "series" ? (
                  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
                    <div className="mb-8 flex space-x-6 border-b border-white/10">
                      {seasonsData.map((season) => (
                        <button
                          key={season.id}
                          onClick={() => setActiveSeason(season.seasonNumber)}
                          className={`relative pb-4 text-lg font-bold ${activeSeason === season.seasonNumber ? "text-white" : "text-gray-500 hover:text-gray-300"}`}
                        >
                          {(season.title || `SEASON ${season.seasonNumber}`).toUpperCase()}
                          {activeSeason === season.seasonNumber ? (
                            <motion.div layoutId="seasonTab" className="absolute bottom-0 left-0 right-0 h-0.5 bg-accent" />
                          ) : null}
                        </button>
                      ))}
                    </div>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                      {(currentSeason?.episodes || []).map((episode, index) => (
                        <div
                          key={episode.id}
                          className="group cursor-pointer"
                          onClick={() => {
                            if (isAuthLoading) {
                              return;
                            }

                            if (!isAuthenticated) {
                              openAuthModal();
                              return;
                            }

                            if (!hasAccess(movie.id)) {
                              if (priceFrom === 0) {
                                navigate(`/watch/${movie.id}?episode=${episode.id}`);
                              } else {
                                setIsPurchaseModalOpen(true);
                              }
                              return;
                            }

                            navigate(`/watch/${movie.id}?episode=${episode.id}`);
                          }}
                        >
                          <div className="relative mb-3 aspect-video overflow-hidden rounded-xl border border-white/10">
                            <img
                              src={episode.thumbnailUrl || episode.backdropUrl || movie.backdropUrl}
                              alt={episode.title}
                              className="h-full w-full object-cover opacity-70 transition-opacity group-hover:opacity-100"
                            />
                            <div className="absolute inset-0 flex items-center justify-center">
                              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-black/50 backdrop-blur-sm transition-colors group-hover:bg-accent">
                                <PlayIcon className="ml-1 h-5 w-5 fill-current text-white" />
                              </div>
                            </div>
                            <div className="absolute bottom-2 right-2 rounded bg-black/80 px-2 py-1 text-xs font-medium text-white">
                              {episode.runtimeMinutes ? `${episode.runtimeMinutes}m` : "45m"}
                            </div>
                          </div>
                          <h4 className="font-bold text-white">{episode.title}</h4>
                          <p className="text-sm text-gray-400">Episode {episode.episodeNumber || index + 1}</p>
                        </div>
                      ))}
                    </div>
                  </motion.div>
                ) : null}

                {activeTab === "cast" ? (
                  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div className="col-span-full">
                      <h3 className="mb-4 text-sm uppercase tracking-[0.2em] text-gray-500">Cast</h3>
                    </div>
                    {movie.cast.map((actor) => (
                      <div key={actor.id} className="flex items-center space-x-4">
                        <img src={actor.avatarUrl} alt={actor.name} className="h-16 w-16 rounded-full object-cover" />
                        <div>
                          <p className="font-medium text-white">{actor.name}</p>
                          <p className="text-sm text-gray-400">{actor.role}</p>
                        </div>
                      </div>
                    ))}
                    {(movie.crew || []).length > 0 ? (
                      <>
                        <div className="col-span-full pt-4">
                          <h3 className="mb-4 text-sm uppercase tracking-[0.2em] text-gray-500">Crew</h3>
                        </div>
                        {(movie.crew || []).map((member) => (
                          <div key={member.id} className="flex items-center space-x-4">
                            <img src={member.avatarUrl} alt={member.name} className="h-16 w-16 rounded-full object-cover" />
                            <div>
                              <p className="font-medium text-white">{member.name}</p>
                              <p className="text-sm text-gray-400">{member.job}</p>
                            </div>
                          </div>
                        ))}
                      </>
                    ) : null}
                  </motion.div>
                ) : null}

                {activeTab === "trailers" ? (
                  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="grid max-w-5xl gap-4 md:grid-cols-2">
                    {videos.map((video) => (
                      <div
                        key={video.id}
                        className="group relative aspect-video cursor-pointer overflow-hidden rounded-xl border border-white/10 bg-black"
                        onClick={() => setShowTrailerModal(true)}
                      >
                        <img
                          src={video.thumbnailUrl || movie.backdropUrl}
                          alt={video.title}
                          className="h-full w-full object-cover opacity-60"
                        />
                        <div className="absolute inset-0 flex items-center justify-center">
                          <button className="flex h-16 w-16 items-center justify-center rounded-full bg-accent/90 backdrop-blur-md transition-transform group-hover:scale-110 hover:bg-accent">
                            <PlayIcon className="ml-1 h-8 w-8 fill-current text-white" />
                          </button>
                        </div>
                        <div className="absolute bottom-4 left-4">
                          <h3 className="text-lg font-bold text-white">{video.title}</h3>
                        </div>
                      </div>
                    ))}
                  </motion.div>
                ) : null}

                {activeTab === "reviews" ? (
                  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="max-w-3xl space-y-6">
                    {MOCK_REVIEWS.map((review) => (
                      <ReviewCard key={review.id} review={review} />
                    ))}
                  </motion.div>
                ) : null}
              </div>
            </div>
          </motion.div>
        </div>
      </div>

      <div className="mt-12">
        <Carousel title="You May Also Like" movies={relatedMovies.slice(0, 12)} />
      </div>

      <PurchaseModal
        isOpen={isPurchaseModalOpen}
        onClose={() => setIsPurchaseModalOpen(false)}
        movie={movie}
        onSuccess={() => navigate(`/watch/${movie.id}`)}
      />

      <AnimatePresence>
        {showTrailerModal ? (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 md:p-12">
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              className="absolute inset-0 bg-black/95 backdrop-blur-md"
              onClick={() => setShowTrailerModal(false)}
            />

            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="relative z-10 aspect-video w-full max-w-6xl overflow-hidden rounded-2xl border border-white/10 bg-black shadow-2xl"
            >
              <button
                onClick={() => setShowTrailerModal(false)}
                className="absolute top-4 right-4 z-20 rounded-full bg-black/50 p-2 text-white backdrop-blur-md transition-colors hover:bg-accent"
              >
                <XIcon className="h-6 w-6" />
              </button>

              <div className="relative flex h-full w-full items-center justify-center">
                <img
                  src={primaryVideo?.thumbnailUrl || movie.backdropUrl}
                  alt="Trailer"
                  className="absolute inset-0 h-full w-full object-cover opacity-40"
                />

                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="flex h-24 w-24 items-center justify-center rounded-full bg-accent/80">
                    <PlayIcon className="ml-2 h-10 w-10 fill-current text-white" />
                  </div>
                </div>
                <div className="absolute bottom-8 left-8 right-8">
                  <div className="mb-4 h-1 overflow-hidden rounded-full bg-white/20">
                    <div className="h-full w-1/3 bg-accent" />
                  </div>
                  <h3 className="text-xl font-bold text-white">
                    {primaryVideo?.title || movie.title}
                  </h3>
                </div>
              </div>
            </motion.div>
          </div>
        ) : null}
      </AnimatePresence>
    </div>
  );
}
