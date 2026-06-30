import React, { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { HeartIcon, SearchIcon, WalletIcon, MenuIcon, XIcon, TvIcon, RefreshCwIcon } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { WalletModal } from './WalletModal';
import { getPublicMenu, PublicMenuItem } from '../lib/storefront';
import { clearStorefrontCache } from '../lib/session';

type MenuNode = PublicMenuItem & { children: MenuNode[] };

function buildMenuTree(items: PublicMenuItem[]): MenuNode[] {
  const nodes = new Map<number, MenuNode>();
  const roots: MenuNode[] = [];
  items.forEach((item) => nodes.set(item.id, { ...item, children: [] }));
  items.forEach((item) => {
    const node = nodes.get(item.id);
    if (!node) return;
    if (item.parent_id && nodes.has(item.parent_id)) {
      nodes.get(item.parent_id)?.children.push(node);
    } else {
      roots.push(node);
    }
  });
  const sortNodes = (nodesToSort: MenuNode[]) => {
    nodesToSort.sort((a, b) => a.sort_order - b.sort_order);
    nodesToSort.forEach((node) => sortNodes(node.children));
  };
  sortNodes(roots);
  return roots;
}

function MenuLink({
  item,
  className,
  onClick,
}: {
  item: PublicMenuItem;
  className: string;
  onClick?: () => void;
}) {
  const isExternal = item.resolved_url.startsWith('http') || item.target === '_blank';
  if (isExternal) {
    return (
      <a href={item.resolved_url} target={item.target === '_blank' ? '_blank' : undefined} rel="noreferrer" className={className} onClick={onClick}>
        {item.label}
      </a>
    );
  }

  return (
    <Link to={item.resolved_url} className={className} onClick={onClick}>
      {item.label}
    </Link>
  );
}

function DesktopSubmenuNode({ item }: { item: MenuNode }) {
  return (
    <div className="group/sub relative">
      <MenuLink item={item} className="flex rounded-md px-3 py-2 text-sm text-gray-300 hover:bg-white/5 hover:text-white" />
      {item.children.length > 0 ? (
        <div className="invisible absolute left-full top-0 min-w-56 rounded-lg border border-white/10 bg-surface p-2 opacity-0 shadow-xl transition group-hover/sub:visible group-hover/sub:opacity-100">
          {item.children.map((child) => (
            <DesktopSubmenuNode key={child.id} item={child} />
          ))}
        </div>
      ) : null}
    </div>
  );
}

function DesktopMenuNode({ item, isActive }: { item: MenuNode; isActive: boolean }) {
  return (
    <div className="group relative">
      <MenuLink
        item={item}
        className={`text-sm font-medium transition-colors ${isActive ? 'text-white' : 'text-gray-400 hover:text-white'}`}
      />
      {item.children.length > 0 ? (
        <div className="invisible absolute left-0 top-full min-w-56 rounded-lg border border-white/10 bg-surface p-2 opacity-0 shadow-xl transition group-hover:visible group-hover:opacity-100">
          {item.children.map((child) => (
            <DesktopSubmenuNode key={child.id} item={child} />
          ))}
        </div>
      ) : null}
    </div>
  );
}

function MobileMenuNode({ item, depth = 0, onClick }: { item: MenuNode; depth?: number; onClick: () => void }) {
  return (
    <>
      <MenuLink
        item={item}
        onClick={onClick}
        className="block rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
      />
      {item.children.map((child) => (
        <div key={child.id} style={{ paddingLeft: `${(depth + 1) * 12}px` }}>
          <MobileMenuNode item={child} depth={depth + 1} onClick={onClick} />
        </div>
      ))}
    </>
  );
}

export function Header() {
  const { isAuthenticated, user, activeProfile, logout, openAuthModal, isLoading: isAuthLoading } = useAuth();
  const { balance, currency } = useWallet();
  const { currentLanguage, languages, setLanguage, t } = useLanguage();
  const navigate = useNavigate();
  const location = useLocation();
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isWalletModalOpen, setIsWalletModalOpen] = useState(false);
  const [showProfileMenu, setShowProfileMenu] = useState(false);
  const [menuItems, setMenuItems] = useState<MenuNode[]>([]);
  const [isCacheClearing, setIsCacheClearing] = useState(false);
  const [cacheMessage, setCacheMessage] = useState<string | null>(null);
  const profileMenuRef = useRef<HTMLDivElement | null>(null);
  const canClearStorefrontCache = Boolean(
    user?.adminPanelAccess || user?.permissionCodes?.includes('settings.edit_home_curation'),
  );
  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);
  useEffect(() => {
    const loadMenu = async () => {
      try {
        const response = await getPublicMenu(currentLanguage.code, 'header');
        setMenuItems(buildMenuTree(response.items));
      } catch {
        setMenuItems([]);
      }
    };

    void loadMenu();
  }, [currentLanguage.code]);

  useEffect(() => {
    if (!showProfileMenu) {
      return;
    }

    const handleOutsideClick = (event: PointerEvent) => {
      if (!profileMenuRef.current?.contains(event.target as Node)) {
        setShowProfileMenu(false);
      }
    };
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setShowProfileMenu(false);
      }
    };

    document.addEventListener('pointerdown', handleOutsideClick, true);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('pointerdown', handleOutsideClick, true);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [showProfileMenu]);

  useEffect(() => {
    setShowProfileMenu(false);
  }, [location.pathname]);

  async function handleClearCache() {
    if (isCacheClearing) {
      return;
    }

    setIsCacheClearing(true);
    setCacheMessage(null);

    try {
      await clearStorefrontCache();
      setCacheMessage(t('admin.cache_cleared'));
      window.setTimeout(() => setCacheMessage(null), 2500);
    } catch {
      setCacheMessage(t('admin.cache_clear_failed'));
      window.setTimeout(() => setCacheMessage(null), 3000);
    } finally {
      setIsCacheClearing(false);
    }
  }

  // Don't show header on player page
  if (location.pathname.startsWith('/watch')) return null;
  return (
    <>
      <header
        className={`fixed top-0 w-full z-40 transition-all duration-300 ${isScrolled || isMobileMenuOpen ? 'bg-background/95 backdrop-blur-md shadow-lg py-3' : 'bg-gradient-to-b from-black/80 to-transparent py-5'}`}>
        
        <div className="container mx-auto px-4 md:px-8 flex items-center justify-between">
          {/* Logo & Desktop Nav */}
          <div className="flex items-center space-x-8">
            <Link
              to="/"
              className="text-2xl font-bold tracking-tighter text-white">
              
              filmoteca<span className="text-accent">.</span>md
            </Link>

            <nav className="hidden md:flex items-center space-x-6">
              {menuItems.length > 0 ? (
                menuItems.map((item) => (
                  <DesktopMenuNode key={item.id} item={item} isActive={location.pathname === item.resolved_url} />
                ))
              ) : (
                <>
                  <Link to="/" className={`text-sm font-medium transition-colors ${location.pathname === '/' ? 'text-white' : 'text-gray-400 hover:text-white'}`}>
                    {t('nav.home')}
                  </Link>
                  <Link to="/search?type=movie" className="text-sm font-medium text-gray-400 hover:text-white transition-colors">
                    {t('nav.movies')}
                  </Link>
                  <Link to="/search?type=documentary" className="text-sm font-medium text-gray-400 hover:text-white transition-colors">
                    {t('nav.documentaries')}
                  </Link>
                  <Link to="/search?type=animation" className="text-sm font-medium text-gray-400 hover:text-white transition-colors">
                    {t('nav.animations')}
                  </Link>
                  <Link to="/search?type=series" className="text-sm font-medium text-gray-400 hover:text-white transition-colors">
                    {t('nav.series')}
                  </Link>
                </>
              )}
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
                {canClearStorefrontCache ? (
                  <button
                    type="button"
                    onClick={handleClearCache}
                    disabled={isCacheClearing}
                    className="hidden h-9 items-center gap-2 rounded-full border border-white/10 bg-surfaceHover px-3 text-xs font-semibold text-white transition-colors hover:bg-white/10 disabled:cursor-wait disabled:opacity-60 md:flex"
                    aria-label={t('admin.clear_cache')}
                    title={cacheMessage ?? t('admin.clear_cache')}
                  >
                    <RefreshCwIcon className={`h-4 w-4 text-accentCyan ${isCacheClearing ? 'animate-spin' : ''}`} />
                    <span>{t('admin.cache')}</span>
                  </button>
                ) : null}
                {/* Wallet */}
                <button
                onClick={() => setIsWalletModalOpen(true)}
                className="hidden md:flex items-center space-x-2 bg-surfaceHover hover:bg-white/10 transition-colors px-3 py-1.5 rounded-full border border-white/10 group">
                
                  <WalletIcon className="w-4 h-4 text-accentGreen group-hover:scale-110 transition-transform" />
                  <span className="text-sm font-bold text-white">
                    {currency} {balance.toFixed(2)}
                  </span>
                </button>
                <button
                  onClick={() => navigate('/dashboard?tab=favorites')}
                  className="hidden md:flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-surfaceHover text-white transition-colors hover:bg-white/10"
                  aria-label={t('dashboard.favorites')}
                  title={t('dashboard.favorites')}
                >
                  <HeartIcon className="h-4 w-4 text-accent" />
                </button>

                {/* Profile Dropdown */}
                {activeProfile &&
              <div ref={profileMenuRef} className="relative">
                    <button
                  onClick={() => setShowProfileMenu(!showProfileMenu)}
                  className="flex items-center space-x-2">
                  
                      <div
                    className={`w-8 h-8 rounded-md bg-gradient-to-br ${activeProfile.color} flex items-center justify-center text-sm font-bold text-white`}>
                    
                        {activeProfile.avatarUrl}
                      </div>
                    </button>

                    {showProfileMenu &&
                <>
                    <button
                      type="button"
                      aria-label={t('common.close')}
                      className="fixed inset-0 z-0 cursor-default"
                      onClick={() => setShowProfileMenu(false)}
                    />
                    <div className="absolute right-0 z-10 mt-4 w-56 rounded-xl border border-white/10 bg-surface/95 py-2 shadow-2xl shadow-black/40 backdrop-blur-xl">
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
                    
                          {t('common.dashboard')}
                        </Link>
                        <Link
                    to="/profiles"
                    onClick={() => setShowProfileMenu(false)}
                    className="block px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-white/5">

                          {t('profiles.manage')}
                        </Link>
                        <Link
                    to="/tv"
                    onClick={() => setShowProfileMenu(false)}
                    className="flex items-center gap-2 px-4 py-2 text-sm text-gray-300 hover:text-white hover:bg-white/5">
                          <TvIcon className="h-4 w-4" />
                          Conectează TV
                        </Link>
                        <button
                    onClick={() => {
                      logout();
                      setShowProfileMenu(false);
                      navigate('/');
                    }}
                    className="w-full text-left px-4 py-2 text-sm text-accent hover:bg-white/5">
                    
                          {t('header.logout')}
                        </button>
                      </div>
                    </>
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
        <div className="absolute left-0 top-full max-h-[calc(100vh-72px)] w-full overflow-y-auto border-b border-white/10 bg-background px-4 pb-5 pt-3 shadow-2xl shadow-black/60 backdrop-blur-xl md:hidden">
            <nav className="flex flex-col gap-1">
              {menuItems.length > 0 ? (
                menuItems.map((item) => (
                  <MobileMenuNode key={item.id} item={item} onClick={() => setIsMobileMenuOpen(false)} />
                ))
              ) : (
                <>
                  <Link to="/" onClick={() => setIsMobileMenuOpen(false)} className="rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    {t('nav.home')}
                  </Link>
                  <Link to="/search?type=movie" onClick={() => setIsMobileMenuOpen(false)} className="rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    {t('nav.movies')}
                  </Link>
                  <Link to="/search?type=documentary" onClick={() => setIsMobileMenuOpen(false)} className="rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    {t('nav.documentaries')}
                  </Link>
                  <Link to="/search?type=animation" onClick={() => setIsMobileMenuOpen(false)} className="rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    {t('nav.animations')}
                  </Link>
                  <Link to="/search?type=series" onClick={() => setIsMobileMenuOpen(false)} className="rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    {t('nav.series')}
                  </Link>
                </>
              )}
              <div className="my-2 border-t border-white/10 pt-3">
                <p className="px-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{t('header.language')}</p>
                <div className="mt-2 grid grid-cols-3 gap-2">
                  {languages.map((lang) => (
                    <button
                      key={lang.code}
                      type="button"
                      onClick={() => {
                        setLanguage(lang.code);
                        setIsMobileMenuOpen(false);
                      }}
                      className={`rounded-lg border px-3 py-2 text-sm font-bold transition ${
                        currentLanguage.code === lang.code
                          ? 'border-white bg-white text-background'
                          : 'border-white/10 bg-white/5 text-white hover:bg-white/10'
                      }`}
                    >
                      {lang.code.toUpperCase()}
                    </button>
                  ))}
                </div>
              </div>
              {isAuthenticated ?
            <>
                <div className="my-2 border-t border-white/10" />
                {canClearStorefrontCache ? (
                  <button
                    onClick={() => {
                      void handleClearCache();
                      setIsMobileMenuOpen(false);
                    }}
                    disabled={isCacheClearing}
                    className="flex items-center gap-2 rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10 disabled:cursor-wait disabled:opacity-60"
                  >
                    <RefreshCwIcon className={`h-5 w-5 text-accentCyan ${isCacheClearing ? 'animate-spin' : ''}`} />
                    <span>{cacheMessage ?? t('admin.clear_cache')}</span>
                  </button>
                ) : null}
                <button
                  onClick={() => {
                    setIsWalletModalOpen(true);
                    setIsMobileMenuOpen(false);
                  }}
                  className="flex items-center gap-2 rounded-lg px-3 py-3 text-sm font-semibold text-accentGreen transition hover:bg-white/10">
                
                    <WalletIcon className="w-5 h-5" />
                    <span>{t('wallet.title')}: {currency} {balance.toFixed(2)}</span>
                  </button>
                <button
                  onClick={() => {
                    navigate('/dashboard?tab=favorites');
                    setIsMobileMenuOpen(false);
                  }}
                  className="flex items-center gap-2 rounded-lg px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                  <HeartIcon className="w-5 h-5 text-accent" />
                  <span>{t('dashboard.favorites')}</span>
                </button>
              </> :

            <button
              onClick={() => {
                openAuthModal();
                setIsMobileMenuOpen(false);
              }}
              className="rounded-lg px-3 py-3 text-left text-sm font-semibold text-accent transition hover:bg-white/10">
              
                  {t('auth.login_register')}
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
