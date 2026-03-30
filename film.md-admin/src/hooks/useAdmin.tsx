import React, { ReactNode, createContext, useContext, useEffect, useMemo, useState } from 'react';
import { adminApi, setAccessTokenGetter } from '../lib/api';
import { AdminUser } from '../types';

const ACCESS_TOKEN_STORAGE_KEY = 'film_admin_access_token';

type AdminPage =
  | 'dashboard'
  | 'catalog'
  | 'editor'
  | 'media'
  | 'taxonomies'
  | 'collections'
  | 'billing'
  | 'home-curation'
  | 'discovery'
  | 'cms'
  | 'playback'
  | 'users'
  | 'roles'
  | 'moderation'
  | 'account';

function canAccessPage(page: AdminPage, user: AdminUser | null): boolean {
  if (!user) {
    return false;
  }

  const permissions = new Set(user.permission_codes);

  if (page === 'dashboard' || page === 'account') {
    return true;
  }

  if (page === 'roles') {
    return permissions.has('settings.manage_roles') || permissions.has('users.view');
  }

  const mapping: Partial<Record<AdminPage, string>> = {
    catalog: 'content.view',
    editor: 'content.create',
    media: 'media.view',
    taxonomies: 'taxonomies.view',
    collections: 'taxonomies.view',
    billing: 'commerce.view_billing',
    'home-curation': 'settings.edit_home_curation',
    discovery: 'settings.edit_search_config',
    cms: 'cms.view',
    playback: 'playback.view_sessions',
    users: 'users.view',
    moderation: 'moderation.view_queue',
  };

  const requiredPermission = mapping[page];
  return requiredPermission ? permissions.has(requiredPermission) : false;
}

function firstAvailablePage(user: AdminUser | null): AdminPage {
  const candidates: AdminPage[] = [
    'dashboard',
    'users',
    'roles',
    'catalog',
    'cms',
    'media',
    'playback',
    'account',
  ];

  return candidates.find((page) => canAccessPage(page, user)) ?? 'dashboard';
}

interface AdminContextType {
  currentPage: AdminPage;
  sidebarCollapsed: boolean;
  selectedContentId: string | null;
  breadcrumbs: string[];
  currentUser: AdminUser | null;
  isAuthenticated: boolean;
  isBooting: boolean;
  isAuthLoading: boolean;
  authError: string | null;
  navigate: (
  page: AdminPage,
  contentId?: string | null,
  breadcrumbs?: string[])
  => void;
  toggleSidebar: () => void;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshCurrentUser: () => Promise<void>;
  can: (permission: string) => boolean;
}

const AdminContext = createContext<AdminContextType | undefined>(undefined);

export function AdminProvider({ children }: {children: ReactNode;}) {
  const [currentPage, setCurrentPage] = useState<AdminPage>('dashboard');
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [selectedContentId, setSelectedContentId] = useState<string | null>(
    null
  );
  const [breadcrumbs, setBreadcrumbs] = useState<string[]>(['Dashboard']);
  const [accessToken, setAccessToken] = useState<string | null>(null);
  const [currentUser, setCurrentUser] = useState<AdminUser | null>(null);
  const [isBooting, setIsBooting] = useState(true);
  const [isAuthLoading, setIsAuthLoading] = useState(false);
  const [authError, setAuthError] = useState<string | null>(null);

  useEffect(() => {
    const storedToken = localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY);
    if (storedToken) {
      setAccessToken(storedToken);
    } else {
      setIsBooting(false);
    }
  }, []);

  useEffect(() => {
    setAccessTokenGetter(() => accessToken);
  }, [accessToken]);

  async function refreshCurrentUser() {
    if (!accessToken) {
      setCurrentUser(null);
      setIsBooting(false);
      return;
    }

    try {
      const response = await adminApi.me();
      setCurrentUser(response.user);
      setCurrentPage((page) =>
        canAccessPage(page, response.user) ? page : firstAvailablePage(response.user),
      );
    } catch (error) {
      localStorage.removeItem(ACCESS_TOKEN_STORAGE_KEY);
      setAccessToken(null);
      setCurrentUser(null);
      setAuthError(error instanceof Error ? error.message : 'Session expired.');
    } finally {
      setIsBooting(false);
    }
  }

  useEffect(() => {
    void refreshCurrentUser();
  }, [accessToken]);

  const navigate = (
  page: AdminPage,
  contentId: string | null = null,
  newBreadcrumbs?: string[]) =>
  {
    if (!canAccessPage(page, currentUser)) {
      return;
    }
    setCurrentPage(page);
    setSelectedContentId(contentId);
    if (newBreadcrumbs) {
      setBreadcrumbs(newBreadcrumbs);
    } else {
      // Auto-generate basic breadcrumb if not provided
      const pageName =
      page.charAt(0).toUpperCase() +
      page.
      slice(1).
      replace(/([A-Z])/g, ' $1').
      trim();
      setBreadcrumbs([pageName]);
    }
  };
  const toggleSidebar = () => setSidebarCollapsed(!sidebarCollapsed);
  const can = (permission: string) =>
  currentUser?.permission_codes.includes(permission) ?? false;

  async function login(email: string, password: string) {
    setIsAuthLoading(true);
    setAuthError(null);

    try {
      const response = await adminApi.login(email, password);
      localStorage.setItem(ACCESS_TOKEN_STORAGE_KEY, response.token);
      setAccessToken(response.token);
      setCurrentUser(response.user);
      setCurrentPage(firstAvailablePage(response.user));
      setBreadcrumbs(['Dashboard']);
    } catch (error) {
      setAuthError(
        error instanceof Error ? error.message : 'Autentificarea a eșuat.',
      );
    } finally {
      setIsAuthLoading(false);
      setIsBooting(false);
    }
  }

  async function logout() {
    try {
      await adminApi.logout();
    } catch {
      // Ignore logout API failures and clear local session anyway.
    }

    localStorage.removeItem(ACCESS_TOKEN_STORAGE_KEY);
    setAccessToken(null);
    setCurrentUser(null);
    setCurrentPage('dashboard');
    setBreadcrumbs(['Dashboard']);
  }

  return (
    <AdminContext.Provider
      value={{
        currentPage,
        sidebarCollapsed,
        selectedContentId,
        breadcrumbs,
        currentUser,
        isAuthenticated: currentUser !== null,
        isBooting,
        isAuthLoading,
        authError,
        navigate,
        toggleSidebar,
        login,
        logout,
        refreshCurrentUser,
        can
      }}>
      
      {children}
    </AdminContext.Provider>);

}
export function useAdmin() {
  const context = useContext(AdminContext);
  if (context === undefined) {
    throw new Error('useAdmin must be used within an AdminProvider');
  }
  return context;
}
