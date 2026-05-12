import { useEffect, useState } from 'react';
import { BrowserRouter as Router, Routes, Route, useLocation } from 'react-router-dom';
import { initGA4 } from './lib/ga4';
import { AuthProvider } from './contexts/AuthContext';
import { WalletProvider } from './contexts/WalletContext';
import { LanguageProvider } from './contexts/LanguageContext';
import { Header } from './components/Header';
import { Footer } from './components/Footer';
import { AuthModal } from './components/AuthModal';
import { ProfileSelectPage } from './pages/ProfileSelectPage';
import { HomePage } from './pages/HomePage';
import { MovieDetailPage } from './pages/MovieDetailPage';
import { PlayerPage } from './pages/PlayerPage';
import { SearchPage } from './pages/SearchPage';
import { UserDashboardPage } from './pages/UserDashboardPage';
import { WatchPartyPage } from './pages/WatchPartyPage';
import { PaymentStatusPage } from './pages/PaymentStatusPage';
import { stripHashRouteFromUrl } from './lib/url';

const MAINTENANCE_PASSWORD = 'superfilm';
const MAINTENANCE_ACCESS_KEY = 'filmoteca_maintenance_access';

function MaintenanceGate({ onUnlock }: { onUnlock: () => void }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (password === MAINTENANCE_PASSWORD) {
      localStorage.setItem(MAINTENANCE_ACCESS_KEY, 'true');
      onUnlock();
      return;
    }

    setError('Parola nu este corectă.');
  }

  return (
    <div className="min-h-screen bg-[#0A0A0F] text-white">
      <main className="flex min-h-screen items-center justify-center px-6 py-12">
        <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-5 rounded-lg border border-white/10 bg-white/[0.04] p-6 shadow-2xl">
          <div className="space-y-2">
            <p className="text-sm font-medium uppercase tracking-[0.2em] text-accent">filmoteca.md</p>
            <h1 className="text-2xl font-semibold">Site în mentenanță</h1>
            <p className="text-sm leading-6 text-white/65">
              Pregătim conținutul. Introdu parola pentru acces temporar.
            </p>
          </div>

          <div className="space-y-2">
            <label htmlFor="maintenance-password" className="text-sm font-medium text-white/80">
              Parolă
            </label>
            <input
              id="maintenance-password"
              type="password"
              value={password}
              onChange={(event) => {
                setPassword(event.target.value);
                setError(null);
              }}
              className="h-11 w-full rounded-md border border-white/15 bg-black/30 px-3 text-sm text-white outline-none transition placeholder:text-white/35 focus:border-accent focus:ring-2 focus:ring-accent/30"
              placeholder="Introdu parola"
              autoFocus
            />
            {error ? <p className="text-sm text-red-300">{error}</p> : null}
          </div>

          <button
            type="submit"
            className="h-11 w-full rounded-md bg-accent px-4 text-sm font-semibold text-white transition hover:bg-accent/90"
          >
            Intră pe site
          </button>
        </form>
      </main>
    </div>
  );
}

function AppFrame() {
  const location = useLocation();
  const isPlayerRoute = location.pathname.startsWith('/watch/');
  const [isMaintenanceUnlocked, setIsMaintenanceUnlocked] = useState(() => {
    return localStorage.getItem(MAINTENANCE_ACCESS_KEY) === 'true';
  });

  if (!isMaintenanceUnlocked) {
    return <MaintenanceGate onUnlock={() => setIsMaintenanceUnlocked(true)} />;
  }

  return (
    <div className="min-h-screen flex flex-col bg-background text-white font-sans selection:bg-accent selection:text-white">
      {!isPlayerRoute ? <Header /> : null}
      {!isPlayerRoute ? <AuthModal /> : null}
      <main className="flex-grow">
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/profiles" element={<ProfileSelectPage />} />
          <Route path="/movie/:id" element={<MovieDetailPage />} />
          <Route path="/watch/:id" element={<PlayerPage />} />
          <Route path="/search" element={<SearchPage />} />
          <Route path="/dashboard" element={<UserDashboardPage />} />
          <Route path="/payment/success" element={<PaymentStatusPage fallbackStatus="success" />} />
          <Route path="/payment/failed" element={<PaymentStatusPage fallbackStatus="failed" />} />
          <Route path="/watch-party/:roomCode" element={<WatchPartyPage />} />
        </Routes>
      </main>
      {!isPlayerRoute ? <Footer /> : null}
    </div>
  );
}

export function App() {
  useEffect(() => {
    void initGA4();
  }, []);

  useEffect(() => {
    window.addEventListener('hashchange', stripHashRouteFromUrl);
    return () => {
      window.removeEventListener('hashchange', stripHashRouteFromUrl);
    };
  }, []);

  return (
    <Router>
      <AuthProvider>
        <LanguageProvider>
          <WalletProvider>
            <AppFrame />
          </WalletProvider>
        </LanguageProvider>
      </AuthProvider>
    </Router>
  );
}
