import { ReactNode, createContext, useContext, useEffect, useState } from 'react';
import { useLocation, useNavigate as useRouterNavigate } from 'react-router-dom';
import { adminApi, setAccessTokenGetter } from '../lib/api';
import { AdminUser } from '../types';

const ACCESS_TOKEN_STORAGE_KEY = 'film_admin_access_token';

export type AdminPage =
  | 'dashboard'
  | 'catalog'
  | 'editor'
  | 'reviews'
  | 'media'
  | 'taxonomies'
  | 'collections'
  | 'billing'
  | 'price-settings'
  | 'coupons'
  | 'geo-stats'
  | 'ads'
  | 'ad-test'
  | 'watch-parties'
  | 'content-creators'
  | 'home-curation'
  | 'discovery'
  | 'cms'
  | 'playback'
  | 'users'
  | 'roles'
  | 'moderation'
  | 'bunny-health'
  | 'account';

export function adminPathForPage(page: AdminPage, contentId: string | null = null): string {
  if (page === 'editor') {
    return contentId && contentId !== 'new' ? `/movies/${contentId}` : '/movies/new';
  }

  const paths: Record<Exclude<AdminPage, 'editor'>, string> = {
    dashboard: '/dashboard',
    catalog: '/movies',
    reviews: '/reviews',
    media: '/media',
    taxonomies: '/taxonomies',
    collections: '/collections',
    billing: '/billing',
    'price-settings': '/prices',
    coupons: '/coupons',
    'geo-stats': '/geo-stats',
    ads: '/ads',
    'ad-test': '/ads/test',
    'watch-parties': '/watch-parties',
    'content-creators': '/creators',
    'home-curation': '/home-curation',
    discovery: '/discovery',
    cms: '/cms',
    playback: '/playback',
    users: '/users',
    roles: '/roles',
    moderation: '/moderation',
    'bunny-health': '/bunny-health',
    account: '/account',
  };

  return paths[page];
}

function defaultBreadcrumb(page: AdminPage): string[] {
  const labels: Record<AdminPage, string> = {
    dashboard: 'Tablou de bord',
    catalog: 'Filme',
    editor: 'Filme',
    reviews: 'Review-uri',
    media: 'Bibliotecă media',
    taxonomies: 'Taxonomii',
    collections: 'Colecții',
    billing: 'Costuri & venituri',
    'price-settings': 'Setări prețuri',
    coupons: 'Cupoane',
    'geo-stats': 'Distribuție geografică',
    ads: 'Reclame',
    'ad-test': 'VAST Test',
    'watch-parties': 'Watch Parties',
    'content-creators': 'Creatori',
    'home-curation': 'Pagina principală',
    discovery: 'Căutare',
    cms: 'CMS',
    playback: 'Playback',
    users: 'Utilizatori',
    roles: 'Roluri',
    moderation: 'Moderare',
    'bunny-health': 'Bunny Health',
    account: 'Setări cont',
  };

  return [labels[page]];
}

export function canAccessAdminPage(page: AdminPage, user: AdminUser | null): boolean {
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
    reviews: 'content.view',
    editor: 'content.create',
    media: 'media.view',
    taxonomies: 'taxonomies.view',
    collections: 'taxonomies.view',
    billing: 'commerce.view_billing',
    'price-settings': 'commerce.view_billing',
    coupons: 'commerce.view',
    'geo-stats': 'commerce.view_billing',
    ads: 'advertising.view',
    'ad-test': 'advertising.view',
    'watch-parties': 'content.view',
    'content-creators': 'users.view',
    'home-curation': 'settings.edit_home_curation',
    discovery: 'settings.edit_search_config',
    cms: 'cms.view',
    playback: 'playback.view_sessions',
    users: 'users.view',
    moderation: 'moderation.view_queue',
    'bunny-health': 'settings.edit_home_curation',
  };

  const requiredPermission = mapping[page];
  return requiredPermission ? permissions.has(requiredPermission) : false;
}

export function firstAvailablePage(user: AdminUser | null): AdminPage {
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

  return candidates.find((page) => canAccessAdminPage(page, user)) ?? 'dashboard';
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
  canAccessPage: (page: AdminPage) => boolean;
  setRouteState: (
  page: AdminPage,
  contentId?: string | null,
  breadcrumbs?: string[])
  => void;
  firstAvailablePath: () => string;
}

const AdminContext = createContext<AdminContextType | undefined>(undefined);

export function AdminProvider({ children }: {children: ReactNode;}) {
  const routerNavigate = useRouterNavigate();
  const routerLocation = useLocation();
  const [currentPage, setCurrentPage] = useState<AdminPage>('dashboard');
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [selectedContentId, setSelectedContentId] = useState<string | null>(
    null
  );
  const [breadcrumbs, setBreadcrumbs] = useState<string[]>(['Panou']);
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
        canAccessAdminPage(page, response.user) ? page : firstAvailablePage(response.user),
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
    if (!canAccessAdminPage(page, currentUser)) {
      return;
    }
    setCurrentPage(page);
    setSelectedContentId(contentId);
    if (newBreadcrumbs) {
      setBreadcrumbs(newBreadcrumbs);
    } else {
      setBreadcrumbs(defaultBreadcrumb(page));
    }
    routerNavigate(adminPathForPage(page, contentId));
  };
  const toggleSidebar = () => setSidebarCollapsed(!sidebarCollapsed);
  const can = (permission: string) =>
  currentUser?.permission_codes.includes(permission) ?? false;
  const canAccessPage = (page: AdminPage) => canAccessAdminPage(page, currentUser);
  const setRouteState = (
  page: AdminPage,
  contentId: string | null = null,
  newBreadcrumbs?: string[]) =>
  {
    setCurrentPage(page);
    setSelectedContentId(contentId);
    setBreadcrumbs(newBreadcrumbs ?? defaultBreadcrumb(page));
  };
  const firstAvailablePath = () => adminPathForPage(firstAvailablePage(currentUser));

  async function login(email: string, password: string) {
    setIsAuthLoading(true);
    setAuthError(null);

    try {
      const response = await adminApi.login(email, password);
      localStorage.setItem(ACCESS_TOKEN_STORAGE_KEY, response.token);
      setAccessToken(response.token);
      setCurrentUser(response.user);
      const nextPage = firstAvailablePage(response.user);
      setCurrentPage(nextPage);
      setBreadcrumbs(defaultBreadcrumb(nextPage));
      if (routerLocation.pathname === '/' || routerLocation.pathname === '/login') {
        routerNavigate(adminPathForPage(nextPage), { replace: true });
      } else {
        routerNavigate(`${routerLocation.pathname}${routerLocation.search}`, { replace: true });
      }
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
    setSelectedContentId(null);
    setBreadcrumbs(defaultBreadcrumb('dashboard'));
    routerNavigate('/login', { replace: true });
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
        can,
        canAccessPage,
        setRouteState,
        firstAvailablePath
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
