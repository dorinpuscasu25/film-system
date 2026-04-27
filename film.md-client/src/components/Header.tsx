import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { SearchIcon, WalletIcon, MenuIcon, XIcon } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { WalletModal } from './WalletModal';
export function Header() {
  const { isAuthenticated, activeProfile, logout, openAuthModal, isLoading: isAuthLoading } = useAuth();
  const { balance, currency } = useWallet();
  const { currentLanguage, languages, setLanguage, t } = useLanguage();
  const navigate = useNavigate();
  const location = useLocation();
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isWalletModalOpen, setIsWalletModalOpen] = useState(false);
  const [showProfileMenu, setShowProfileMenu] = useState(false);
  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);
  // Don't show header on player page
  if (location.pathname.startsWith('/watch')) return null;
  return (
    <>
      <header
        className={`fixed top-0 w-full z-40 transition-all duration-300 ${isScrolled ? 'bg-background/95 backdrop-blur-md shadow-lg py-3' : 'bg-gradient-to-b from-black/80 to-transparent py-5'}`}>
        
        <div className="container mx-auto px-4 md:px-8 flex items-center justify-between">
          {/* Logo & Desktop Nav */}
          <div className="flex items-center space-x-8">
            <Link
              to="/"
              className="text-2xl font-bold tracking-tighter text-white">
              
              filmoteca<span className="text-accent">.</span>md
            </Link>

            {/* Nav links always visible */}
            <nav className="hidden md:flex items-center space-x-6">
              <Link
                to="/"
                className={`text-sm font-medium transition-colors ${location.pathname === '/' ? 'text-white' : 'text-gray-400 hover:text-white'}`}>
                
                {t('nav.home')}
              </Link>
              <Link
                to="/search?type=movie"
                className="text-sm font-medium text-gray-400 hover:text-white transition-colors">
                
                {t('nav.movies')}
              </Link>
              <Link
                to="/search?type=series"
                className="text-sm font-medium text-gray-400 hover:text-white transition-colors">
                
                {t('nav.series')}
              </Link>
            </nav>
          </div>

          {/* Right Actions */}
          <div className="flex items-center space-x-4 md:space-x-6">
            {/* Search — always visible */}
            <button
              onClick={() => navigate('/search')}
              className="text-gray-300 hover:text-white transition-colors">
              
              <SearchIcon className="w-5 h-5" />
            </button>

            {/* Language Switcher */}
            <div className="hidden md:flex items-center space-x-1 bg-surface/50 rounded-full px-2 py-1 border border-white/5">
              {languages.map((lang) =>
              <button
                key={lang.code}
                onClick={() => setLanguage(lang.code)}
                className={`text-xs px-2 py-1 rounded-full transition-colors ${currentLanguage.code === lang.code ? 'bg-white/20 text-white' : 'text-gray-400 hover:text-white'}`}>
                
                  {lang.code.toUpperCase()}
                </button>
              )}
            </div>

            {isAuthLoading ? null :
            isAuthenticated ?
            <>
                {/* Wallet */}
                <button
                onClick={() => setIsWalletModalOpen(true)}
                className="hidden md:flex items-center space-x-2 bg-surfaceHover hover:bg-white/10 transition-colors px-3 py-1.5 rounded-full border border-white/10 group">
                
                  <WalletIcon className="w-4 h-4 text-accentGreen group-hover:scale-110 transition-transform" />
                  <span className="text-sm font-bold text-white">
                    {currency} {balance.toFixed(2)}
                  </span>
                </button>

                {/* Profile Dropdown */}
                {activeProfile &&
              <div className="relative">
                    <button
                  onClick={() => setShowProfileMenu(!showProfileMenu)}
                  className="flex items-center space-x-2">
                  
                      <div
                    className={`w-8 h-8 rounded-md bg-gradient-to-br ${activeProfile.color} flex items-center justify-center text-sm font-bold text-white`}>
                    
                        {activeProfile.avatarUrl}
                      </div>
                    </button>

                    {showProfileMenu &&
                <div className="absolute right-0 mt-4 w-48 glass-panel rounded-xl py-2 shadow-2xl border border-white/10">
                        <div className="px-4 py-2 border-b border-white/10 mb-2">
                          <p className="text-sm text-white font-medium">
                            {activeProfile.name}
                          </p>
                          <p className="text-xs text-accentGreen font-bold md:hidden">
                            {currency} {balance.toFixed(2)}
                          </p>
                        </div>
                        <Link
                    to="/dashboard"
                    onClick={() => setShowProfileMenu(false)}
                    className="block px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-white/5">
                    
                          Dashboard
                        </Link>
                        <Link
                    to="/profiles"
                    onClick={() => setShowProfileMenu(false)}
                    className="block px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-white/5">
                    
                          Switch Profile
                        </Link>
                        <button
                    onClick={() => {
                      logout();
                      setShowProfileMenu(false);
                      navigate('/');
                    }}
                    className="w-full text-left px-4 py-2 text-sm text-accent hover:bg-white/5">
                    
                          Sign Out
                        </button>
                      </div>
                }
                  </div>
              }
              </> :

            <button
              onClick={openAuthModal}
              className="bg-accent hover:bg-red-700 text-white px-4 py-1.5 rounded font-medium transition-colors text-sm">
              
                {t('auth.login')}
              </button>
            }

            {/* Mobile Menu Toggle — always visible */}
            <button
              className="md:hidden text-gray-300 hover:text-white"
              onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}>
              
              {isMobileMenuOpen ?
              <XIcon className="w-6 h-6" /> :

              <MenuIcon className="w-6 h-6" />
              }
            </button>
          </div>
        </div>

        {/* Mobile Menu — always available */}
        {isMobileMenuOpen &&
        <div className="md:hidden absolute top-full left-0 w-full bg-surface border-b border-white/10 py-4 px-4 shadow-xl">
            <nav className="flex flex-col space-y-4">
              <Link
              to="/"
              onClick={() => setIsMobileMenuOpen(false)}
              className="text-white font-medium">
              
                Home
              </Link>
              <Link
              to="/search"
              onClick={() => setIsMobileMenuOpen(false)}
              className="text-white font-medium">
              
                Movies & Series
              </Link>
              {isAuthenticated ?
            <button
              onClick={() => {
                setIsWalletModalOpen(true);
                setIsMobileMenuOpen(false);
              }}
              className="flex items-center space-x-2 text-accentGreen font-medium">
              
                  <WalletIcon className="w-5 h-5" />
                  <span>Wallet: {currency} {balance.toFixed(2)}</span>
                </button> :

            <button
              onClick={() => {
                openAuthModal();
                setIsMobileMenuOpen(false);
              }}
              className="text-accent font-medium text-left">
              
                  Log In / Register
                </button>
            }
            </nav>
          </div>
        }
      </header>

      <WalletModal
        isOpen={isWalletModalOpen}
        onClose={() => setIsWalletModalOpen(false)} />
      
    </>);

}
