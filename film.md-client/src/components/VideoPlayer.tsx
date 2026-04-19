import React, { useEffect, useRef } from 'react';
import { ArrowLeftIcon } from 'lucide-react';
import { Movie } from '../types';
import { useWallet } from '../contexts/WalletContext';
interface VideoPlayerProps {
  movie: Movie;
  sourceUrl: string;
  episodeTitle?: string | null;
  sessionToken?: string | null;
  contentFormatId?: number | null;
  episodeId?: string | null;
  initialPositionSeconds?: number;
  subtitles?: Array<{
    id: number;
    locale: string;
    label: string;
    url: string;
    is_default: boolean;
  }>;
  onProgress?: (payload: {
    position_seconds: number;
    duration_seconds: number;
    watch_time_seconds: number;
    event_type: string;
  }) => void;
  onBack: () => void;
}
export function VideoPlayer({
  movie,
  sourceUrl,
  episodeTitle,
  sessionToken,
  contentFormatId,
  episodeId,
  initialPositionSeconds = 0,
  subtitles = [],
  onProgress,
  onBack,
}: VideoPlayerProps) {
  const { getTimeRemaining } = useWallet();
  const timeRemaining = getTimeRemaining(movie.id);
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const watchSecondsRef = useRef(0);
  const intervalRef = useRef<number | null>(null);

  useEffect(() => {
    const video = videoRef.current;
    if (!video) {
      return;
    }

    const safeStart = Math.max(0, initialPositionSeconds || 0);
    if (safeStart > 0) {
      video.currentTime = safeStart;
    }

    const sync = (eventType: string) => {
      if (!videoRef.current || !onProgress) {
        return;
      }

      onProgress({
        position_seconds: Math.floor(videoRef.current.currentTime || 0),
        duration_seconds: Math.floor(videoRef.current.duration || 0),
        watch_time_seconds: watchSecondsRef.current,
        event_type: eventType,
      });
    };

    const onPlay = () => sync('play');
    const onPause = () => sync('pause');
    const onEnded = () => sync('complete');

    video.addEventListener('play', onPlay);
    video.addEventListener('pause', onPause);
    video.addEventListener('ended', onEnded);

    intervalRef.current = window.setInterval(() => {
      if (!videoRef.current || videoRef.current.paused || videoRef.current.ended) {
        return;
      }

      watchSecondsRef.current += 5;
      sync('heartbeat');
    }, 5000);

    return () => {
      sync('stop');
      video.removeEventListener('play', onPlay);
      video.removeEventListener('pause', onPause);
      video.removeEventListener('ended', onEnded);
      if (intervalRef.current !== null) {
        window.clearInterval(intervalRef.current);
      }
    };
  }, [contentFormatId, episodeId, initialPositionSeconds, onProgress, sessionToken]);

  return (
    <div className="relative flex h-screen w-full items-center justify-center overflow-hidden bg-black">
      <video
        ref={videoRef}
        key={sourceUrl}
        src={sourceUrl}
        className="h-full w-full bg-black object-contain"
        controls
        autoPlay
        playsInline
        poster={movie.backdropUrl || movie.posterUrl}
      >
        {subtitles.map((track) => (
          <track
            key={track.id}
            src={track.url}
            kind="subtitles"
            srcLang={track.locale}
            label={track.label}
            default={track.is_default}
          />
        ))}
      </video>

      <div className="pointer-events-none absolute inset-x-0 top-0 z-20 bg-gradient-to-b from-black/90 via-black/50 to-transparent p-4 sm:p-6">
        <div className="flex items-start justify-between gap-4">
          <div className="pointer-events-auto flex min-w-0 items-center space-x-3 sm:space-x-4">
            <button
              onClick={onBack}
              className="rounded-full bg-black/50 p-2 text-white transition-colors hover:bg-black/70"
            >
              <ArrowLeftIcon className="h-6 w-6" />
            </button>
            <div className="min-w-0">
              <h1 className="truncate text-base font-bold text-white sm:text-xl">{movie.title}</h1>
              {episodeTitle ? <p className="truncate text-xs text-gray-300 sm:text-sm">{episodeTitle}</p> : null}
            </div>
          </div>

          {timeRemaining ? (
            <div className="hidden rounded-full border border-white/10 bg-black/50 px-3 py-1 text-sm text-gray-200 backdrop-blur-md sm:block">
              {timeRemaining === 'Lifetime' ? 'Lifetime access' : `Access ends in ${timeRemaining}`}
            </div>
          ) : null}
        </div>
      </div>
    </div>);

}
