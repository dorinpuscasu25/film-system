import React, { useLayoutEffect } from 'react';
import { Navigate, Route, Routes, useParams } from 'react-router-dom';
import { AdminProvider, useAdmin } from './hooks/useAdmin';
import type { AdminPage } from './hooks/useAdmin';
import { AdminLayout } from './components/AdminLayout';
import { LoginPage } from './pages/LoginPage';
// Pages
import { AccountSettings } from './pages/AccountSettings';
import { Dashboard } from './pages/Dashboard';
import { ContentCatalog } from './pages/ContentCatalog';
import { ContentEditor } from './pages/ContentEditor';
import { MediaLibrary } from './pages/MediaLibrary';
import { Taxonomies } from './pages/Taxonomies';
import { HomeCuration } from './pages/HomeCuration';
import { SearchDiscovery } from './pages/SearchDiscovery';
import { PlaybackOps } from './pages/PlaybackOps';
import { Users } from './pages/Users';
import { Billing } from './pages/Billing';
import { CMSPages } from './pages/CMSPages';
import { Moderation } from './pages/Moderation';
import { RolesPermissions } from './pages/RolesPermissions';
import { Coupons } from './pages/Coupons';
import { ContentCreators } from './pages/ContentCreators';
import { WatchParties } from './pages/WatchParties';
import { GeoStats } from './pages/GeoStats';
import { PriceSettings } from './pages/PriceSettings';
import { AdTest } from './pages/AdTest';
import { BunnyHealth } from './pages/BunnyHealth';

function RoutePage({
  page,
  contentId = null,
  breadcrumbs,
  children,
}: {
  page: AdminPage;
  contentId?: string | null;
  breadcrumbs?: string[];
  children: React.ReactNode;
}) {
  const { canAccessPage, firstAvailablePath, setRouteState } = useAdmin();

  useLayoutEffect(() => {
    setRouteState(page, contentId, breadcrumbs);
  }, [page, contentId, breadcrumbs?.join('\u0000')]);

  if (!canAccessPage(page)) {
    return <Navigate to={firstAvailablePath()} replace />;
  }

  return <AdminLayout>{children}</AdminLayout>;
}

function EditorRoute({ isNew = false }: { isNew?: boolean }) {
  const { contentId } = useParams();
  const editorContentId = isNew ? 'new' : contentId ?? 'new';
  const editorBreadcrumbs = [
    'Filme',
    editorContentId === 'new' ? 'Adaugă film' : `Film #${editorContentId}`,
  ];

  return (
    <RoutePage page="editor" contentId={editorContentId} breadcrumbs={editorBreadcrumbs}>
      <ContentEditor contentId={editorContentId} />
    </RoutePage>
  );
}

function LegacyEditorRedirect() {
  const { contentId } = useParams();

  return <Navigate to={`/movies/${contentId ?? 'new'}`} replace />;
}

function AdminRouter() {
  const { isAuthenticated, isBooting } = useAdmin();

  if (isBooting) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background text-sm text-muted-foreground">
        Se încarcă sesiunea...
      </div>
    );
  }

  if (!isAuthenticated) {
    return <LoginPage />;
  }

  return (
    <Routes>
      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="/login" element={<Navigate to="/dashboard" replace />} />
      <Route path="/catalog" element={<Navigate to="/movies" replace />} />
      <Route path="/content" element={<Navigate to="/movies" replace />} />
      <Route path="/editor/new" element={<Navigate to="/movies/new" replace />} />
      <Route path="/editor/:contentId" element={<LegacyEditorRedirect />} />

      <Route path="/dashboard" element={<RoutePage page="dashboard" breadcrumbs={['Tablou de bord']}><Dashboard /></RoutePage>} />
      <Route path="/movies" element={<RoutePage page="catalog" breadcrumbs={['Filme']}><ContentCatalog /></RoutePage>} />
      <Route path="/movies/new" element={<EditorRoute isNew />} />
      <Route path="/movies/:contentId" element={<EditorRoute />} />
      <Route path="/media" element={<RoutePage page="media" breadcrumbs={['Bibliotecă media']}><MediaLibrary /></RoutePage>} />
      <Route path="/taxonomies" element={<RoutePage page="taxonomies" breadcrumbs={['Taxonomii']}><Taxonomies /></RoutePage>} />
      <Route path="/collections" element={<RoutePage page="collections" breadcrumbs={['Colecții']}><Taxonomies /></RoutePage>} />
      <Route path="/billing" element={<RoutePage page="billing" breadcrumbs={['Costuri & venituri']}><Billing /></RoutePage>} />
      <Route path="/prices" element={<RoutePage page="price-settings" breadcrumbs={['Prețuri']}><PriceSettings /></RoutePage>} />
      <Route path="/coupons" element={<RoutePage page="coupons" breadcrumbs={['Cupoane']}><Coupons /></RoutePage>} />
      <Route path="/geo-stats" element={<RoutePage page="geo-stats" breadcrumbs={['Distribuție geografică']}><GeoStats /></RoutePage>} />
      <Route path="/ads" element={<RoutePage page="ads" breadcrumbs={['Reclame']}><AdTest /></RoutePage>} />
      <Route path="/ads/test" element={<RoutePage page="ad-test" breadcrumbs={['Reclame', 'VAST Test']}><AdTest /></RoutePage>} />
      <Route path="/watch-parties" element={<RoutePage page="watch-parties" breadcrumbs={['Watch Parties']}><WatchParties /></RoutePage>} />
      <Route path="/users" element={<RoutePage page="users" breadcrumbs={['Utilizatori']}><Users /></RoutePage>} />
      <Route path="/creators" element={<RoutePage page="content-creators" breadcrumbs={['Creatori']}><ContentCreators /></RoutePage>} />
      <Route path="/roles" element={<RoutePage page="roles" breadcrumbs={['Roluri']}><RolesPermissions /></RoutePage>} />
      <Route path="/home-curation" element={<RoutePage page="home-curation" breadcrumbs={['Pagina principală']}><HomeCuration /></RoutePage>} />
      <Route path="/discovery" element={<RoutePage page="discovery" breadcrumbs={['Căutare']}><SearchDiscovery /></RoutePage>} />
      <Route path="/cms" element={<RoutePage page="cms" breadcrumbs={['CMS']}><CMSPages /></RoutePage>} />
      <Route path="/playback" element={<RoutePage page="playback" breadcrumbs={['Playback']}><PlaybackOps /></RoutePage>} />
      <Route path="/moderation" element={<RoutePage page="moderation" breadcrumbs={['Moderare']}><Moderation /></RoutePage>} />
      <Route path="/bunny-health" element={<RoutePage page="bunny-health" breadcrumbs={['Bunny Health']}><BunnyHealth /></RoutePage>} />
      <Route path="/account" element={<RoutePage page="account" breadcrumbs={['Setări cont']}><AccountSettings /></RoutePage>} />

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}
export function App() {
  return (
    <AdminProvider>
      <AdminRouter />
    </AdminProvider>);

}
