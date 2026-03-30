import React, { useEffect, useState } from "react";
import { useParams, useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";
import { VideoPlayer } from "../components/VideoPlayer";
import { getContentDetail } from "../lib/storefront";
import { fetchStorefrontPlayback } from "../lib/session";
import { Movie } from "../types";

export function PlayerPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { isAuthenticated, openAuthModal, isLoading: isAuthLoading } = useAuth();
  const { currentLanguage } = useLanguage();
  const [movie, setMovie] = useState<Movie | null>(null);
  const [playbackUrl, setPlaybackUrl] = useState<string | null>(null);
  const [episodeTitle, setEpisodeTitle] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const episodeId = searchParams.get("episode") ?? undefined;

  useEffect(() => {
    if (!id || isAuthLoading) {
      return;
    }

    if (!isAuthenticated) {
      openAuthModal();
      navigate(`/movie/${id}`);
      return;
    }

    let active = true;
    async function loadMovie() {
      setIsLoading(true);

      try {
        const [detail, playback] = await Promise.all([
          getContentDetail(currentLanguage.code, id),
          fetchStorefrontPlayback(id, {
            locale: currentLanguage.code,
            episodeId,
          }),
        ]);

        if (!active) {
          return;
        }

        setMovie(detail);
        setPlaybackUrl(playback.playback.url);
        setEpisodeTitle(playback.episode?.title ?? null);
      } catch {
        if (active) {
          navigate(`/movie/${id}`);
        }
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
  }, [currentLanguage.code, episodeId, id, isAuthenticated, isAuthLoading, navigate, openAuthModal]);

  if (isLoading || isAuthLoading) {
    return <div className="min-h-screen bg-black" />;
  }

  if (!isAuthenticated || !movie || !playbackUrl) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-50 bg-black">
      <VideoPlayer
        movie={movie}
        sourceUrl={playbackUrl}
        episodeTitle={episodeTitle}
        onBack={() => navigate(`/movie/${movie.id}`)}
      />
    </div>
  );
}
