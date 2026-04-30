const API_URL = import.meta.env.VITE_API_URL ?? "http://localhost:8000/api/v1";
const AUTH_TOKEN_STORAGE_KEY = "film_auth_token";

export interface SessionProfilePayload {
  id: string | number;
  name: string;
  avatar_label?: string | null;
  avatar_color?: string | null;
  is_kids?: boolean;
  is_default?: boolean;
  sort_order?: number;
  favorite_slugs?: string[];
}

export interface SessionUserPayload {
  id: string | number;
  name: string;
  email: string;
  preferred_locale?: "en" | "ro" | "ru" | null;
  avatar_url?: string | null;
  wallet?: {
    id: string | number;
    currency: string;
    balance_amount: number;
  } | null;
  profiles?: SessionProfilePayload[];
}

export interface AuthResponsePayload {
  token: string;
  user: SessionUserPayload;
  redirect_app?: "admin" | "client";
}

export interface RegistrationStartPayload {
  message: string;
  email: string;
  expires_at?: string | null;
}

export interface StorefrontTransactionPayload {
  id: string | number;
  type: string;
  amount: number;
  balance_after: number;
  currency: string;
  description?: string | null;
  meta?: Record<string, unknown>;
  processed_at?: string | null;
}

export interface StorefrontLibraryItemPayload {
  id: string | number;
  content_id: string | number;
  content_slug: string;
  content_title: string;
  content_type: "movie" | "series";
  poster_url?: string | null;
  backdrop_url?: string | null;
  offer_id?: string | number | null;
  offer_name?: string | null;
  access_type: "free" | "rental" | "lifetime";
  quality?: string | null;
  status: "active" | "expired" | "revoked";
  is_active: boolean;
  currency: string;
  price_amount: number;
  granted_at?: string | null;
  expires_at?: string | null;
}

export interface StorefrontAccountPayload {
  user: SessionUserPayload;
  wallet: {
    id: string | number;
    currency: string;
    balance_amount: number;
  };
  transactions: StorefrontTransactionPayload[];
  library: StorefrontLibraryItemPayload[];
  favorites_by_profile: Record<string, string[]>;
}

export interface StorefrontPurchasePayload {
  message: string;
  already_owned: boolean;
  wallet: {
    id: string | number;
    currency: string;
    balance_amount: number;
  };
  transaction?: StorefrontTransactionPayload | null;
  library_item: StorefrontLibraryItemPayload;
}

export interface StorefrontPremiereLockPayload {
  id: number;
  title: string;
  starts_at: string;
  ends_at?: string | null;
}

export interface StorefrontPlaybackPayload {
  content: {
    id: string | number;
    slug: string;
    title: string;
    type: "movie" | "series";
    poster_url?: string | null;
    backdrop_url?: string | null;
  };
  episode?: {
    id: string;
    season_number: number;
    episode_number: number;
    title?: string | null;
    description?: string | null;
    runtime_minutes?: number | null;
    thumbnail_url?: string | null;
    backdrop_url?: string | null;
  } | null;
  playback: {
    url: string;
    embed_url?: string | null;
    quality?: string | null;
    content_format_id?: number | null;
    drm?: {
      policy?: string | null;
      servers?: Record<string, string>;
      headers?: Record<string, string>;
      clear_keys?: Record<string, string>;
    };
    offer_type?: "free" | "rental" | "lifetime" | null;
    expires_at?: string | null;
    is_lifetime: boolean;
    country_code?: string | null;
    session_token?: string | null;
  };
  subtitles?: Array<{
    id: number;
    locale: string;
    label: string;
    url: string;
    is_default: boolean;
  }>;
  continue_watching?: {
    position_seconds: number;
    duration_seconds: number;
    watch_time_seconds: number;
    last_watched_at?: string | null;
  } | null;
  premiere_event?: StorefrontPremiereLockPayload | null;
}

export interface StorefrontPlaybackSessionPayload {
  session: {
    id: number;
    token: string;
    status: string;
    started_at?: string | null;
  };
}

export interface ContinueWatchingItemPayload {
  content_id: string | number;
  content_slug: string;
  title?: string | null;
  poster_url?: string | null;
  position_seconds: number;
  duration_seconds: number;
  progress_percent: number;
  last_watched_at?: string | null;
}

export interface ContinueWatchingResponsePayload {
  items: ContinueWatchingItemPayload[];
}

export interface RecommendationItemPayload {
  id: string | number;
  slug: string;
  title: string;
  poster_url?: string | null;
  backdrop_url?: string | null;
  type: "movie" | "series";
}

export interface RecommendationsResponsePayload {
  items: RecommendationItemPayload[];
}

export interface RequestErrorWithPayload extends Error {
  status?: number;
  payload?: Record<string, unknown>;
}

export interface ProfileMutationPayload {
  profile?: SessionProfilePayload;
  profiles: SessionProfilePayload[];
}

export interface FavoriteMutationPayload {
  favorites_by_profile: Record<string, string[]>;
}

function buildUrl(path: string, query?: Record<string, string | number | undefined>) {
  const params = new URLSearchParams();

  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== "") {
      params.set(key, String(value));
    }
  });

  return `${API_URL}${path}${params.toString() ? `?${params.toString()}` : ""}`;
}

async function parseErrorMessage(response: Response): Promise<string> {
  try {
    const payload = await response.json();

    if (typeof payload?.message === "string" && payload.message.trim() !== "") {
      return payload.message;
    }

    const errors = payload?.errors;
    if (errors && typeof errors === "object") {
      const firstError = Object.values(errors)[0];
      if (Array.isArray(firstError) && typeof firstError[0] === "string") {
        return firstError[0];
      }
    }
  } catch {
    // Ignore parsing failures and fall back to a generic message.
  }

  return "The request could not be completed.";
}

async function requestJson<T>(
  path: string,
  init: RequestInit = {},
  query?: Record<string, string | number | undefined>,
  requireAuth = false,
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");

  if (init.body && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  if (requireAuth) {
    const token = readAuthToken();

    if (!token) {
      throw new Error("Please log in to continue.");
    }

    headers.set("Authorization", `Bearer ${token}`);
  }

  const response = await fetch(buildUrl(path, query), {
    ...init,
    headers,
  });

  if (!response.ok) {
    let payload: Record<string, unknown> | undefined;
    try {
      payload = await response.clone().json();
    } catch {
      payload = undefined;
    }

    const error = new Error(await parseErrorMessage(response)) as RequestErrorWithPayload;
    error.status = response.status;
    error.payload = payload;
    throw error;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}

export function readAuthToken() {
  return localStorage.getItem(AUTH_TOKEN_STORAGE_KEY);
}

export function writeAuthToken(token: string) {
  localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, token);
}

export function clearAuthToken() {
  localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
}

export async function loginWithPassword(email: string, password: string) {
  return requestJson<AuthResponsePayload>("/auth/login", {
    method: "POST",
    body: JSON.stringify({
      email,
      password,
      app: "client",
    }),
  });
}

export async function registerWithPassword(payload: {
  name: string;
  email: string;
  password: string;
  preferredLocale?: "en" | "ro" | "ru";
}) {
  return requestJson<RegistrationStartPayload>("/auth/register", {
    method: "POST",
    body: JSON.stringify({
      name: payload.name,
      email: payload.email,
      password: payload.password,
      password_confirmation: payload.password,
      preferred_locale: payload.preferredLocale,
    }),
  });
}

export async function verifyRegistrationCode(email: string, code: string) {
  return requestJson<AuthResponsePayload>("/auth/register/verify", {
    method: "POST",
    body: JSON.stringify({
      email,
      code,
    }),
  });
}

export async function resendRegistrationCode(email: string) {
  return requestJson<RegistrationStartPayload>("/auth/register/resend", {
    method: "POST",
    body: JSON.stringify({
      email,
    }),
  });
}

export async function fetchCurrentUser() {
  return requestJson<{ user: SessionUserPayload }>("/auth/me", {}, undefined, true);
}

export async function logoutCurrentUser() {
  return requestJson<void>("/auth/logout", {
    method: "POST",
  }, undefined, true);
}

export async function updateAccountSettings(payload: {
  name: string;
  email: string;
  preferredLocale?: "en" | "ro" | "ru";
}) {
  return requestJson<{ user: SessionUserPayload }>("/settings/profile", {
    method: "PUT",
    body: JSON.stringify({
      name: payload.name,
      email: payload.email,
      preferred_locale: payload.preferredLocale,
    }),
  }, undefined, true);
}

export async function updateAccountPassword(payload: {
  currentPassword: string;
  password: string;
}) {
  return requestJson<{ message: string }>("/settings/password", {
    method: "PUT",
    body: JSON.stringify({
      current_password: payload.currentPassword,
      password: payload.password,
      password_confirmation: payload.password,
    }),
  }, undefined, true);
}

export async function fetchStorefrontAccount(locale?: "en" | "ro" | "ru") {
  return requestJson<StorefrontAccountPayload>("/storefront/account", {}, {
    locale,
  }, true);
}

export async function purchaseStorefrontOffer(offerId: string, locale?: "en" | "ro" | "ru") {
  return requestJson<StorefrontPurchasePayload>(`/storefront/offers/${offerId}/purchase`, {
    method: "POST",
  }, {
    locale,
  }, true);
}

export async function fetchStorefrontPlayback(
  identifier: string,
  options?: {
    locale?: "en" | "ro" | "ru";
    episodeId?: string;
  },
) {
  return requestJson<StorefrontPlaybackPayload>(`/storefront/content/${identifier}/playback`, {}, {
    locale: options?.locale,
    episode_id: options?.episodeId,
  }, true);
}

export async function startStorefrontPlaybackSession(
  identifier: string,
  payload: {
    content_format_id?: number | null;
    offer_id?: number | null;
    account_profile_id?: string | number | null;
    country_code?: string | null;
    device_type?: string | null;
  },
) {
  return requestJson<StorefrontPlaybackSessionPayload>(`/storefront/content/${identifier}/playback/session`, {
    method: "POST",
    body: JSON.stringify(payload),
  }, undefined, true);
}

export async function updateStorefrontWatchProgress(payload: {
  session_token: string;
  content_id: string | number;
  content_format_id?: number | null;
  episode_id?: string | null;
  position_seconds?: number;
  duration_seconds?: number;
  watch_time_seconds?: number;
  event_type: string;
  country_code?: string | null;
}) {
  return requestJson<{ session: { id: number; status: string; watch_time_seconds: number; max_position_seconds: number } }>(
    "/storefront/tracking/watch-progress",
    {
      method: "POST",
      body: JSON.stringify(payload),
    },
    undefined,
    true,
  );
}

export async function fetchContinueWatching(locale?: "en" | "ro" | "ru") {
  return requestJson<ContinueWatchingResponsePayload>("/storefront/continue-watching", {}, {
    locale,
  }, true);
}

export async function fetchStorefrontRecommendations(
  identifier: string,
  locale?: "en" | "ro" | "ru",
) {
  return requestJson<RecommendationsResponsePayload>(`/storefront/content/${identifier}/recommendations`, {}, {
    locale,
  }, true);
}

export async function createStorefrontProfile(payload: {
  name: string;
  avatarColor?: string;
  avatarLabel?: string;
  isKids?: boolean;
}) {
  return requestJson<ProfileMutationPayload>("/storefront/profiles", {
    method: "POST",
    body: JSON.stringify({
      name: payload.name,
      avatar_color: payload.avatarColor,
      avatar_label: payload.avatarLabel,
      is_kids: payload.isKids,
    }),
  }, undefined, true);
}

export async function updateStorefrontProfile(
  profileId: string,
  payload: {
    name: string;
    avatarColor?: string;
    avatarLabel?: string;
    isKids?: boolean;
  },
) {
  return requestJson<ProfileMutationPayload>(`/storefront/profiles/${profileId}`, {
    method: "PATCH",
    body: JSON.stringify({
      name: payload.name,
      avatar_color: payload.avatarColor,
      avatar_label: payload.avatarLabel,
      is_kids: payload.isKids,
    }),
  }, undefined, true);
}

export async function deleteStorefrontProfile(profileId: string) {
  return requestJson<ProfileMutationPayload>(`/storefront/profiles/${profileId}`, {
    method: "DELETE",
  }, undefined, true);
}

export async function favoriteStorefrontContent(profileId: string, identifier: string) {
  return requestJson<FavoriteMutationPayload>(`/storefront/profiles/${profileId}/favorites/${identifier}`, {
    method: "PUT",
  }, undefined, true);
}

export async function unfavoriteStorefrontContent(profileId: string, identifier: string) {
  return requestJson<FavoriteMutationPayload>(`/storefront/profiles/${profileId}/favorites/${identifier}`, {
    method: "DELETE",
  }, undefined, true);
}
