import React, { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useLanguage } from '../contexts/LanguageContext';
import { getPublicMenu, PublicMenuItem } from '../lib/storefront';

const footerButtonClass = 'hover:text-white transition-colors';
type FooterMenuNode = PublicMenuItem & { children: FooterMenuNode[] };

function buildFooterTree(items: PublicMenuItem[]): FooterMenuNode[] {
  const nodes = new Map<number, FooterMenuNode>();
  const roots: FooterMenuNode[] = [];
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
  const sortNodes = (nodesToSort: FooterMenuNode[]) => {
    nodesToSort.sort((a, b) => a.sort_order - b.sort_order);
    nodesToSort.forEach((node) => sortNodes(node.children));
  };
  sortNodes(roots);
  return roots;
}

function FooterMenuLink({ item }: { item: PublicMenuItem }) {
  const isExternal = item.resolved_url.startsWith('http') || item.target === '_blank';

  if (isExternal) {
    return (
      <a href={item.resolved_url} target={item.target === '_blank' ? '_blank' : undefined} rel="noreferrer" className="hover:text-white transition-colors">
        {item.label}
      </a>
    );
  }

  return (
    <Link to={item.resolved_url} className="hover:text-white transition-colors">
      {item.label}
    </Link>
  );
}

function FooterMenuItem({ item }: { item: FooterMenuNode }) {
  return (
    <li>
      <FooterMenuLink item={item} />
      {item.children.length > 0 ? (
        <ul className="mt-2 space-y-2 border-l border-white/10 pl-3">
          {item.children.map((child) => (
            <FooterMenuItem key={child.id} item={child} />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

export function Footer() {
  const location = useLocation();
  const { t, currentLanguage } = useLanguage();
  const [footerItems, setFooterItems] = useState<FooterMenuNode[]>([]);
  useEffect(() => {
    const loadMenu = async () => {
      try {
        const response = await getPublicMenu(currentLanguage.code, 'footer');
        setFooterItems(buildFooterTree(response.items));
      } catch {
        setFooterItems([]);
      }
    };

    void loadMenu();
  }, [currentLanguage.code]);
  // Hide footer on player page and auth/profile pages
  if (
  location.pathname.startsWith('/watch') ||
  location.pathname === '/auth' ||
  location.pathname === '/profiles')
  {
    return null;
  }
  return (
    <footer className="bg-background border-t border-white/5 py-12 mt-20">
      <div className="container mx-auto px-4 md:px-8">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-8 mb-8">
          <div>
            <h3 className="text-2xl font-bold tracking-tighter text-white mb-4">
              filmoteca<span className="text-accent">.</span>md
            </h3>
            <p className="text-sm text-gray-500">
              {t('footer.tagline')}
            </p>
          </div>

          <div>
            <h4 className="text-white font-medium mb-4">{t('footer.navigation')}</h4>
            <ul className="space-y-2 text-sm text-gray-400">
              {footerItems.length > 0 ? (
                footerItems.map((item) => <FooterMenuItem key={item.id} item={item} />)
              ) : (
                <>
                  <li>
                    <Link to="/" className="hover:text-white transition-colors">
                      {t('nav.home')}
                    </Link>
                  </li>
                  <li>
                    <Link to="/search?type=movie" className="hover:text-white transition-colors">
                      {t('nav.movies')}
                    </Link>
                  </li>
                  <li>
                    <Link to="/search?type=series" className="hover:text-white transition-colors">
                      {t('nav.series')}
                    </Link>
                  </li>
                  <li>
                    <Link to="/search" className="hover:text-white transition-colors">
                      {t('nav.trending')}
                    </Link>
                  </li>
                </>
              )}
            </ul>
          </div>

          <div>
            <h4 className="text-white font-medium mb-4">{t('footer.support')}</h4>
            <ul className="space-y-2 text-sm text-gray-400">
              <li>
                <button type="button" className={footerButtonClass}>
                  FAQ
                </button>
              </li>
              <li>
                <button type="button" className={footerButtonClass}>
                  {t('footer.contact')}
                </button>
              </li>
              <li>
                <button type="button" className={footerButtonClass}>
                  {t('footer.wallet_help')}
                </button>
              </li>
            </ul>
          </div>

          <div>
            <h4 className="text-white font-medium mb-4">{t('footer.legal')}</h4>
            <ul className="space-y-2 text-sm text-gray-400">
              <li>
                <button type="button" className={footerButtonClass}>
                  {t('footer.terms')}
                </button>
              </li>
              <li>
                <button type="button" className={footerButtonClass}>
                  {t('footer.privacy')}
                </button>
              </li>
              <li>
                <button type="button" className={footerButtonClass}>
                  {t('footer.cookies')}
                </button>
              </li>
            </ul>
          </div>
        </div>

        <div className="pt-8 border-t border-white/5 flex flex-col md:flex-row items-center justify-between text-xs text-gray-600">
          <p>&copy; {new Date().getFullYear()} filmoteca.md. {t('footer.rights')}</p>
          <div className="flex space-x-4 mt-4 md:mt-0">
            <span>English</span>
            <span>Română</span>
            <span>Русский</span>
          </div>
        </div>
      </div>
    </footer>);

}
