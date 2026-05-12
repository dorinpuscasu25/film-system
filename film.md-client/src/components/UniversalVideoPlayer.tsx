import { AlertTriangleIcon } from "lucide-react";
import { isDirectMediaUrl, resolveEmbedUrl } from "../lib/videoEmbeds";

interface UniversalVideoPlayerProps {
  sourceUrl?: string | null;
  posterUrl?: string;
  title: string;
  className?: string;
  autoPlay?: boolean;
}

export function UniversalVideoPlayer({
  sourceUrl,
  posterUrl,
  title,
  className = "",
  autoPlay = true,
}: UniversalVideoPlayerProps) {
  const trimmedUrl = sourceUrl?.trim() ?? "";
  const embedUrl = trimmedUrl ? resolveEmbedUrl(trimmedUrl, null, autoPlay) : null;

  if (!trimmedUrl) {
    return (
      <div className={`flex h-full w-full items-center justify-center bg-black px-6 text-center text-white ${className}`}>
        <div>
          <AlertTriangleIcon className="mx-auto mb-3 h-8 w-8 text-amber-300" />
          <p className="text-sm text-white/70">Nu există URL video pentru acest material.</p>
        </div>
      </div>
    );
  }

  if (embedUrl && !isDirectMediaUrl(trimmedUrl)) {
    return (
      <iframe
        title={title}
        src={embedUrl}
        className={`h-full w-full border-0 ${className}`}
        allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen"
        allowFullScreen
      />
    );
  }

  return (
    <video
      className={`h-full w-full bg-black object-contain ${className}`}
      src={trimmedUrl}
      poster={posterUrl}
      controls
      autoPlay={autoPlay}
      playsInline
    />
  );
}
