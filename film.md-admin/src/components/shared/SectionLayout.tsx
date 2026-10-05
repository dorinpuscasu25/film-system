import { useSearchParams } from "react-router-dom";
import type { LucideIcon } from "lucide-react";

export interface SectionTab {
  id: string;
  label: string;
  description?: string;
  icon?: LucideIcon;
  /** Hidden when false — used for permission gating. */
  show?: boolean;
}

interface SectionLayoutProps {
  title: string;
  description?: string;
  tabs: SectionTab[];
  /** Heading shown above the sub-navigation, e.g. "Prețuri". */
  navLabel?: string;
  children: (activeTab: string) => React.ReactNode;
}

/**
 * Two-pane section: sub-navigation on the left, one focused panel on the right.
 *
 * Large admin screens had grown into single scrolling pages stacking unrelated
 * settings, which made them hard to scan and hard to extend. Splitting a section
 * into named sub-pages keeps each panel to one job.
 *
 * The active panel lives in the `?tab=` query parameter so a panel can be linked
 * to directly and the browser back button behaves as expected.
 */
export function SectionLayout({
  title,
  description,
  tabs,
  navLabel,
  children,
}: SectionLayoutProps) {
  const [searchParams, setSearchParams] = useSearchParams();
  const visibleTabs = tabs.filter((tab) => tab.show !== false);

  const requested = searchParams.get("tab");
  const activeTab = visibleTabs.some((tab) => tab.id === requested)
    ? (requested as string)
    : visibleTabs[0]?.id ?? "";

  const selectTab = (id: string) => {
    const next = new URLSearchParams(searchParams);
    next.set("tab", id);
    setSearchParams(next, { replace: true });
  };

  return (
    <div className="space-y-6">
      <div className="page-header">
        <h1 className="page-title">{title}</h1>
        {description && <p className="page-description">{description}</p>}
      </div>

      <div className="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        <nav aria-label={navLabel ?? title} className="lg:border-r lg:border-border lg:pr-6">
          {navLabel && (
            <p className="mb-3 px-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {navLabel}
            </p>
          )}

          <ul className="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
            {visibleTabs.map((tab) => {
              const Icon = tab.icon;
              const isActive = tab.id === activeTab;

              return (
                <li key={tab.id} className="shrink-0 lg:shrink">
                  <button
                    type="button"
                    onClick={() => selectTab(tab.id)}
                    aria-current={isActive ? "page" : undefined}
                    className={`flex w-full items-center gap-2.5 whitespace-nowrap rounded-lg px-3 py-2.5 text-left text-sm transition-colors ${
                      isActive
                        ? "bg-primary/10 font-medium text-primary"
                        : "text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                    }`}
                  >
                    {Icon && <Icon className="h-4 w-4 shrink-0" />}
                    <span className="truncate">{tab.label}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="min-w-0">{children(activeTab)}</div>
      </div>
    </div>
  );
}
