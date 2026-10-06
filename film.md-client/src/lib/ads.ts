/**
 * Client-side VAST/VMAP playback support.
 *
 * The films are delivered by Bunny, but we render them in our own <video>
 * element (hls.js / Shaka) rather than Bunny's iframe whenever we have a direct
 * media URL. That means ad breaks have to be scheduled and tracked here instead
 * of by Bunny's player, which only applies to the iframe path.
 *
 * Everything here is deliberately dependency-free: VAST is a small, stable XML
 * shape and pulling in an IMA SDK would add a cross-origin script, a consent
 * surface and ~200 KB for what amounts to a handful of selectors.
 */

// Same variable and fallback as session.ts / storefront.ts. A relative path here
// would resolve against the storefront host instead of the API host, and the
// request would 404 silently.
const API_URL = import.meta.env.VITE_API_URL ?? "https://filmmd-api.veezify.com/api/v1";

export type AdPlacement = "pre-roll" | "mid-roll" | "post-roll";

export type AdTrackingEvent =
  | "impression"
  | "start"
  | "firstQuartile"
  | "midpoint"
  | "thirdQuartile"
  | "complete"
  | "pause"
  | "resume"
  | "mute"
  | "unmute"
  | "skip"
  | "close"
  | "click";

export interface AdCreative {
  /** Media file the player should load. */
  mediaUrl: string;
  mimeType: string | null;
  /** Creative length in seconds; 0 when the VAST omits a usable duration. */
  durationSeconds: number;
  /** Seconds before a skip button may appear; null means unskippable. */
  skipOffsetSeconds: number | null;
  clickThroughUrl: string | null;
  adTitle: string | null;
  /** event name -> tracking URLs to ping. */
  tracking: Partial<Record<AdTrackingEvent, string[]>>;
}

export interface AdBreak {
  placement: AdPlacement;
  /** Seconds into the content; 0 for pre-roll, Infinity for post-roll. */
  timeOffsetSeconds: number;
  creative: AdCreative;
  /** Flipped once the break has been shown so it never repeats. */
  played: boolean;
}

/** Parses `HH:MM:SS(.mmm)` into seconds. */
function parseVastTime(value: string | null | undefined): number | null {
  if (!value) return null;
  const match = value.trim().match(/^(\d{1,3}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/);
  if (!match) {
    // VAST also allows a bare percentage for skipoffset (e.g. "25%").
    const percent = value.trim().match(/^(\d{1,3})%$/);
    return percent ? Number(percent[1]) / 100 : null;
  }
  const [, h, m, s, ms] = match;
  return Number(h) * 3600 + Number(m) * 60 + Number(s) + (ms ? Number(ms) / 1000 : 0);
}

function textOf(parent: Element | Document, selector: string): string | null {
  const node = parent.querySelector(selector);
  const value = node?.textContent?.trim();
  return value ? value : null;
}

/**
 * Extracts the single linear creative from a VAST document.
 * Returns null when the document carries no playable media file.
 */
export function parseVast(xml: Document | Element): AdCreative | null {
  const linear = xml.querySelector("Linear");
  if (!linear) return null;

  // Prefer progressive MP4; HLS inside an ad slot buys us nothing here.
  const mediaFiles = Array.from(linear.querySelectorAll("MediaFile"));
  const chosen =
    mediaFiles.find((node) => (node.getAttribute("type") ?? "").includes("mp4")) ?? mediaFiles[0];
  const mediaUrl = chosen?.textContent?.trim();
  if (!mediaUrl) return null;

  const tracking: Partial<Record<AdTrackingEvent, string[]>> = {};
  const push = (event: AdTrackingEvent, url: string | null | undefined) => {
    const trimmed = url?.trim();
    if (!trimmed) return;
    (tracking[event] ??= []).push(trimmed);
  };

  xml.querySelectorAll("Impression").forEach((node) => push("impression", node.textContent));
  linear.querySelectorAll("TrackingEvents > Tracking").forEach((node) => {
    const event = node.getAttribute("event") as AdTrackingEvent | null;
    if (event) push(event, node.textContent);
  });
  linear.querySelectorAll("VideoClicks > ClickTracking").forEach((node) => push("click", node.textContent));

  return {
    mediaUrl,
    mimeType: chosen?.getAttribute("type") ?? null,
    durationSeconds: parseVastTime(textOf(linear, "Duration")) ?? 0,
    skipOffsetSeconds: parseVastTime(linear.getAttribute("skipoffset")),
    clickThroughUrl: textOf(linear, "VideoClicks > ClickThrough"),
    adTitle: textOf(xml, "AdTitle"),
    tracking,
  };
}

/** Maps a VMAP `timeOffset` onto seconds within the content. */
function parseBreakOffset(raw: string | null, placement: AdPlacement): number {
  if (placement === "pre-roll" || raw === "start") return 0;
  if (placement === "post-roll" || raw === "end") return Number.POSITIVE_INFINITY;
  return parseVastTime(raw) ?? 0;
}

export function parseVmap(xmlText: string): AdBreak[] {
  const doc = new DOMParser().parseFromString(xmlText, "application/xml");
  if (doc.querySelector("parsererror")) return [];

  const breaks: AdBreak[] = [];
  // getElementsByTagNameNS avoids depending on the `vmap:` prefix spelling.
  const nodes = Array.from(doc.getElementsByTagName("*")).filter(
    (node) => node.localName === "AdBreak",
  );

  for (const node of nodes) {
    const placement = (node.getAttribute("breakId") ?? "pre-roll") as AdPlacement;
    const vastRoot = Array.from(node.getElementsByTagName("*")).find(
      (child) => child.localName === "VAST",
    );
    if (!vastRoot) continue;

    const creative = parseVast(vastRoot);
    if (!creative) continue;

    breaks.push({
      placement,
      timeOffsetSeconds: parseBreakOffset(node.getAttribute("timeOffset"), placement),
      creative,
      played: false,
    });
  }

  return breaks;
}

export interface FetchAdBreaksOptions {
  contentId: number | string;
  sessionToken?: string | null;
  accountProfileId?: string | number | null;
  platform?: string;
  group?: string;
  signal?: AbortSignal;
}

/**
 * Asks the API for every break in one request.
 *
 * Returns an empty list on any failure — a broken ad server must never stop a
 * paying customer from watching the film they bought.
 */
export async function fetchAdBreaks(options: FetchAdBreaksOptions): Promise<AdBreak[]> {
  const params = new URLSearchParams({
    content: String(options.contentId),
    platform: options.platform ?? "web",
    group: options.group ?? "movies",
  });
  if (options.sessionToken) params.set("session", options.sessionToken);
  if (options.accountProfileId) params.set("account_profile_id", String(options.accountProfileId));

  try {
    const response = await fetch(`${API_URL}/playback/breaks?${params.toString()}`, {
      signal: options.signal,
      headers: { Accept: "application/xml" },
    });
    if (response.status === 204) return [];
    if (!response.ok) {
      // Never block playback, but do not fail silently either: a misrouted or
      // rejected request here looks exactly like "no campaigns are running".
      console.warn(`[ads] break playlist request failed with ${response.status}`);
      return [];
    }
    return parseVmap(await response.text());
  } catch (error) {
    if ((error as Error)?.name !== "AbortError") {
      console.warn("[ads] break playlist request could not be completed", error);
    }
    return [];
  }
}

/**
 * Fires tracking beacons for one event.
 *
 * Uses `sendBeacon` where available so a `complete` or `skip` ping still lands
 * when the viewer closes the tab the moment the ad ends.
 */
export function fireAdTracking(creative: AdCreative, event: AdTrackingEvent): void {
  const urls = creative.tracking[event];
  if (!urls?.length) return;

  for (const url of urls) {
    try {
      if (typeof navigator !== "undefined" && typeof navigator.sendBeacon === "function") {
        navigator.sendBeacon(url);
      } else {
        void fetch(url, { method: "GET", mode: "no-cors", keepalive: true });
      }
    } catch {
      // Tracking is best-effort; never surface it to the viewer.
    }
  }
}

/**
 * Quartile beacons that have to fire exactly once per playback of a creative.
 * Returns the events newly crossed at `currentTime`.
 */
export function quartilesCrossed(
  currentTime: number,
  duration: number,
  alreadyFired: Set<AdTrackingEvent>,
): AdTrackingEvent[] {
  if (!Number.isFinite(duration) || duration <= 0) return [];

  const thresholds: Array<[AdTrackingEvent, number]> = [
    ["start", 0],
    ["firstQuartile", 0.25],
    ["midpoint", 0.5],
    ["thirdQuartile", 0.75],
  ];

  const progress = currentTime / duration;
  const crossed: AdTrackingEvent[] = [];

  for (const [event, threshold] of thresholds) {
    if (progress >= threshold && !alreadyFired.has(event)) {
      alreadyFired.add(event);
      crossed.push(event);
    }
  }

  return crossed;
}
