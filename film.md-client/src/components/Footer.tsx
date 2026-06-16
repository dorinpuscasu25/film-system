import React, { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ChevronDownIcon } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { getPublicMenu, PublicMenuItem, PublicMenuSummary } from '../lib/storefront';
import amexLogo from '../assets/payment/amex.png';
import maibLogo from '../assets/payment/maib.png';
import mastercardLogo from '../assets/payment/mastercard.png';
import visaLogo from '../assets/payment/visa.png';

const paymentLogos = [
  { src: maibLogo, alt: 'maib', className: 'h-5 w-auto md:h-6' },
  { src: visaLogo, alt: 'Visa', className: 'h-3.5 w-auto md:h-4' },
  { src: mastercardLogo, alt: 'Mastercard', className: 'h-5 w-auto md:h-6' },
  { src: amexLogo, alt: 'American Express', className: 'h-6 w-auto md:h-7' },
];
const languageFooterLabels = new Set(['english', 'romana', 'română', 'русский']);
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

function withoutLanguageLinks(items: FooterMenuNode[]): FooterMenuNode[] {
  return items
    .filter((item) => !languageFooterLabels.has(item.label.trim().toLowerCase()))
    .map((item) => ({
      ...item,
      children: withoutLanguageLinks(item.children),
    }));
}

function FooterMenuLink({ item }: { item: PublicMenuItem }) {
  const isExternal = item.resolved_url.startsWith('http') || item.target === '_blank';

  if (isExternal) {
    return (
      <a href={item.resolved_url} target={item.target === '_blank' ? '_blank' : undefined} rel="noreferrer" className="transition-colors hover:text-white">
        {item.label}
      </a>
    );
  }

  return (
    <Link to={item.resolved_url} className="transition-colors hover:text-white">
      {item.label}
    </Link>
  );
}

function FooterMenuItem({ item }: { item: FooterMenuNode }) {
  return (
    <li>
      <FooterMenuLink item={item} />
      {item.children.length > 0 ? (
        <ul className="mt-1.5 space-y-1.5 border-l border-white/10 pl-3">
          {item.children.map((child) => (
            <FooterMenuItem key={child.id} item={child} />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

function FooterMenuAccordion({ menu, isOpen, onToggle }: { menu: FooterMenuGroup; isOpen: boolean; onToggle: () => void }) {
  return (
    <section className="border-t border-white/5 pt-3 md:border-t-0 md:pt-0">
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full items-center justify-between gap-3 text-left text-xs font-semibold uppercase tracking-wide text-white/80 transition hover:text-white"
        aria-expanded={isOpen}
      >
        <span>{menu.name}</span>
        <ChevronDownIcon className={`h-4 w-4 shrink-0 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
      </button>
      <div className={`relative mt-2 overflow-hidden transition-[max-height] duration-300 ${isOpen ? 'max-h-80' : 'max-h-[58px]'}`}>
        <ul className="space-y-1.5 text-xs leading-5 text-gray-500">
          {menu.items.map((item) => <FooterMenuItem key={item.id} item={item} />)}
        </ul>
        {!isOpen ? (
          <div className="pointer-events-none absolute inset-x-0 bottom-0 h-8 bg-gradient-to-b from-background/0 to-background" />
        ) : null}
      </div>
    </section>
  );
}

export function Footer() {
  const location = useLocation();
  const { t, currentLanguage } = useLanguage();
  const [footerMenus, setFooterMenus] = useState<FooterMenuGroup[]>([]);
  const [openMenuIds, setOpenMenuIds] = useState<Set<number>>(new Set());
  useEffect(() => {
    const loadMenu = async () => {
      try {
        const response = await getPublicMenu(currentLanguage.code, 'footer');
        const menus = response.menus ?? (response.menu ? [response.menu] : []);
        const menuGroups = menus
          .map((menu) => ({
            ...menu,
            items: withoutLanguageLinks(buildFooterTree(response.items.filter((item) => item.menu_id === menu.id))),
          }))
          .filter((menu) => menu.items.length > 0);

        setFooterMenus(menuGroups);
        setOpenMenuIds(new Set());
      } catch {
        setFooterMenus([]);
        setOpenMenuIds(new Set());
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

  const toggleFooterMenu = (menuId: number) => {
    setOpenMenuIds((current) => {
      const next = new Set(current);
      if (next.has(menuId)) {
        next.delete(menuId);
      } else {
        next.add(menuId);
      }
      return next;
    });
  };

  return (
    <footer className="mt-12 border-t border-white/5 bg-background py-6">
      <div className="container mx-auto px-4 md:px-8">
        <div className="mb-5 grid grid-cols-1 gap-5 md:grid-cols-[minmax(220px,1fr)_minmax(220px,1.4fr)] md:items-start">
          <div>
            <h3 className="mb-2 text-xl font-bold tracking-tighter text-white">
              filmoteca<span className="text-accent">.</span>md
            </h3>
            <p className="max-w-md text-xs leading-5 text-gray-600">
              {t('footer.tagline')}
            </p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            {footerMenus.map((menu) => (
              <FooterMenuAccordion
                key={menu.id}
                menu={menu}
                isOpen={openMenuIds.has(menu.id)}
                onToggle={() => toggleFooterMenu(menu.id)}
              />
            ))}
          </div>
        </div>

        <div className="flex flex-col items-center gap-4 border-t border-white/5 pt-5 text-[11px] text-gray-600 md:flex-row md:justify-between">
          <p>&copy; {new Date().getFullYear()} filmoteca.md. {t('footer.rights')}</p>
          <div
            className="flex flex-wrap items-center justify-center gap-2 rounded-md border border-white/10 bg-white/[0.03] px-3 py-2"
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
          <p className="max-w-xs text-center text-[11px] font-medium text-gray-500 md:text-right">
            {t('footer.support_message')}
          </p>
        </div>
      </div>
    </footer>);

}
