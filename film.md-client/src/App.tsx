import { useEffect, useState } from 'react';
import { BrowserRouter as Router, Routes, Route, useLocation } from 'react-router-dom';
import { initGA4 } from './lib/ga4';
import { AuthProvider } from './contexts/AuthContext';
import { WalletProvider } from './contexts/WalletContext';
import { LanguageProvider, useLanguage } from './contexts/LanguageContext';
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
import { applyDefaultSeo } from './lib/seo';
import filmotecaCover from './assets/filmoteca-cover.png';

const MAINTENANCE_PASSWORD = 'superfilm';
const MAINTENANCE_ACCESS_KEY = 'filmoteca_maintenance_access';

function MaintenanceGate({ onUnlock }: { onUnlock: () => void }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isLoginVisible, setIsLoginVisible] = useState(false);

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
    <div className="relative min-h-screen overflow-hidden bg-[#09090D] text-white">
      <img
        src={filmotecaCover}
        alt=""
        className="absolute inset-0 h-full w-full object-cover opacity-75"
      />
      <div className="absolute inset-0 bg-gradient-to-r from-[#09090D] via-[#09090D]/82 to-[#09090D]/20" />
      <div className="absolute inset-0 bg-gradient-to-t from-[#09090D] via-transparent to-black/45" />

      <header className="relative z-10 flex items-center justify-between px-5 py-5 md:px-10">
        <div className="text-sm font-semibold uppercase tracking-[0.22em] text-white/90">
          FILMOTECA<span className="text-accent">.</span>md
        </div>
        <button
          type="button"
          onClick={() => {
            setIsLoginVisible((visible) => !visible);
            setError(null);
          }}
          className="rounded-full border border-white/20 bg-black/35 px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-white/85 backdrop-blur-md transition hover:border-white/40 hover:bg-white/10 hover:text-white"
        >
          Login
        </button>
      </header>

      <main className="relative z-10 flex min-h-[calc(100vh-76px)] items-center px-5 pb-12 pt-8 md:px-10">
        <section className="grid w-full items-end gap-8 lg:grid-cols-[minmax(0,760px)_360px]">
          <div className="max-w-3xl pb-4">
            <h1 className="max-w-3xl text-4xl font-bold leading-[1.05] text-white drop-shadow-2xl md:text-6xl">
              FILMOTECA.md - Cinematografia Moldovei, mai aproape de tine.
            </h1>
            <p className="mt-6 max-w-2xl text-lg font-medium leading-8 text-white/88 md:text-xl">
              Îți mulțumim pentru interesul față de cinematografia națională.
            </p>
            <div className="mt-8 max-w-2xl space-y-5 text-base leading-7 text-white/74 md:text-lg md:leading-8">
              <p>Îți mulțumim pentru interesul față de cinematografia națională.</p>
              <p>
                FILMOTECA.md se pregătește de lansare — prima platformă digitală dedicată filmului moldovenesc și
                conținutului audiovizual local și internațional, cu acces individual și legal.
              </p>
              <p>
                Momentan suntem în etapa finală de testare și calibrare a platformei. Lucrăm la ultimele detalii
                pentru ca experiența să fie exact așa cum ne-am imaginat-o.
              </p>
              <p className="font-semibold text-white">Ne vedem curând la primul play.</p>
            </div>
          </div>

          {isLoginVisible ? (
            <form
              onSubmit={handleSubmit}
              className="w-full space-y-5 rounded-lg border border-white/12 bg-black/55 p-5 shadow-2xl backdrop-blur-xl"
            >
              <div className="space-y-2">
                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">Acces temporar</p>
                <h2 className="text-xl font-semibold">Login</h2>
                <p className="text-sm leading-6 text-white/65">
                  Introdu parola temporară pentru a accesa sistemul.
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
                  className="h-11 w-full rounded-md border border-white/15 bg-black/40 px-3 text-sm text-white outline-none transition placeholder:text-white/35 focus:border-accent focus:ring-2 focus:ring-accent/30"
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
          ) : null}
        </section>
      </main>
    </div>
  );
}

function AppFrame() {
  const location = useLocation();
  const { currentLanguage } = useLanguage();
  const isPlayerRoute = location.pathname.startsWith('/watch/');
  const [isMaintenanceUnlocked, setIsMaintenanceUnlocked] = useState(() => {
    return localStorage.getItem(MAINTENANCE_ACCESS_KEY) === 'true';
  });

  useEffect(() => {
    if (isMaintenanceUnlocked && !location.pathname.startsWith('/movie/')) {
      void applyDefaultSeo(currentLanguage.code);
    }
  }, [currentLanguage.code, isMaintenanceUnlocked, location.pathname]);

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
