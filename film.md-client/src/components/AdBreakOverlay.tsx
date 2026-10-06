import { useCallback, useEffect, useRef, useState } from "react";
import { useLanguage } from "../contexts/LanguageContext";
import {
  AdBreak,
  AdTrackingEvent,
  fireAdTracking,
  quartilesCrossed,
} from "../lib/ads";

interface AdBreakOverlayProps {
  /** The break to play. The overlay unmounts itself once finished. */
  adBreak: AdBreak;
  /** Called when the ad ends, is skipped, or fails — playback must resume. */
  onFinished: () => void;
}

/**
 * Plays one linear ad over the content player and reports VAST events.
 *
 * Rendered only while a break is active; the content <video> stays mounted
 * underneath and paused, so resuming does not re-buffer the film.
 *
 * Any failure (bad media URL, decode error, blocked autoplay) resolves the
 * break immediately rather than trapping the viewer in front of an ad that will
 * not play — a broken advert must never cost us a paying customer.
 */
export function AdBreakOverlay({ adBreak, onFinished }: AdBreakOverlayProps) {
  const { t } = useLanguage();
  const videoRef = useRef<HTMLVideoElement>(null);
  const firedRef = useRef<Set<AdTrackingEvent>>(new Set());
  const finishedRef = useRef(false);

  const [remaining, setRemaining] = useState<number>(adBreak.creative.durationSeconds);
  const [skipIn, setSkipIn] = useState<number | null>(adBreak.creative.skipOffsetSeconds);
  const [isMuted, setIsMuted] = useState(false);

  const { creative } = adBreak;

  // Guarded so the parent is told exactly once per break.
  const finish = useCallback(
    (event?: AdTrackingEvent) => {
      if (finishedRef.current) return;
      finishedRef.current = true;
      if (event) fireAdTracking(creative, event);
      onFinished();
    },
    [creative, onFinished],
  );

  useEffect(() => {
    fireAdTracking(creative, "impression");
  }, [creative]);

  const handleTimeUpdate = useCallback(() => {
    const video = videoRef.current;
    if (!video) return;

    const duration = Number.isFinite(video.duration) && video.duration > 0
      ? video.duration
      : creative.durationSeconds;

    for (const event of quartilesCrossed(video.currentTime, duration, firedRef.current)) {
      fireAdTracking(creative, event);
    }

    setRemaining(Math.max(0, Math.ceil(duration - video.currentTime)));

    if (creative.skipOffsetSeconds !== null) {
      setSkipIn(Math.max(0, Math.ceil(creative.skipOffsetSeconds - video.currentTime)));
    }
  }, [creative]);

  const handleClick = useCallback(() => {
    if (!creative.clickThroughUrl) return;
    fireAdTracking(creative, "click");
    window.open(creative.clickThroughUrl, "_blank", "noopener,noreferrer");
  }, [creative]);

  const toggleMute = useCallback(() => {
    const video = videoRef.current;
    if (!video) return;
    video.muted = !video.muted;
    setIsMuted(video.muted);
    fireAdTracking(creative, video.muted ? "mute" : "unmute");
  }, [creative]);

  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;

    // Browsers refuse unmuted autoplay without a recent user gesture, which is
    // exactly the case on a page reload. Muted autoplay is always permitted, so
    // fall back to it instead of dropping the break — the viewer can turn sound
    // on with the control in the corner. Only a second failure ends the break.
    void video.play().catch(() => {
      video.muted = true;
      setIsMuted(true);
      void video.play().catch(() => finish());
    });
  }, [finish]);

  const canSkip = creative.skipOffsetSeconds !== null && (skipIn ?? 1) <= 0;

  return (
    <div className="absolute inset-0 z-30 flex items-center justify-center bg-black">
      <video
        ref={videoRef}
        src={creative.mediaUrl}
        className="h-full w-full object-contain"
        playsInline
        autoPlay
        onTimeUpdate={handleTimeUpdate}
        onEnded={() => finish("complete")}
        onError={() => finish()}
        onClick={handleClick}
        style={{ cursor: creative.clickThroughUrl ? "pointer" : "default" }}
      />

      <div className="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between p-4">
        <span className="pointer-events-auto rounded-md bg-black/70 px-3 py-1.5 text-xs font-medium text-white">
          {t("ads.label")}
          {remaining > 0 ? ` · ${remaining}s` : ""}
        </span>

        <button
          type="button"
          onClick={toggleMute}
          className="pointer-events-auto rounded-md bg-black/70 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-black/90"
        >
          {isMuted ? t("ads.unmute") : t("ads.mute")}
        </button>
      </div>

      <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between p-4">
        {creative.clickThroughUrl ? (
          <button
            type="button"
            onClick={handleClick}
            className="pointer-events-auto rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700"
          >
            {t("ads.learn_more")}
          </button>
        ) : (
          <span />
        )}

        {creative.skipOffsetSeconds !== null && (
          <button
            type="button"
            disabled={!canSkip}
            onClick={() => finish("skip")}
            className="pointer-events-auto rounded-md bg-black/75 px-4 py-2 text-sm font-medium text-white transition-colors enabled:hover:bg-black/90 disabled:cursor-default disabled:opacity-60"
          >
            {canSkip ? t("ads.skip") : t("ads.skip_in", { seconds: skipIn ?? 0 })}
          </button>
        )}
      </div>
    </div>
  );
}
