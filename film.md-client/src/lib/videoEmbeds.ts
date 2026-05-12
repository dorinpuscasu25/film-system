export function isDirectMediaUrl(url: string) {
  return /\.(m3u8|mpd|mp4|webm|ogg)(\?.*)?$/i.test(url);
}

export function isBunnyApiAssetUrl(url: string) {
  return /video\.bunnycdn\.com\/[^/?#]+\/[^/?#]+/i.test(url) && !isDirectMediaUrl(url);
}

export function inferBunnyEmbedUrl(url: string) {
  if (url.includes("iframe.mediadelivery.net/embed/")) {
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

export function inferYouTubeEmbedUrl(url: string, autoplay = true) {
  try {
    const parsed = new URL(url);
    const host = parsed.hostname.replace(/^www\./, "").toLowerCase();
    let id: string | null = null;

    if (host === "youtu.be") {
      id = parsed.pathname.split("/").filter(Boolean)[0] ?? null;
    }

    if (host === "youtube.com" || host === "m.youtube.com" || host === "youtube-nocookie.com") {
      if (parsed.pathname === "/watch") {
        id = parsed.searchParams.get("v");
      } else {
        const parts = parsed.pathname.split("/").filter(Boolean);
        if (["embed", "shorts", "live"].includes(parts[0])) {
          id = parts[1] ?? null;
        }
      }
    }

    if (!id) {
      return null;
    }

    const params = new URLSearchParams({
      rel: "0",
      modestbranding: "1",
      playsinline: "1",
    });

    if (autoplay) {
      params.set("autoplay", "1");
    }

    return `https://www.youtube.com/embed/${encodeURIComponent(id)}?${params.toString()}`;
  } catch {
    return null;
  }
}

export function resolveEmbedUrl(sourceUrl: string, explicitEmbedUrl?: string | null, autoplay = true) {
  return inferYouTubeEmbedUrl(sourceUrl, autoplay) ?? explicitEmbedUrl ?? inferBunnyEmbedUrl(sourceUrl);
}
