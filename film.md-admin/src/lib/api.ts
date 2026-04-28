import {
  AdminInvitation,
  AdminAdCampaign,
  AuditLogsResponse,
  AdminContent,
  AdminContentFilters,
  AdminContentOptions,
  AdminUserContentOption,
  AdminOffer,
  AdminPermission,
  AdminRole,
  AdminTaxonomy,
  AdminUser,
  AdCampaignPayload,
  AdCampaignsResponse,
  ContentFinancialsResponse,
  ContentPayload,
  FinancialSummaryResponse,
  CostSettingsPayload,
  CostSettingsResponse,
  DashboardResponse,
  ExportJobPayload,
  ExportJobsResponse,
  HomeCurationResponse,
  HomeCurationSection,
  OfferIndexResponse,
  OfferPayload,
  PlaybackOpsResponse,
  TaxonomyIndexResponse,
  TaxonomyPayload,
} from "../types";

const configuredApiUrl = import.meta.env.VITE_API_URL;
const API_URL =
  import.meta.env.DEV && configuredApiUrl?.startsWith("http://localhost:8000/api/")
    ? "/api/v1"
    : configuredApiUrl ?? "http://localhost:8000/api/v1";

let getAccessToken: (() => string | null) | null = null;

export function setAccessTokenGetter(getter: () => string | null) {
  getAccessToken = getter;
}

interface RequestOptions {
  data?: unknown;
  withAuth?: boolean;
  headers?: Record<string, string>;
}

export interface ApiRequestError extends Error {
  status?: number;
  errors?: Record<string, string[]>;
}

async function request<T>(
  method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE",
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const { data, withAuth = true, headers = {} } = options;
  const requestHeaders = new Headers(headers);
  requestHeaders.set("Accept", "application/json");

  if (!(data instanceof FormData) && data !== undefined) {
    requestHeaders.set("Content-Type", "application/json");
  }

  if (withAuth && getAccessToken) {
    const token = getAccessToken();
    if (token) {
      requestHeaders.set("Authorization", `Bearer ${token}`);
    }
  }

  const response = await fetch(`${API_URL}${path}`, {
    method,
    headers: requestHeaders,
    body:
      data instanceof FormData
        ? data
        : data !== undefined
          ? JSON.stringify(data)
          : undefined,
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    const error = new Error(payload.message ?? "Request failed.") as ApiRequestError;
    error.status = response.status;
    error.errors = payload.errors;
    throw error;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}

export const adminApi = {
  login(email: string, password: string) {
    return request<{ token: string; user: AdminUser }>("POST", "/auth/login", {
      withAuth: false,
      data: { email, password, app: "admin" },
    });
  },

  forgotPassword(email: string) {
    return request<{ message: string }>("POST", "/auth/forgot-password", {
      withAuth: false,
      data: { email },
    });
  },

  me() {
    return request<{ user: AdminUser }>("GET", "/auth/me");
  },

  logout() {
    return request<void>("POST", "/auth/logout");
  },

  getDashboard(range?: "7days" | "30days" | "3months") {
    const query = range ? `?range=${range}` : "";
    return request<DashboardResponse>("GET", `/admin/dashboard${query}`);
  },

  getCostSettings() {
    return request<CostSettingsResponse>("GET", "/admin/cost-settings");
  },

  saveCostSettings(payload: CostSettingsPayload) {
    return request<{ version: unknown }>("POST", "/admin/cost-settings", {
      data: payload,
    });
  },

  getExports() {
    return request<ExportJobsResponse>("GET", "/admin/exports");
  },

  createExportJob(payload: ExportJobPayload) {
    return request<{ job: unknown }>("POST", "/admin/exports", {
      data: payload,
    });
  },

  async downloadExportJob(jobId: number, fallbackFileName?: string | null) {
    const headers = new Headers({
      Accept: "application/octet-stream",
    });

    if (getAccessToken) {
      const token = getAccessToken();
      if (token) {
        headers.set("Authorization", `Bearer ${token}`);
      }
    }

    const response = await fetch(`${API_URL}/admin/exports/${jobId}/download`, {
      method: "GET",
      headers,
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      throw new Error(payload.message ?? "Export download failed.");
    }

    const blob = await response.blob();
    const contentDisposition = response.headers.get("Content-Disposition");
    const fileNameMatch = contentDisposition?.match(/filename="?([^"]+)"?/i);
    const fileName = fileNameMatch?.[1] ?? fallbackFileName ?? `export-${jobId}`;
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },

  getPlaybackOps() {
    return request<PlaybackOpsResponse>("GET", "/admin/playback/sessions");
  },

  getAuditLogs(filters?: { action?: string; entity_type?: string; user_id?: number }) {
    const searchParams = new URLSearchParams();
    if (filters?.action) {
      searchParams.set("action", filters.action);
    }
    if (filters?.entity_type) {
      searchParams.set("entity_type", filters.entity_type);
    }
    if (filters?.user_id) {
      searchParams.set("user_id", String(filters.user_id));
    }

    const query = searchParams.toString();
    return request<AuditLogsResponse>("GET", `/admin/audit-logs${query ? `?${query}` : ""}`);
  },

  revokePlaybackSession(sessionId: number) {
    return request<void>("POST", `/admin/playback/sessions/${sessionId}/revoke`);
  },

  getAdCampaigns() {
    return request<AdCampaignsResponse>("GET", "/admin/ad-campaigns");
  },

  createAdCampaign(payload: AdCampaignPayload) {
    return request<{ campaign: AdminAdCampaign }>("POST", "/admin/ad-campaigns", {
      data: payload,
    });
  },

  updateAdCampaign(campaignId: number, payload: AdCampaignPayload) {
    return request<{ campaign: AdminAdCampaign }>("PATCH", `/admin/ad-campaigns/${campaignId}`, {
      data: payload,
    });
  },

  deleteAdCampaign(campaignId: number) {
    return request<void>("DELETE", `/admin/ad-campaigns/${campaignId}`);
  },

  getHomeCuration() {
    return request<HomeCurationResponse>("GET", "/admin/home-curation");
  },

  updateHomeCuration(payload: { sections: HomeCurationSection[] }) {
    return request<{ sections: HomeCurationSection[] }>("PUT", "/admin/home-curation", {
      data: payload,
    });
  },

  getContentIndex() {
    return request<{ items: AdminContent[]; filters: AdminContentFilters }>("GET", "/admin/content");
  },

  getContentOptions() {
    return request<{ options: AdminContentOptions }>("GET", "/admin/content/options");
  },

  getContent(contentId: number) {
    return request<{ content: AdminContent; options: AdminContentOptions }>(
      "GET",
      `/admin/content/${contentId}`,
    );
  },

  createContent(payload: ContentPayload) {
    return request<{ content: AdminContent }>("POST", "/admin/content", {
      data: payload,
    });
  },

  updateContent(contentId: number, payload: ContentPayload) {
    return request<{ content: AdminContent }>("PATCH", `/admin/content/${contentId}`, {
      data: payload,
    });
  },

  deleteContent(contentId: number) {
    return request<void>("DELETE", `/admin/content/${contentId}`);
  },

  getContentFinancials(contentId: number, months = 12) {
    return request<ContentFinancialsResponse>(
      "GET",
      `/admin/content/${contentId}/financials?months=${months}`,
    );
  },

  getFinancialSummary() {
    return request<FinancialSummaryResponse>("GET", "/admin/financial-summary");
  },

  uploadFile(file: File, directory?: string) {
    const formData = new FormData();
    formData.append("file", file);
    if (directory) {
      formData.append("directory", directory);
    }
    return request<{ url: string }>("POST", "/admin/upload", {
      data: formData,
    });
  },

  uploadFiles(files: File[], directory?: string) {
    const formData = new FormData();
    for (const file of files) {
      formData.append("files[]", file);
    }
    if (directory) {
      formData.append("directory", directory);
    }
    return request<{ urls: string[]; errors: Array<{ index: number; name: string; error: string }> }>(
      "POST",
      "/admin/upload",
      { data: formData },
    );
  },

  deleteUpload(url: string) {
    return request<void>("DELETE", "/admin/upload", {
      data: { url },
    });
  },

  getOffers() {
    return request<OfferIndexResponse>("GET", "/admin/offers");
  },

  createOffer(payload: OfferPayload) {
    return request<{ offer: AdminOffer }>("POST", "/admin/offers", {
      data: payload,
    });
  },

  updateOffer(offerId: number, payload: OfferPayload) {
    return request<{ offer: AdminOffer }>("PATCH", `/admin/offers/${offerId}`, {
      data: payload,
    });
  },

  deleteOffer(offerId: number) {
    return request<void>("DELETE", `/admin/offers/${offerId}`);
  },

  getUsers() {
    return request<{ users: AdminUser[]; invitations: AdminInvitation[]; content_options: AdminUserContentOption[] }>(
      "GET",
      "/admin/users",
    );
  },

  getRoles() {
    return request<{ roles: AdminRole[]; permissions: AdminPermission[] }>(
      "GET",
      "/admin/roles",
    );
  },

  getTaxonomies() {
    return request<TaxonomyIndexResponse>("GET", "/admin/taxonomies");
  },

  createTaxonomy(payload: TaxonomyPayload) {
    return request<{ taxonomy: AdminTaxonomy }>("POST", "/admin/taxonomies", {
      data: payload,
    });
  },

  updateTaxonomy(taxonomyId: number, payload: TaxonomyPayload) {
    return request<{ taxonomy: AdminTaxonomy }>(
      "PATCH",
      `/admin/taxonomies/${taxonomyId}`,
      {
        data: payload,
      },
    );
  },

  deleteTaxonomy(taxonomyId: number) {
    return request<void>("DELETE", `/admin/taxonomies/${taxonomyId}`);
  },

  inviteUser(payload: {
    email: string;
    name?: string;
    role_ids: number[];
    expires_in_hours?: number;
  }) {
    return request<{ invitation: AdminInvitation; accept_url: string }>(
      "POST",
      "/admin/users/invite",
      { data: payload },
    );
  },

  updateUser(
    userId: number,
    payload: {
      name: string;
      email: string;
      status: "active" | "suspended";
      role_ids: number[];
      assigned_content_ids?: number[];
      preferred_locale?: "en" | "ro" | "ru";
    },
  ) {
    return request<{ user: AdminUser }>("PATCH", `/admin/users/${userId}`, {
      data: payload,
    });
  },

  createRole(payload: {
    name: string;
    description?: string;
    admin_panel_access: boolean;
    permission_ids: number[];
  }) {
    return request<{ role: AdminRole }>("POST", "/admin/roles", { data: payload });
  },

  updateRole(
    roleId: number,
    payload: {
      name: string;
      description?: string;
      admin_panel_access: boolean;
      permission_ids: number[];
    },
  ) {
    return request<{ role: AdminRole }>("PATCH", `/admin/roles/${roleId}`, {
      data: payload,
    });
  },

  updateProfile(payload: {
    name: string;
    email: string;
    preferred_locale?: "en" | "ro" | "ru";
    avatar_url?: string;
  }) {
    return request<{ user: AdminUser }>("PUT", "/settings/profile", {
      data: payload,
    });
  },

  updatePassword(payload: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }) {
    return request<{ message: string }>("PUT", "/settings/password", {
      data: payload,
    });
  },

  // === Coupons ===
  getCoupons(query?: { q?: string }) {
    const qs = query?.q ? `?q=${encodeURIComponent(query.q)}` : "";
    return request<{
      items: Array<{
        id: number;
        code: string;
        name: string;
        description: string | null;
        discount_type: "percent" | "fixed" | "free_access";
        discount_value: number;
        currency: string;
        max_redemptions: number | null;
        redemptions_count: number;
        per_user_limit: number;
        starts_at: string | null;
        ends_at: string | null;
        is_active: boolean;
        is_currently_valid: boolean;
        applicable_content_ids: number[] | null;
        applicable_offer_ids: number[] | null;
        created_at: string | null;
      }>;
      pagination: { page: number; per_page: number; total: number };
    }>("GET", `/admin/coupons${qs}`);
  },
  createCoupon(payload: Record<string, unknown>) {
    return request<{ coupon: unknown }>("POST", "/admin/coupons", { data: payload });
  },
  updateCoupon(id: number, payload: Record<string, unknown>) {
    return request<{ coupon: unknown }>("PATCH", `/admin/coupons/${id}`, { data: payload });
  },
  deleteCoupon(id: number) {
    return request<void>("DELETE", `/admin/coupons/${id}`);
  },

  // === Content creators ===
  getContentCreators() {
    return request<{
      items: Array<{
        id: number;
        name: string;
        email: string | null;
        company_name: string | null;
        platform_fee_percent: number;
        is_active: boolean;
        user: { id: number; name: string; email: string } | null;
        content_count: number;
        contents: Array<{ id: number; title: string }>;
      }>;
    }>("GET", "/admin/content-creators");
  },
  createContentCreator(payload: Record<string, unknown>) {
    return request<{ creator: unknown }>("POST", "/admin/content-creators", { data: payload });
  },
  updateContentCreator(id: number, payload: Record<string, unknown>) {
    return request<{ creator: unknown }>("PATCH", `/admin/content-creators/${id}`, { data: payload });
  },
  deleteContentCreator(id: number) {
    return request<void>("DELETE", `/admin/content-creators/${id}`);
  },
  getCreatorStatements(creatorId: number) {
    return request<{
      creator_id: number;
      items: Array<{
        id: number;
        month: string;
        revenue_usd: number;
        costs_usd: number;
        payout_usd: number;
        profit_usd: number;
        is_locked: boolean;
      }>;
    }>("GET", `/admin/content-creators/${creatorId}/statements`);
  },

  // === Watch parties ===
  getWatchParties() {
    return request<{
      items: Array<{
        id: number;
        content_id: number;
        content_title: string | null;
        title: string;
        room_code: string;
        scheduled_start_at: string | null;
        actual_start_at: string | null;
        ended_at: string | null;
        status: string;
        is_public: boolean;
        chat_enabled: boolean;
        max_participants: number | null;
        created_at: string | null;
      }>;
    }>("GET", "/admin/watch-parties");
  },
  createWatchParty(payload: {
    content_id: number;
    title: string;
    scheduled_start_at: string;
    is_public?: boolean;
    chat_enabled?: boolean;
    max_participants?: number;
  }) {
    return request<{ party: unknown }>("POST", "/admin/watch-parties", { data: payload });
  },
  startWatchParty(id: number) {
    return request<{ party: unknown }>("POST", `/admin/watch-parties/${id}/start`);
  },
  endWatchParty(id: number) {
    return request<{ party: unknown }>("POST", `/admin/watch-parties/${id}/end`);
  },
  deleteWatchParty(id: number) {
    return request<void>("DELETE", `/admin/watch-parties/${id}`);
  },

  // === Ad campaign stats ===
  getAdCampaignStats(campaignId: number, days = 30) {
    return request<{
      campaign: {
        id: number;
        name: string;
        company_name: string | null;
        placement: string;
        status: string;
        bid_amount: number;
        click_through_url: string | null;
        is_active: boolean;
        starts_at: string | null;
        ends_at: string | null;
        rollups: {
          impressions: number;
          completes: number;
          clicks: number;
          skips: number;
          ctr: number;
          completion_rate: number;
        };
      };
      events_chart: Array<{ event: string; count: number }>;
      country_chart: Array<{ country: string; count: number; percent: number }>;
      daily_chart: Array<{
        date: string;
        impressions: number;
        completes: number;
        clicks: number;
        skips: number;
      }>;
    }>("GET", `/admin/ad-campaigns/${campaignId}/stats?days=${days}`);
  },
  getAdCampaignEvents(campaignId: number, params?: { event_type?: string; country_code?: string; per_page?: number }) {
    const qs = new URLSearchParams();
    if (params?.event_type) qs.set("event_type", params.event_type);
    if (params?.country_code) qs.set("country_code", params.country_code);
    if (params?.per_page) qs.set("per_page", String(params.per_page));
    const suffix = qs.toString() ? `?${qs.toString()}` : "";
    return request<{
      items: Array<{
        id: number;
        event_type: string;
        country_code: string | null;
        occurred_at: string | null;
        ip_address: string | null;
        playback_session_id: string | null;
        content_id: number | null;
      }>;
      pagination: { page: number; per_page: number; total: number };
    }>("GET", `/admin/ad-campaigns/${campaignId}/events${suffix}`);
  },

  // === Subtitles per content ===
  getSubtitles(contentId: number) {
    return request<{
      content_id: number;
      items: Array<{
        id: number;
        locale: string;
        label: string;
        file_url: string;
        is_default: boolean;
        sort_order: number;
        content_format_id: number | null;
      }>;
    }>("GET", `/admin/content/${contentId}/subtitles`);
  },
  createSubtitle(contentId: number, payload: Record<string, unknown>) {
    return request<{ track: unknown }>("POST", `/admin/content/${contentId}/subtitles`, { data: payload });
  },
  updateSubtitle(contentId: number, trackId: number, payload: Record<string, unknown>) {
    return request<{ track: unknown }>("PATCH", `/admin/content/${contentId}/subtitles/${trackId}`, { data: payload });
  },
  deleteSubtitle(contentId: number, trackId: number) {
    return request<void>("DELETE", `/admin/content/${contentId}/subtitles/${trackId}`);
  },

  // === Availability windows per content ===
  getAvailabilityWindows(contentId: number) {
    return request<{
      content_id: number;
      items: Array<{
        id: number;
        content_format_id: number | null;
        country_code: string | null;
        is_allowed: boolean;
        starts_at: string | null;
        ends_at: string | null;
      }>;
    }>("GET", `/admin/content/${contentId}/availability`);
  },
  createAvailabilityWindow(contentId: number, payload: Record<string, unknown>) {
    return request<{ window: unknown }>("POST", `/admin/content/${contentId}/availability`, { data: payload });
  },
  updateAvailabilityWindow(contentId: number, windowId: number, payload: Record<string, unknown>) {
    return request<{ window: unknown }>("PATCH", `/admin/content/${contentId}/availability/${windowId}`, { data: payload });
  },
  deleteAvailabilityWindow(contentId: number, windowId: number) {
    return request<void>("DELETE", `/admin/content/${contentId}/availability/${windowId}`);
  },

  // === Geo distribution ===
  getGeoStats(days = 30) {
    return request<{
      days: number;
      totals: { total_views: number; unique_countries: number };
      countries: Array<{
        country: string;
        sessions: number;
        users: number;
        views: number;
        percent: number;
      }>;
    }>("GET", `/admin/geo-stats?days=${days}`);
  },

  // === Platform settings ===
  getPlatformSettings() {
    return request<{ settings: Record<string, unknown> }>("GET", "/admin/platform-settings");
  },
  savePlatformSettings(settings: Record<string, unknown>) {
    return request<{ settings: Record<string, unknown> }>("PUT", "/admin/platform-settings", {
      data: { settings },
    });
  },
};
