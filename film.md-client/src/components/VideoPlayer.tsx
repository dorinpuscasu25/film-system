import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import shaka from 'shaka-player';
import {
  AlertTriangleIcon,
  ArrowLeftIcon,
  CaptionsIcon,
  CheckIcon,
  Loader2Icon,
  MaximizeIcon,
  MinimizeIcon,
  PauseIcon,
  PictureInPicture2Icon,
  PlayIcon,
  RotateCcwIcon,
  RotateCwIcon,
  SettingsIcon,
  Volume1Icon,
  Volume2Icon,
  VolumeXIcon,
} from 'lucide-react';
import { Movie } from '../types';
import { useWallet } from '../contexts/WalletContext';

interface PlaybackDrmConfig {
  policy?: string | null;
  servers?: Record<string, string>;
  headers?: Record<string, string>;
  clear_keys?: Record<string, string>;
}

interface VideoPlayerProps {
  movie: Movie;
  sourceUrl: string;
  embedUrl?: string | null;
  quality?: string | null;
  drm?: PlaybackDrmConfig | null;
  episodeTitle?: string | null;
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

type VariantTrack = shaka.extern.Track;
type TextTrack = shaka.extern.TextTrack;

const RATES = [0.5, 0.75, 1, 1.25, 1.5, 2];

function isDirectMediaUrl(url: string) {
  return /\.(m3u8|mpd|mp4|webm|ogg)(\?.*)?$/i.test(url);
}

function isBunnyApiAssetUrl(url: string) {
  return /video\.bunnycdn\.com\/[^/?#]+\/[^/?#]+/i.test(url) && !isDirectMediaUrl(url);
}

function inferBunnyEmbedUrl(url: string) {
  if (url.includes('iframe.mediadelivery.net/embed/')) {
    return url;
  }

  const match = url.match(/video\.bunnycdn\.com\/([^/?#]+)\/([^/?#]+)/i);
  if (!match) {
    return null;
  }

  if (!/^\d+$/.test(match[1])) {
    return null;
  }

  return `https://iframe.mediadelivery.net/embed/${encodeURIComponent(match[1])}/${encodeURIComponent(match[2])}?autoplay=true&responsive=true`;
}

function asStringRecord(value: unknown) {
  if (!value || Array.isArray(value) || typeof value !== 'object') {
    return {};
  }

  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>).filter(
      (entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1].trim() !== '',
    ),
  );
}

function formatTime(seconds: number) {
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return '0:00';
  }

  const totalSeconds = Math.floor(seconds);
  const hrs = Math.floor(totalSeconds / 3600);
  const mins = Math.floor((totalSeconds % 3600) / 60);
  const secs = totalSeconds % 60;

  if (hrs > 0) {
    return `${hrs}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }

  return `${mins}:${String(secs).padStart(2, '0')}`;
}

function qualityLabel(track: VariantTrack) {
  if (track.height) {
    return `${track.height}p`;
  }

  if (track.bandwidth) {
    return `${Math.round(track.bandwidth / 1000)} kbps`;
  }

  return track.label || `Track ${track.id}`;
}

function uniqueVariantTracks(tracks: VariantTrack[]) {
  const seen = new Set<string>();

  return tracks
    .filter((track) => track.type === 'variant')
    .sort((a, b) => (b.height ?? 0) - (a.height ?? 0) || b.bandwidth - a.bandwidth)
    .filter((track) => {
      const key = `${track.height ?? 0}-${track.bandwidth}`;
      if (seen.has(key)) {
        return false;
      }

      seen.add(key);
      return true;
    });
}

function drmConfig(drm?: PlaybackDrmConfig | null) {
  if (!drm) {
    return {};
  }

  const servers = asStringRecord(drm.servers);
  const clearKeys = asStringRecord(drm.clear_keys);
  const headers = asStringRecord(drm.headers);
  const config: { drm?: Record<string, unknown> } = {};

  if (Object.keys(servers).length > 0 || Object.keys(clearKeys).length > 0) {
    config.drm = {};
  }

  if (config.drm && Object.keys(servers).length > 0) {
    config.drm.servers = servers;

    if (Object.keys(headers).length > 0) {
      config.drm.advanced = Object.fromEntries(
        Object.keys(servers).map((keySystem) => [
          keySystem,
          { headers },
        ]),
      );
    }
  }

  if (config.drm && Object.keys(clearKeys).length > 0) {
    config.drm.clearKeys = clearKeys;
  }

  return config;
}

export function VideoPlayer({
  movie,
  sourceUrl,
  embedUrl,
  quality,
  drm,
  episodeTitle,
  initialPositionSeconds = 0,
  subtitles = [],
  onProgress,
  onBack,
}: VideoPlayerProps) {
  const { getTimeRemaining } = useWallet();
  const timeRemaining = getTimeRemaining(movie.id);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const playerRef = useRef<shaka.Player | null>(null);
  const watchSecondsRef = useRef(0);
  const progressRef = useRef(onProgress);
  const [isReady, setIsReady] = useState(false);
  const [isPlaying, setIsPlaying] = useState(false);
  const [isBuffering, setIsBuffering] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [duration, setDuration] = useState(0);
  const [currentTime, setCurrentTime] = useState(0);
  const [bufferedPercent, setBufferedPercent] = useState(0);
  const [volume, setVolume] = useState(1);
  const [isMuted, setIsMuted] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [selectedRate, setSelectedRate] = useState(1);
  const [variantTracks, setVariantTracks] = useState<VariantTrack[]>([]);
  const [selectedVariant, setSelectedVariant] = useState<'auto' | number>('auto');
  const [textTracks, setTextTracks] = useState<TextTrack[]>([]);
  const [selectedText, setSelectedText] = useState<'off' | number>('off');
  const [settingsPanel, setSettingsPanel] = useState<'quality' | 'speed' | 'subtitles' | null>(null);
  const resolvedEmbedUrl = embedUrl ?? inferBunnyEmbedUrl(sourceUrl);
  const shouldUseBunnyEmbed = Boolean(resolvedEmbedUrl && !isDirectMediaUrl(sourceUrl));

  useEffect(() => {
    progressRef.current = onProgress;
  }, [onProgress]);

  const syncProgress = useCallback((eventType: string) => {
    const video = videoRef.current;
    if (!video || !progressRef.current) {
      return;
    }

    progressRef.current({
      position_seconds: Math.floor(video.currentTime || 0),
      duration_seconds: Math.floor(video.duration || 0),
      watch_time_seconds: watchSecondsRef.current,
      event_type: eventType,
    });
  }, []);

  useEffect(() => {
    if (shouldUseBunnyEmbed) {
      return;
    }

    const video = videoRef.current;
    if (!video) {
      return;
    }

    let active = true;
    shaka.polyfill.installAll();
    setIsReady(false);
    setError(null);

    async function load() {
      if (isBunnyApiAssetUrl(sourceUrl)) {
        setError(
          'URL-ul Bunny primit nu este un manifest video. Setează un library ID numeric + video ID valid pentru embed, sau pune un stream_url direct către .m3u8/.mpd/.mp4.',
        );
        return;
      }

      if (!video || !shaka.Player.isBrowserSupported()) {
        setError('Browserul nu suportă playback modern pentru acest stream.');
        return;
      }

      const player = new shaka.Player();
      playerRef.current = player;
      await player.attach(video);
      player.configure({
        abr: { enabled: true },
        streaming: {
          bufferingGoal: 30,
          rebufferingGoal: 3,
          retryParameters: { maxAttempts: 4, baseDelay: 700, backoffFactor: 2, fuzzFactor: 0.5 },
        },
        ...drmConfig(drm),
      });

      player.addEventListener('error', (event) => {
        const detail = (event as CustomEvent).detail as { message?: string; code?: number } | undefined;
        setError(detail?.message || `Playback error${detail?.code ? ` ${detail.code}` : ''}.`);
      });
      player.addEventListener('buffering', (event) => {
        setIsBuffering(Boolean((event as CustomEvent).detail?.buffering));
      });
      player.addEventListener('variantchanged', () => {
        if (active) {
          setVariantTracks(uniqueVariantTracks(player.getVariantTracks()));
        }
      });
      player.addEventListener('textchanged', () => {
        if (active) {
          const activeTrack = player.getTextTracks().find((track) => track.active);
          setSelectedText(activeTrack?.id ?? 'off');
        }
      });

      try {
        await player.load(sourceUrl, initialPositionSeconds || undefined);
        if (!active) {
          return;
        }

        const variants = uniqueVariantTracks(player.getVariantTracks());
        const tracks = player.getTextTracks();
        setVariantTracks(variants);
        setTextTracks(tracks);
        setSelectedVariant('auto');
        setSelectedText(tracks.find((track) => track.active)?.id ?? 'off');
        setIsReady(true);
        void video.play().catch(() => setIsPlaying(false));
      } catch (loadError) {
        const message = loadError instanceof Error ? loadError.message : 'Nu am putut porni stream-ul.';
        if (active) {
          setError(message);
        }
      }
    }

    void load();

    return () => {
      active = false;
      void playerRef.current?.destroy();
      playerRef.current = null;
    };
  }, [drm, initialPositionSeconds, shouldUseBunnyEmbed, sourceUrl]);

  useEffect(() => {
    if (shouldUseBunnyEmbed) {
      return;
    }

    const video = videoRef.current;
    if (!video) {
      return;
    }

    const updateTime = () => {
      setCurrentTime(video.currentTime || 0);
      setDuration(Number.isFinite(video.duration) ? video.duration : 0);

      if (video.buffered.length > 0 && Number.isFinite(video.duration) && video.duration > 0) {
        setBufferedPercent(Math.min(100, (video.buffered.end(video.buffered.length - 1) / video.duration) * 100));
      }
    };
    const onPlay = () => {
      setIsPlaying(true);
      syncProgress('play');
    };
    const onPause = () => {
      setIsPlaying(false);
      syncProgress('pause');
    };
    const onEnded = () => {
      setIsPlaying(false);
      syncProgress('complete');
    };

    video.addEventListener('timeupdate', updateTime);
    video.addEventListener('durationchange', updateTime);
    video.addEventListener('progress', updateTime);
    video.addEventListener('play', onPlay);
    video.addEventListener('pause', onPause);
    video.addEventListener('ended', onEnded);

    const heartbeat = window.setInterval(() => {
      if (!video.paused && !video.ended) {
        watchSecondsRef.current += 5;
        syncProgress('heartbeat');
      }
    }, 5000);

    return () => {
      syncProgress('stop');
      video.removeEventListener('timeupdate', updateTime);
      video.removeEventListener('durationchange', updateTime);
      video.removeEventListener('progress', updateTime);
      video.removeEventListener('play', onPlay);
      video.removeEventListener('pause', onPause);
      video.removeEventListener('ended', onEnded);
      window.clearInterval(heartbeat);
    };
  }, [shouldUseBunnyEmbed, syncProgress]);

  useEffect(() => {
    const onFullscreenChange = () => setIsFullscreen(document.fullscreenElement === containerRef.current);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', onFullscreenChange);
  }, []);

  const subtitleOptions = useMemo(
    () => [
      ...textTracks.map((track) => ({
        id: track.id,
        label: track.label || track.language.toUpperCase(),
        source: 'manifest' as const,
      })),
      ...subtitles.map((track) => ({
        id: track.id,
        label: track.label,
        source: 'external' as const,
      })),
    ],
    [subtitles, textTracks],
  );

  const togglePlay = () => {
    const video = videoRef.current;
    if (!video) {
      return;
    }

    if (video.paused) {
      void video.play();
    } else {
      video.pause();
    }
  };

  const seekBy = (seconds: number) => {
    const video = videoRef.current;
    if (!video) {
      return;
    }

    video.currentTime = Math.min(Math.max(0, video.currentTime + seconds), video.duration || video.currentTime + seconds);
  };

  const seekToPercent = (percent: number) => {
    const video = videoRef.current;
    if (!video || !duration) {
      return;
    }

    video.currentTime = (percent / 100) * duration;
  };

  const setPlayerVolume = (nextVolume: number) => {
    const video = videoRef.current;
    const normalized = Math.min(1, Math.max(0, nextVolume));
    setVolume(normalized);
    setIsMuted(normalized === 0);

    if (video) {
      video.volume = normalized;
      video.muted = normalized === 0;
    }
  };

  const toggleMute = () => {
    const video = videoRef.current;
    if (!video) {
      return;
    }

    video.muted = !video.muted;
    setIsMuted(video.muted);
  };

  const setPlaybackRate = (rate: number) => {
    if (videoRef.current) {
      videoRef.current.playbackRate = rate;
    }

    setSelectedRate(rate);
    setSettingsPanel(null);
  };

  const selectVariant = (variant: 'auto' | VariantTrack) => {
    const player = playerRef.current;
    if (!player) {
      return;
    }

    if (variant === 'auto') {
      player.configure('abr.enabled', true);
      setSelectedVariant('auto');
    } else {
      player.configure('abr.enabled', false);
      player.selectVariantTrack(variant, true, 5);
      setSelectedVariant(variant.id);
    }

    setSettingsPanel(null);
  };

  const selectSubtitle = async (id: 'off' | number) => {
    const player = playerRef.current;
    const video = videoRef.current;
    setSelectedText(id);

    if (id === 'off') {
      player?.selectTextTrack(null);
      Array.from(video?.textTracks ?? []).forEach((track) => {
        track.mode = 'disabled';
      });
      setSettingsPanel(null);
      return;
    }

    const manifestTrack = textTracks.find((track) => track.id === id);
    if (manifestTrack && player) {
      player.selectTextTrack(manifestTrack);
      setSettingsPanel(null);
      return;
    }

    Array.from(video?.textTracks ?? []).forEach((track) => {
      track.mode = Number(track.id) === id ? 'showing' : 'disabled';
    });
    setSettingsPanel(null);
  };

  const toggleFullscreen = () => {
    if (!containerRef.current) {
      return;
    }

    if (document.fullscreenElement) {
      void document.exitFullscreen();
    } else {
      void containerRef.current.requestFullscreen();
    }
  };

  const enterPictureInPicture = () => {
    const video = videoRef.current;
    if (!video || !document.pictureInPictureEnabled) {
      return;
    }

    void video.requestPictureInPicture();
  };

  if (shouldUseBunnyEmbed && resolvedEmbedUrl) {
    return (
      <div ref={containerRef} className="relative h-screen w-full overflow-hidden bg-black">
        <iframe
          title={`${movie.title} player`}
          src={resolvedEmbedUrl}
          className="h-full w-full border-0"
          allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
        />
        <div className="pointer-events-none absolute inset-x-0 top-0 z-20 bg-gradient-to-b from-black/90 via-black/40 to-transparent p-4 sm:p-6">
          <div className="pointer-events-auto flex items-center justify-between gap-4">
            <div className="flex min-w-0 items-center gap-3">
              <button onClick={onBack} className="rounded-full bg-black/50 p-2 text-white transition hover:bg-black/70">
                <ArrowLeftIcon className="h-6 w-6" />
              </button>
              <div className="min-w-0">
                <h1 className="truncate text-base font-bold text-white sm:text-xl">{movie.title}</h1>
                {episodeTitle ? <p className="truncate text-xs text-gray-300 sm:text-sm">{episodeTitle}</p> : null}
              </div>
            </div>
            <span className="hidden rounded-full border border-white/10 bg-black/50 px-3 py-1 text-sm text-gray-200 sm:block">
              Bunny Stream
            </span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div
      ref={containerRef}
      className="group relative flex h-screen w-full items-center justify-center overflow-hidden bg-black"
      onDoubleClick={toggleFullscreen}
    >
      <video
        ref={videoRef}
        className="h-full w-full bg-black object-contain"
        autoPlay
        playsInline
        crossOrigin="anonymous"
        poster={movie.backdropUrl || movie.posterUrl}
        onClick={togglePlay}
      >
        {subtitles.map((track) => (
          <track
            key={track.id}
            id={String(track.id)}
            src={track.url}
            kind="subtitles"
            srcLang={track.locale}
            label={track.label}
            default={track.is_default}
          />
        ))}
      </video>

      <div className="pointer-events-none absolute inset-x-0 top-0 z-20 bg-gradient-to-b from-black/90 via-black/45 to-transparent p-4 opacity-100 transition sm:p-6">
        <div className="flex items-start justify-between gap-4">
          <div className="pointer-events-auto flex min-w-0 items-center gap-3 sm:gap-4">
            <button onClick={onBack} className="rounded-full bg-black/50 p-2 text-white transition hover:bg-black/70">
              <ArrowLeftIcon className="h-6 w-6" />
            </button>
            <div className="min-w-0">
              <h1 className="truncate text-base font-bold text-white sm:text-xl">{movie.title}</h1>
              <p className="truncate text-xs text-gray-300 sm:text-sm">
                {[episodeTitle, quality].filter(Boolean).join(' • ')}
              </p>
            </div>
          </div>

          {timeRemaining ? (
            <div className="hidden rounded-full border border-white/10 bg-black/50 px-3 py-1 text-sm text-gray-200 backdrop-blur-md sm:block">
              {timeRemaining === 'Lifetime' ? 'Lifetime access' : `Access ends in ${timeRemaining}`}
            </div>
          ) : null}
        </div>
      </div>

      {(isBuffering || !isReady) && !error ? (
        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
          <Loader2Icon className="h-12 w-12 animate-spin text-white/80" />
        </div>
      ) : null}

      {error ? (
        <div className="absolute inset-0 flex items-center justify-center bg-black/70 px-6 text-center text-white">
          <div className="max-w-lg rounded-2xl border border-white/10 bg-white/10 p-6 backdrop-blur-md">
            <AlertTriangleIcon className="mx-auto mb-4 h-10 w-10 text-amber-300" />
            <h2 className="text-xl font-semibold">Playback nu poate porni</h2>
            <p className="mt-2 text-sm text-white/70">{error}</p>
          </div>
        </div>
      ) : null}

      <div className="absolute inset-x-0 bottom-0 z-30 bg-gradient-to-t from-black/95 via-black/65 to-transparent p-4 opacity-100 transition sm:p-6">
        <div className="relative mb-4 h-5">
          <div className="absolute left-0 right-0 top-2 h-1 rounded-full bg-white/20">
            <div className="h-full rounded-full bg-white/35" style={{ width: `${bufferedPercent}%` }} />
            <div className="absolute left-0 top-0 h-full rounded-full bg-accent" style={{ width: `${duration ? (currentTime / duration) * 100 : 0}%` }} />
          </div>
          <input
            aria-label="Seek"
            type="range"
            min={0}
            max={100}
            value={duration ? (currentTime / duration) * 100 : 0}
            onChange={(event) => seekToPercent(Number(event.target.value))}
            className="absolute inset-0 h-5 w-full cursor-pointer opacity-0"
          />
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 text-white">
          <div className="flex items-center gap-2">
            <button onClick={() => seekBy(-10)} className="rounded-full p-2 transition hover:bg-white/10" title="Back 10s">
              <RotateCcwIcon className="h-5 w-5" />
            </button>
            <button onClick={togglePlay} className="rounded-full bg-white p-3 text-black transition hover:bg-white/90" title={isPlaying ? 'Pause' : 'Play'}>
              {isPlaying ? <PauseIcon className="h-5 w-5 fill-current" /> : <PlayIcon className="ml-0.5 h-5 w-5 fill-current" />}
            </button>
            <button onClick={() => seekBy(10)} className="rounded-full p-2 transition hover:bg-white/10" title="Forward 10s">
              <RotateCwIcon className="h-5 w-5" />
            </button>
            <div className="ml-2 hidden text-sm text-white/80 sm:block">
              {formatTime(currentTime)} / {formatTime(duration)}
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button onClick={toggleMute} className="rounded-full p-2 transition hover:bg-white/10" title="Mute">
              {isMuted || volume === 0 ? <VolumeXIcon className="h-5 w-5" /> : volume < 0.5 ? <Volume1Icon className="h-5 w-5" /> : <Volume2Icon className="h-5 w-5" />}
            </button>
            <input
              aria-label="Volume"
              type="range"
              min={0}
              max={1}
              step={0.05}
              value={isMuted ? 0 : volume}
              onChange={(event) => setPlayerVolume(Number(event.target.value))}
              className="hidden w-24 accent-white sm:block"
            />

            <div className="relative">
              <button
                onClick={() => setSettingsPanel(settingsPanel === 'subtitles' ? null : 'subtitles')}
                className="rounded-full p-2 transition hover:bg-white/10"
                title="Subtitles"
              >
                <CaptionsIcon className="h-5 w-5" />
              </button>
              {settingsPanel === 'subtitles' ? (
                <div className="absolute bottom-12 right-0 w-52 rounded-xl border border-white/10 bg-black/90 p-2 text-sm shadow-2xl backdrop-blur">
                  <button onClick={() => void selectSubtitle('off')} className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-white/10">
                    Off {selectedText === 'off' ? <CheckIcon className="h-4 w-4" /> : null}
                  </button>
                  {subtitleOptions.map((track) => (
                    <button
                      key={`${track.source}-${track.id}`}
                      onClick={() => void selectSubtitle(track.id)}
                      className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-white/10"
                    >
                      {track.label}
                      {selectedText === track.id ? <CheckIcon className="h-4 w-4" /> : null}
                    </button>
                  ))}
                </div>
              ) : null}
            </div>

            <div className="relative">
              <button
                onClick={() => setSettingsPanel(settingsPanel === 'quality' ? null : 'quality')}
                className="rounded-full p-2 transition hover:bg-white/10"
                title="Quality"
              >
                <SettingsIcon className="h-5 w-5" />
              </button>
              {settingsPanel === 'quality' ? (
                <div className="absolute bottom-12 right-0 w-52 rounded-xl border border-white/10 bg-black/90 p-2 text-sm shadow-2xl backdrop-blur">
                  <button onClick={() => selectVariant('auto')} className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-white/10">
                    Auto {selectedVariant === 'auto' ? <CheckIcon className="h-4 w-4" /> : null}
                  </button>
                  {variantTracks.map((track) => (
                    <button
                      key={track.id}
                      onClick={() => selectVariant(track)}
                      className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-white/10"
                    >
                      {qualityLabel(track)}
                      {selectedVariant === track.id ? <CheckIcon className="h-4 w-4" /> : null}
                    </button>
                  ))}
                </div>
              ) : null}
            </div>

            <div className="relative">
              <button
                onClick={() => setSettingsPanel(settingsPanel === 'speed' ? null : 'speed')}
                className="rounded-full px-3 py-2 text-sm transition hover:bg-white/10"
                title="Speed"
              >
                {selectedRate}x
              </button>
              {settingsPanel === 'speed' ? (
                <div className="absolute bottom-12 right-0 w-40 rounded-xl border border-white/10 bg-black/90 p-2 text-sm shadow-2xl backdrop-blur">
                  {RATES.map((rate) => (
                    <button
                      key={rate}
                      onClick={() => setPlaybackRate(rate)}
                      className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-white/10"
                    >
                      {rate}x
                      {selectedRate === rate ? <CheckIcon className="h-4 w-4" /> : null}
                    </button>
                  ))}
                </div>
              ) : null}
            </div>

            <button onClick={enterPictureInPicture} className="hidden rounded-full p-2 transition hover:bg-white/10 sm:block" title="Picture in picture">
              <PictureInPicture2Icon className="h-5 w-5" />
            </button>
            <button onClick={toggleFullscreen} className="rounded-full p-2 transition hover:bg-white/10" title="Fullscreen">
              {isFullscreen ? <MinimizeIcon className="h-5 w-5" /> : <MaximizeIcon className="h-5 w-5" />}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
