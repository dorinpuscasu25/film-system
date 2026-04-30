import { useEffect, useRef, useState } from 'react';
import { Volume2Icon, VolumeXIcon } from 'lucide-react';

interface Props {
  trailerUrl: string;
  posterUrl?: string;
  /** Delay before autoplay starts (ms). Default 800ms — avoids triggering on quick fly-overs */
  autoplayDelay?: number;
  className?: string;
}

/**
 * Catalog trailer player. Plays muted on hover/focus after a short delay.
 * Falls back to poster image when not playing. Caiet de sarcini §14.2.
 */
export function TrailerAutoplay({ trailerUrl, posterUrl, autoplayDelay = 800, className }: Props) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const timerRef = useRef<number | null>(null);
  const [isPlaying, setIsPlaying] = useState(false);
  const [isMuted, setIsMuted] = useState(true);

  function startPlayback() {
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current);
    }
    timerRef.current = window.setTimeout(() => {
      const video = videoRef.current;
      if (video === null) return;
      video.muted = true;
      void video
        .play()
        .then(() => setIsPlaying(true))
        .catch(() => {
          /* autoplay blocked — keep poster */
        });
    }, autoplayDelay);
  }

  function stopPlayback() {
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current);
      timerRef.current = null;
    }
    const video = videoRef.current;
    if (video !== null) {
      video.pause();
      video.currentTime = 0;
    }
    setIsPlaying(false);
  }

  useEffect(() => {
    return () => {
      if (timerRef.current !== null) {
        window.clearTimeout(timerRef.current);
      }
    };
  }, []);

  return (
    <div
      className={`relative overflow-hidden rounded-lg group ${className ?? ''}`}
      onMouseEnter={startPlayback}
      onMouseLeave={stopPlayback}
      onFocus={startPlayback}
      onBlur={stopPlayback}
    >
      {posterUrl && (
        <img
          src={posterUrl}
          alt=""
          className={`absolute inset-0 w-full h-full object-cover transition-opacity duration-300 ${
            isPlaying ? 'opacity-0' : 'opacity-100'
          }`}
        />
      )}
      <video
        ref={videoRef}
        src={trailerUrl}
        muted={isMuted}
        playsInline
        loop
        preload="metadata"
        className={`w-full h-full object-cover transition-opacity duration-300 ${
          isPlaying ? 'opacity-100' : 'opacity-0'
        }`}
      />
      {isPlaying && (
        <button
          onClick={(e) => {
            e.stopPropagation();
            const video = videoRef.current;
            if (video !== null) {
              video.muted = !video.muted;
              setIsMuted(video.muted);
            }
          }}
          className="absolute bottom-2 right-2 p-2 bg-black/60 rounded-full text-white opacity-0 group-hover:opacity-100 transition-opacity"
          aria-label={isMuted ? 'Unmute' : 'Mute'}
        >
          {isMuted ? <VolumeXIcon className="w-4 h-4" /> : <Volume2Icon className="w-4 h-4" />}
        </button>
      )}
    </div>
  );
}
