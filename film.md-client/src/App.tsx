import React, { useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
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
export function App() {
  useEffect(() => {
    void initGA4();
  }, []);

  return (
    <AuthProvider>
      <LanguageProvider>
        <WalletProvider>
          <Router>
            <div className="min-h-screen flex flex-col bg-background text-white font-sans selection:bg-accent selection:text-white">
              <Header />
              <AuthModal />
              <main className="flex-grow">
                <Routes>
                  <Route path="/" element={<HomePage />} />
                  <Route path="/profiles" element={<ProfileSelectPage />} />
                  <Route path="/movie/:id" element={<MovieDetailPage />} />
                  <Route path="/watch/:id" element={<PlayerPage />} />
                  <Route path="/search" element={<SearchPage />} />
                  <Route path="/dashboard" element={<UserDashboardPage />} />
                  <Route path="/watch-party/:roomCode" element={<WatchPartyPage />} />
                </Routes>
              </main>
              <Footer />
            </div>
          </Router>
        </WalletProvider>
      </LanguageProvider>
    </AuthProvider>);

}
