import React, { useEffect, useState } from "react";
import { useParams, useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";
import { VideoPlayer } from "../components/VideoPlayer";
import { getContentDetail } from "../lib/storefront";
import {
  fetchStorefrontPlayback,
  RequestErrorWithPayload,
  startStorefrontPlaybackSession,
  updateStorefrontWatchProgress,
} from "../lib/session";
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
  const [sessionToken, setSessionToken] = useState<string | null>(null);
  const [contentFormatId, setContentFormatId] = useState<number | null>(null);
  const [initialPositionSeconds, setInitialPositionSeconds] = useState(0);
  const [premiereLock, setPremiereLock] = useState<{
    title: string;
    startsAt: string;
    endsAt?: string | null;
    message?: string;
  } | null>(null);
  const [playbackError, setPlaybackError] = useState<string | null>(null);
  const [subtitles, setSubtitles] = useState<Array<{
    id: number;
    locale: string;
    label: string;
    url: string;
    is_default: boolean;
  }>>([]);
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

        setPremiereLock(null);
        setPlaybackError(null);
        setMovie(detail);
        setPlaybackUrl(playback.playback.url);
        setEpisodeTitle(playback.episode?.title ?? null);
        setSessionToken(playback.playback.session_token ?? null);
        setContentFormatId(playback.playback.content_format_id ?? null);
        setInitialPositionSeconds(playback.continue_watching?.position_seconds ?? 0);
        setSubtitles(playback.subtitles ?? []);

        if (!playback.playback.session_token) {
          const session = await startStorefrontPlaybackSession(id, {
            content_format_id: playback.playback.content_format_id ?? null,
            device_type: window.innerWidth > window.innerHeight ? "landscape" : "portrait",
            country_code: playback.playback.country_code ?? null,
          });

          if (active) {
            setSessionToken(session.session.token);
          }
        }
      } catch (error) {
        if (!active) {
          return;
        }

        const requestError = error as RequestErrorWithPayload;
        const premiereEvent = requestError.payload?.premiere_event as
          | { title?: string; starts_at?: string; ends_at?: string | null }
          | undefined;

        if (requestError.status === 423 && premiereEvent?.starts_at) {
          setPremiereLock({
            title: premiereEvent.title || "Digital premiere",
            startsAt: premiereEvent.starts_at,
            endsAt: premiereEvent.ends_at,
            message: requestError.message,
          });
          setPlaybackUrl(null);
          setPlaybackError(null);
          return;
        }

        setPlaybackError(requestError.message || "Playback could not be started.");
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

  if (premiereLock && movie) {
    const startsAt = new Date(premiereLock.startsAt);
    const now = new Date();
    const remainingMs = Math.max(0, startsAt.getTime() - now.getTime());
    const remainingMinutes = Math.floor(remainingMs / 1000 / 60);
    const hours = Math.floor(remainingMinutes / 60);
    const minutes = remainingMinutes % 60;

    return (
      <div className="fixed inset-0 z-50 flex min-h-screen items-center justify-center bg-black px-6 text-white">
        <div className="mx-auto w-full max-w-2xl rounded-3xl border border-white/10 bg-white/5 p-8 backdrop-blur-md">
          <div className="space-y-4 text-center">
            <p className="text-sm uppercase tracking-[0.35em] text-white/60">Watch Party</p>
            <h1 className="text-3xl font-semibold">{movie.title}</h1>
            <p className="text-lg text-white/80">{premiereLock.title}</p>
            <p className="text-white/70">
              {premiereLock.message || "Playback opens automatically when the premiere starts."}
            </p>
            <div className="rounded-2xl border border-white/10 bg-black/30 p-5">
              <p className="text-sm text-white/60">Starts at</p>
              <p className="mt-1 text-xl font-medium">{startsAt.toLocaleString()}</p>
              <p className="mt-3 text-sm text-white/70">
                {hours > 0 ? `${hours}h ` : ""}{minutes}m remaining
              </p>
            </div>
          </div>
          <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
            <button
              onClick={() => navigate(`/movie/${movie.id}`)}
              className="rounded-full border border-white/15 px-5 py-2 text-sm text-white/80 transition hover:bg-white/10"
            >
              Back to details
            </button>
            <button
              onClick={() => window.location.reload()}
              className="rounded-full bg-white px-5 py-2 text-sm font-medium text-black transition hover:bg-white/90"
            >
              Retry access
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (playbackError && movie) {
    return (
      <div className="fixed inset-0 z-50 flex min-h-screen items-center justify-center bg-black px-6 text-white">
        <div className="mx-auto w-full max-w-xl rounded-3xl border border-white/10 bg-white/5 p-8 text-center backdrop-blur-md">
          <h1 className="text-2xl font-semibold">{movie.title}</h1>
          <p className="mt-3 text-white/75">{playbackError}</p>
          <button
            onClick={() => navigate(`/movie/${movie.id}`)}
            className="mt-6 rounded-full bg-white px-5 py-2 text-sm font-medium text-black transition hover:bg-white/90"
          >
            Back to details
          </button>
        </div>
      </div>
    );
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
        sessionToken={sessionToken}
        contentFormatId={contentFormatId}
        episodeId={episodeId ?? null}
        initialPositionSeconds={initialPositionSeconds}
        subtitles={subtitles}
        onProgress={(payload) => {
          if (!sessionToken) {
            return;
          }

          void updateStorefrontWatchProgress({
            session_token: sessionToken,
            content_id: movie.id,
            content_format_id: contentFormatId,
            episode_id: episodeId ?? null,
            position_seconds: payload.position_seconds,
            duration_seconds: payload.duration_seconds,
            watch_time_seconds: payload.watch_time_seconds,
            event_type: payload.event_type,
          });
        }}
        onBack={() => navigate(`/movie/${movie.id}`)}
      />
    </div>
  );
}
