import React, { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useLanguage } from '../contexts/LanguageContext';
import { getPublicMenu, PublicMenuItem, PublicMenuSummary } from '../lib/storefront';
import amexLogo from '../assets/payment/amex.png';
import maibLogo from '../assets/payment/maib.png';
import mastercardLogo from '../assets/payment/mastercard.png';
import visaLogo from '../assets/payment/visa.png';

const paymentLogos = [
  { src: maibLogo, alt: 'maib', className: 'h-6 w-auto md:h-7' },
  { src: visaLogo, alt: 'Visa', className: 'h-4 w-auto md:h-5' },
  { src: mastercardLogo, alt: 'Mastercard', className: 'h-6 w-auto md:h-7' },
  { src: amexLogo, alt: 'American Express', className: 'h-8 w-auto md:h-9' },
];
type FooterMenuNode = PublicMenuItem & { children: FooterMenuNode[] };
type FooterMenuGroup = PublicMenuSummary & { items: FooterMenuNode[] };

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
  const [footerMenus, setFooterMenus] = useState<FooterMenuGroup[]>([]);
  useEffect(() => {
    const loadMenu = async () => {
      try {
        const response = await getPublicMenu(currentLanguage.code, 'footer');
        const menus = response.menus ?? (response.menu ? [response.menu] : []);
        const menuGroups = menus
          .map((menu) => ({
            ...menu,
            items: buildFooterTree(response.items.filter((item) => item.menu_id === menu.id)),
          }))
          .filter((menu) => menu.items.length > 0);

        setFooterMenus(menuGroups);
      } catch {
        setFooterMenus([]);
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
        <div className="grid grid-cols-1 gap-8 mb-8 sm:grid-cols-[minmax(220px,1.2fr)_repeat(auto-fit,minmax(160px,1fr))]">
          <div>
            <h3 className="text-2xl font-bold tracking-tighter text-white mb-4">
              filmoteca<span className="text-accent">.</span>md
            </h3>
            <p className="text-sm text-gray-500">
              {t('footer.tagline')}
            </p>
          </div>

          {footerMenus.map((menu) => (
            <div key={menu.id}>
              <h4 className="text-white font-medium mb-4">{menu.name}</h4>
              <ul className="space-y-2 text-sm text-gray-400">
                {menu.items.map((item) => <FooterMenuItem key={item.id} item={item} />)}
              </ul>
            </div>
          ))}
        </div>

        <div className="pt-8 border-t border-white/5 flex flex-col items-center gap-5 text-xs text-gray-600 md:flex-row md:justify-between">
          <p>&copy; {new Date().getFullYear()} filmoteca.md. {t('footer.rights')}</p>
          <div
            className="flex flex-wrap items-center justify-center gap-3 rounded-md border border-white/10 bg-white/[0.03] px-4 py-3"
            aria-label="Sisteme de plată acceptate"
          >
            {paymentLogos.map((logo) => (
              <img
                key={logo.alt}
                src={logo.src}
                alt={logo.alt}
                className={`${logo.className} object-contain`}
                loading="lazy"
              />
            ))}
          </div>
          <p className="mt-4 max-w-xs text-center text-xs font-medium text-gray-500 md:mt-0 md:text-right">
            {t('footer.support_message')}
          </p>
        </div>
      </div>
    </footer>);

}
