import {
  AdminInvitation,
  AdminContent,
  AdminContentFilters,
  AdminContentOptions,
  AdminOffer,
  AdminPermission,
  AdminRole,
  AdminTaxonomy,
  AdminUser,
  ContentPayload,
  DashboardResponse,
  HomeCurationResponse,
  HomeCurationSection,
  OfferIndexResponse,
  OfferPayload,
  TaxonomyIndexResponse,
  TaxonomyPayload,
} from "../types";

const API_URL = import.meta.env.VITE_API_URL ?? "http://localhost:8000/api/v1";

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
    return request<{ users: AdminUser[]; invitations: AdminInvitation[] }>(
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
};
