import React from 'react';
import { AdminProvider, useAdmin } from './hooks/useAdmin';
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
function AdminRouter() {
  const { currentPage, isAuthenticated, isBooting } = useAdmin();

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

  const renderPage = () => {
    switch (currentPage) {
      case 'dashboard':
        return <Dashboard />;
      case 'catalog':
        return <ContentCatalog />;
      case 'editor':
        return <ContentEditor />;
      case 'media':
        return <MediaLibrary />;
      case 'taxonomies':
        return <Taxonomies />;
      case 'collections':
        return <Taxonomies />;
      case 'billing':
        return <Billing />;
      case 'home-curation':
        return <HomeCuration />;
      case 'discovery':
        return <SearchDiscovery />;
      case 'cms':
        return <CMSPages />;
      case 'playback':
        return <PlaybackOps />;
      case 'users':
        return <Users />;
      case 'roles':
        return <RolesPermissions />;
      case 'account':
        return <AccountSettings />;
      case 'moderation':
        return <Moderation />;
      case 'coupons':
        return <Coupons />;
      case 'content-creators':
        return <ContentCreators />;
      case 'watch-parties':
        return <WatchParties />;
      case 'geo-stats':
        return <GeoStats />;
      case 'price-settings':
        return <PriceSettings />;
      case 'ad-test':
        return <AdTest />;
      case 'bunny-health':
        return <BunnyHealth />;
      default:
        return <Dashboard />;
    }
  };
  return <AdminLayout>{renderPage()}</AdminLayout>;
}
export function App() {
  return (
    <AdminProvider>
      <AdminRouter />
    </AdminProvider>);

}
