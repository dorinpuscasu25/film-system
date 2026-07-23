import React from "react";
import {
  BarChart3Icon,
  Clock3Icon,
  DollarSignIcon,
  EyeIcon,
  FilmIcon,
  FilterIcon,
  Globe2Icon,
  RotateCcwIcon,
  ShoppingCartIcon,
  UsersIcon,
} from "lucide-react";
import { Tabs } from "../components/shared/Tabs";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import {
  AnalyticsContentPerformance,
  AnalyticsFilters,
  AnalyticsResponse,
  AnalyticsTimelinePoint,
} from "../types";

type DashboardTab = "overview" | "content" | "audience";
type TimelineMetric = "revenue" | "sales" | "views" | "sessions";

const SELECT_CLASS =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring";

function localDate(daysAgo = 0) {
  const date = new Date();
  date.setDate(date.getDate() - daysAgo);
  return date.toISOString().slice(0, 10);
}

function defaultFilters(): AnalyticsFilters {
  return {
    from: localDate(29),
    to: localDate(),
    group_by: "day",
  };
}

function formatCurrency(amount: number, currency = "MDL") {
  return new Intl.NumberFormat("ro-MD", {
    style: "currency",
    currency,
    maximumFractionDigits: 2,
  }).format(Number.isFinite(amount) ? amount : 0);
}

function formatNumber(value: number) {
  return new Intl.NumberFormat("ro-MD").format(Number.isFinite(value) ? value : 0);
}

function formatDuration(seconds: number) {
  const normalized = Math.max(0, Number.isFinite(seconds) ? seconds : 0);
  const hours = Math.floor(normalized / 3600);
  const minutes = Math.floor((normalized % 3600) / 60);

  return hours > 0 ? `${formatNumber(hours)}h ${minutes}m` : `${minutes}m`;
}

function countryFlag(code: string) {
  if (!/^[A-Z]{2}$/.test(code)) {
    return "🌐";
  }

  return String.fromCodePoint(...code.split("").map((letter) => 127397 + letter.charCodeAt(0)));
}

function quickRange(days: number): AnalyticsFilters {
  return {
    from: localDate(days - 1),
    to: localDate(),
    group_by: days > 62 ? "month" : "day",
  };
}

export function Dashboard() {
  const { navigate, can, currentUser } = useAdmin();
  const [activeTab, setActiveTab] = React.useState<DashboardTab>("overview");
  const [draftFilters, setDraftFilters] = React.useState<AnalyticsFilters>(defaultFilters);
  const [appliedFilters, setAppliedFilters] = React.useState<AnalyticsFilters>(defaultFilters);
  const [analytics, setAnalytics] = React.useState<AnalyticsResponse | null>(null);
  const [isLoading, setIsLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setError(null);

    adminApi
      .getAnalytics(appliedFilters)
      .then((response) => {
        if (!cancelled) {
          setAnalytics(response);
        }
      })
      .catch((requestError) => {
        if (!cancelled) {
          setError(requestError instanceof Error ? requestError.message : "Statisticile nu au putut fi încărcate.");
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [appliedFilters]);

  const applyQuickRange = (days: number) => {
    const next = { ...draftFilters, ...quickRange(days) };
    setDraftFilters(next);
    setAppliedFilters(next);
  };

  const resetFilters = () => {
    const next = defaultFilters();
    setDraftFilters(next);
    setAppliedFilters(next);
  };

  const options = analytics?.filters.options;
  const currency = analytics?.currency ?? "MDL";
  const isScoped = analytics?.scope.is_content_scoped ?? currentUser?.content_scope_assigned ?? false;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Statistici</h1>
          <p className="page-description">
            Vânzări, vizionări, audiență și venituri analizate separat, pentru perioada și conținutul ales.
          </p>
          {isScoped ? (
            <p className="mt-2 inline-flex rounded-full border border-sky-500/30 bg-sky-500/10 px-3 py-1 text-xs font-medium text-sky-700 dark:text-sky-300">
              Vezi exclusiv datele filmelor care ți-au fost atribuite.
            </p>
          ) : null}
        </div>

        {can("commerce.view_billing") ? (
          <Button variant="outline" onClick={() => navigate("billing", null, ["Facturare"])}>
            <DollarSignIcon className="h-4 w-4" />
            Deschide finanțele
          </Button>
        ) : null}
      </div>

      <Tabs
        tabs={[
          { id: "overview", label: "Prezentare generală", icon: BarChart3Icon },
          { id: "content", label: "Performanță filme", icon: FilmIcon },
          { id: "audience", label: "Audiență și țări", icon: Globe2Icon },
        ]}
        activeTab={activeTab}
        onChange={(value) => setActiveTab(value as DashboardTab)}
      />

      <AnalyticsFiltersCard
        filters={draftFilters}
        options={options}
        loading={isLoading}
        onChange={setDraftFilters}
        onApply={() => setAppliedFilters({ ...draftFilters })}
        onReset={resetFilters}
        onQuickRange={applyQuickRange}
      />

      {error ? (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300">
          {error}
        </div>
      ) : null}

      {activeTab === "overview" ? (
        <OverviewTab analytics={analytics} loading={isLoading} currency={currency} />
      ) : null}

      {activeTab === "content" ? (
        <ContentPerformanceTab analytics={analytics} loading={isLoading} currency={currency} />
      ) : null}

      {activeTab === "audience" ? (
        <AudienceTab analytics={analytics} loading={isLoading} currency={currency} />
      ) : null}
    </div>
  );
}

function AnalyticsFiltersCard({
  filters,
  options,
  loading,
  onChange,
  onApply,
  onReset,
  onQuickRange,
}: {
  filters: AnalyticsFilters;
  options: AnalyticsResponse["filters"]["options"] | undefined;
  loading: boolean;
  onChange: React.Dispatch<React.SetStateAction<AnalyticsFilters>>;
  onApply: () => void;
  onReset: () => void;
  onQuickRange: (days: number) => void;
}) {
  return (
    <Card>
      <CardHeader className="pb-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <CardTitle className="flex items-center gap-2 text-lg">
              <FilterIcon className="h-4 w-4" />
              Filtre analiză
            </CardTitle>
            <CardDescription>
              Toți indicatorii și toate taburile folosesc simultan filtrele de mai jos.
            </CardDescription>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={() => onQuickRange(7)}>
              7 zile
            </Button>
            <Button size="sm" variant="outline" onClick={() => onQuickRange(30)}>
              30 zile
            </Button>
            <Button size="sm" variant="outline" onClick={() => onQuickRange(90)}>
              3 luni
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <FilterField label="De la">
            <Input
              type="date"
              value={filters.from ?? ""}
              onChange={(event) => onChange((current) => ({ ...current, from: event.target.value }))}
            />
          </FilterField>
          <FilterField label="Până la">
            <Input
              type="date"
              value={filters.to ?? ""}
              onChange={(event) => onChange((current) => ({ ...current, to: event.target.value }))}
            />
          </FilterField>
          <FilterField label="Film">
            <select
              className={SELECT_CLASS}
              value={filters.content_id ?? ""}
              onChange={(event) =>
                onChange((current) => ({
                  ...current,
                  content_id: event.target.value ? Number(event.target.value) : null,
                }))
              }
            >
              <option value="">Toate filmele</option>
              {(options?.contents ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </FilterField>
          <FilterField label="Gen">
            <select
              className={SELECT_CLASS}
              value={filters.genre_id ?? ""}
              onChange={(event) =>
                onChange((current) => ({
                  ...current,
                  genre_id: event.target.value ? Number(event.target.value) : null,
                }))
              }
            >
              <option value="">Toate genurile</option>
              {(options?.genres ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </FilterField>
          <FilterField label="Țară">
            <select
              className={SELECT_CLASS}
              value={filters.country_code ?? ""}
              onChange={(event) => onChange((current) => ({ ...current, country_code: event.target.value }))}
            >
              <option value="">Toate țările</option>
              {(options?.countries ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </FilterField>
          <FilterField label="Regiune de facturare">
            <select
              className={SELECT_CLASS}
              value={filters.administrative_area ?? ""}
              onChange={(event) =>
                onChange((current) => ({ ...current, administrative_area: event.target.value }))
              }
            >
              <option value="">Toate regiunile</option>
              {(options?.regions ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </FilterField>
          <FilterField label="Grupare grafic">
            <select
              className={SELECT_CLASS}
              value={filters.group_by ?? "day"}
              onChange={(event) =>
                onChange((current) => ({ ...current, group_by: event.target.value as "day" | "month" }))
              }
            >
              <option value="day">Pe zile</option>
              <option value="month">Pe luni</option>
            </select>
          </FilterField>
        </div>

        <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
          <Button variant="ghost" onClick={onReset}>
            <RotateCcwIcon className="h-4 w-4" />
            Resetează
          </Button>
          <Button onClick={onApply} disabled={loading}>
            <FilterIcon className="h-4 w-4" />
            {loading ? "Se aplică..." : "Aplică filtrele"}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function FilterField({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="space-y-2 text-sm">
      <span className="font-medium text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}

function OverviewTab({
  analytics,
  loading,
  currency,
}: {
  analytics: AnalyticsResponse | null;
  loading: boolean;
  currency: string;
}) {
  const stats = analytics?.stats;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          label="Venit din vânzări"
          value={formatCurrency(stats?.revenue_amount ?? 0, currency)}
          detail={`${formatNumber(stats?.sales_count ?? 0)} vânzări plătite`}
          icon={DollarSignIcon}
          tone="positive"
        />
        <MetricCard
          label="Vizionări"
          value={formatNumber(stats?.views_count ?? 0)}
          detail={`${formatNumber(stats?.sessions_count ?? 0)} sesiuni pornite`}
          icon={EyeIcon}
          tone="info"
        />
        <MetricCard
          label="Timp vizionat"
          value={formatDuration(stats?.watch_time_seconds ?? 0)}
          detail={`${formatNumber(stats?.unique_viewers_count ?? 0)} spectatori unici`}
          icon={Clock3Icon}
          tone="warning"
        />
        <MetricCard
          label="Comenzi"
          value={formatNumber(stats?.orders_count ?? 0)}
          detail={`${formatNumber(stats?.free_claims_count ?? 0)} accesări gratuite`}
          icon={ShoppingCartIcon}
          tone="neutral"
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <Card>
          <CardHeader>
            <CardTitle>Evoluție în timp</CardTitle>
            <CardDescription>
              {analytics
                ? `${analytics.range.from} — ${analytics.range.to}, grupare pe ${
                    analytics.range.group_by === "month" ? "luni" : "zile"
                  }.`
                : "Venituri, vânzări și consum pentru intervalul selectat."}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <PerformanceTimeline
              points={analytics?.timeline ?? []}
              currency={currency}
              loading={loading}
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top filme</CardTitle>
            <CardDescription>Clasare după venit, apoi după vizionări.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {(analytics?.content_performance ?? []).slice(0, 5).map((content, index) => (
              <div key={content.content_id} className="rounded-lg border bg-background p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{content.title}</p>
                    <p className="text-xs text-muted-foreground">{content.genres.join(", ") || "Fără gen"}</p>
                  </div>
                  <span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold">#{index + 1}</span>
                </div>
                <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                  <div>
                    <p className="text-xs text-muted-foreground">Venit</p>
                    <p className="font-semibold text-emerald-600">
                      +{formatCurrency(content.revenue_amount, currency)}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Vizionări</p>
                    <p className="font-semibold text-sky-600">{formatNumber(content.views_count)}</p>
                  </div>
                </div>
              </div>
            ))}
            {!loading && (analytics?.content_performance.length ?? 0) === 0 ? (
              <EmptyState label="Nu există date pentru filtrele selectate." />
            ) : null}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function ContentPerformanceTab({
  analytics,
  loading,
  currency,
}: {
  analytics: AnalyticsResponse | null;
  loading: boolean;
  currency: string;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Performanță pe film</CardTitle>
        <CardDescription>
          Compară direct câte vânzări, vizionări și minute de consum a produs fiecare titlu.
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Film</TableHead>
                <TableHead className="text-right">Vânzări</TableHead>
                <TableHead className="text-right">Venit</TableHead>
                <TableHead className="text-right">Vizionări</TableHead>
                <TableHead className="text-right">Sesiuni</TableHead>
                <TableHead className="text-right">Spectatori</TableHead>
                <TableHead className="text-right">Timp vizionat</TableHead>
                <TableHead className="text-right">Trafic</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {(analytics?.content_performance ?? []).map((content) => (
                <ContentPerformanceRow key={content.content_id} content={content} currency={currency} />
              ))}
              {(analytics?.content_performance.length ?? 0) === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="h-32 text-center text-muted-foreground">
                    {loading ? "Se încarcă performanța filmelor..." : "Nu există rezultate pentru filtrele curente."}
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>
  );
}

function ContentPerformanceRow({
  content,
  currency,
}: {
  content: AnalyticsContentPerformance;
  currency: string;
}) {
  return (
    <TableRow>
      <TableCell>
        <div className="max-w-[280px]">
          <p className="truncate font-medium">{content.title}</p>
          <p className="truncate text-xs text-muted-foreground">
            {content.genres.join(", ") || "Fără gen"} · {content.type}
          </p>
        </div>
      </TableCell>
      <TableCell className="text-right font-medium">{formatNumber(content.sales_count)}</TableCell>
      <TableCell className="text-right font-semibold text-emerald-600">
        +{formatCurrency(content.revenue_amount, currency)}
      </TableCell>
      <TableCell className="text-right font-medium text-sky-600">{formatNumber(content.views_count)}</TableCell>
      <TableCell className="text-right">{formatNumber(content.sessions_count)}</TableCell>
      <TableCell className="text-right">{formatNumber(content.unique_viewers_count)}</TableCell>
      <TableCell className="text-right">{formatDuration(content.watch_time_seconds)}</TableCell>
      <TableCell className="text-right">{content.bandwidth_gb.toFixed(2)} GB</TableCell>
    </TableRow>
  );
}

function AudienceTab({
  analytics,
  loading,
  currency,
}: {
  analytics: AnalyticsResponse | null;
  loading: boolean;
  currency: string;
}) {
  const countries = analytics?.country_breakdown ?? [];
  const totalViews = Math.max(1, countries.reduce((sum, row) => sum + row.views_count, 0));

  return (
    <div className="space-y-6">
      <div className="grid gap-4 md:grid-cols-3">
        <MetricCard
          label="Țări active"
          value={formatNumber(countries.length)}
          detail="Cu vizionări sau cumpărări în interval"
          icon={Globe2Icon}
          tone="info"
        />
        <MetricCard
          label="Spectatori unici"
          value={formatNumber(analytics?.stats.unique_viewers_count ?? 0)}
          detail={`${formatNumber(analytics?.stats.sessions_count ?? 0)} sesiuni`}
          icon={UsersIcon}
          tone="neutral"
        />
        <MetricCard
          label="Trafic video"
          value={`${(analytics?.stats.bandwidth_gb ?? 0).toFixed(2)} GB`}
          detail={formatDuration(analytics?.stats.watch_time_seconds ?? 0)}
          icon={EyeIcon}
          tone="warning"
        />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Distribuție geografică</CardTitle>
          <CardDescription>
            Vizionările folosesc țara sesiunii video; venitul folosește țara din adresa de facturare.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Țară</TableHead>
                  <TableHead className="text-right">Vizionări</TableHead>
                  <TableHead className="text-right">Pondere</TableHead>
                  <TableHead className="text-right">Timp vizionat</TableHead>
                  <TableHead className="text-right">Vânzări</TableHead>
                  <TableHead className="text-right">Venit</TableHead>
                  <TableHead className="w-[24%]">Distribuție</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {countries.map((country) => {
                  const share = (country.views_count / totalViews) * 100;

                  return (
                    <TableRow key={country.country_code}>
                      <TableCell>
                        <span className="mr-2 text-lg">{countryFlag(country.country_code)}</span>
                        <span className="font-medium">{country.country_code}</span>
                      </TableCell>
                      <TableCell className="text-right font-medium text-sky-600">
                        {formatNumber(country.views_count)}
                      </TableCell>
                      <TableCell className="text-right">{share.toFixed(1)}%</TableCell>
                      <TableCell className="text-right">{formatDuration(country.watch_time_seconds)}</TableCell>
                      <TableCell className="text-right">{formatNumber(country.sales_count)}</TableCell>
                      <TableCell className="text-right font-semibold text-emerald-600">
                        +{formatCurrency(country.revenue_amount, currency)}
                      </TableCell>
                      <TableCell>
                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                          <div
                            className="h-full rounded-full bg-sky-500"
                            style={{ width: `${Math.min(100, share)}%` }}
                          />
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
                {countries.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="h-32 text-center text-muted-foreground">
                      {loading ? "Se încarcă audiența..." : "Nu există date geografice pentru filtrele curente."}
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function MetricCard({
  label,
  value,
  detail,
  icon: Icon,
  tone,
}: {
  label: string;
  value: string;
  detail: string;
  icon: React.ElementType;
  tone: "positive" | "info" | "warning" | "neutral";
}) {
  const classes = {
    positive: "border-emerald-500/30 bg-emerald-500/5 text-emerald-600 dark:text-emerald-400",
    info: "border-sky-500/30 bg-sky-500/5 text-sky-600 dark:text-sky-400",
    warning: "border-amber-500/30 bg-amber-500/5 text-amber-600 dark:text-amber-400",
    neutral: "border-border bg-card text-foreground",
  }[tone];

  return (
    <Card className={classes}>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
        <div>
          <CardDescription>{label}</CardDescription>
          <CardTitle className="mt-2 text-2xl">{value}</CardTitle>
        </div>
        <div className="rounded-lg border border-current/20 bg-background/70 p-2">
          <Icon className="h-5 w-5" />
        </div>
      </CardHeader>
      <CardContent>
        <p className="text-xs text-muted-foreground">{detail}</p>
      </CardContent>
    </Card>
  );
}

function PerformanceTimeline({
  points,
  currency,
  loading,
}: {
  points: AnalyticsTimelinePoint[];
  currency: string;
  loading: boolean;
}) {
  const [metric, setMetric] = React.useState<TimelineMetric>("revenue");
  const valueFor = (point: AnalyticsTimelinePoint) => {
    if (metric === "revenue") return point.revenue_amount;
    if (metric === "sales") return point.sales_count;
    if (metric === "views") return point.views_count;
    return point.sessions_count;
  };
  const maxValue = Math.max(1, ...points.map(valueFor));
  const minWidth = Math.max(600, points.length * 34);

  if (points.length === 0) {
    return <EmptyState label={loading ? "Se încarcă evoluția..." : "Nu există date pentru intervalul selectat."} />;
  }

  return (
    <div className="space-y-5">
      <Tabs
        tabs={[
          { id: "revenue", label: "Venit" },
          { id: "sales", label: "Vânzări" },
          { id: "views", label: "Vizionări" },
          { id: "sessions", label: "Sesiuni" },
        ]}
        activeTab={metric}
        onChange={(value) => setMetric(value as TimelineMetric)}
      />

      <div className="overflow-x-auto pb-2">
        <div
          className="grid h-72 items-end gap-2"
          style={{
            minWidth,
            gridTemplateColumns: `repeat(${points.length}, minmax(22px, 1fr))`,
          }}
        >
          {points.map((point) => {
            const value = valueFor(point);
            const height = Math.max(2, (value / maxValue) * 100);
            const displayValue =
              metric === "revenue" ? formatCurrency(value, currency) : formatNumber(value);

            return (
              <div key={point.period} className="group flex h-full min-w-0 flex-col justify-end gap-2">
                <div className="invisible rounded-md border bg-popover px-2 py-1 text-center text-[11px] shadow-sm group-hover:visible">
                  <p className="font-semibold">{displayValue}</p>
                  <p className="text-muted-foreground">{point.label}</p>
                </div>
                <div className="flex flex-1 items-end">
                  <div
                    className={`w-full rounded-t-sm ${
                      metric === "revenue"
                        ? "bg-emerald-500"
                        : metric === "views"
                          ? "bg-sky-500"
                          : metric === "sales"
                            ? "bg-violet-500"
                            : "bg-amber-500"
                    }`}
                    style={{ height: `${height}%` }}
                  />
                </div>
                <p className="truncate text-center text-[10px] text-muted-foreground">{point.label}</p>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function EmptyState({ label }: { label: string }) {
  return (
    <div className="flex h-48 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
      {label}
    </div>
  );
}
