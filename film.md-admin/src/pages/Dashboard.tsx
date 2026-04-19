import React from "react";
import {
  AlertCircleIcon,
  CreditCardIcon,
  DollarSignIcon,
  FilmIcon,
  PlusIcon,
  ShoppingCartIcon,
  UsersIcon,
  WalletIcon,
} from "lucide-react";
import { StatsCard } from "../components/shared/StatsCard";
import { SalesTimeline } from "../components/shared/SalesTimeline";
import { TransactionTypeBadge } from "../components/shared/TransactionTypeBadge";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Tabs } from "../components/shared/Tabs";
import { adminApi } from "../lib/api";
import { DashboardResponse } from "../types";
import { useAdmin } from "../hooks/useAdmin";

type RangeValue = "7days" | "30days" | "3months";

const EMPTY_DASHBOARD: DashboardResponse = {
  range: {
    value: "30days",
    label: "Ultimele 30 de zile",
    days: 30,
    from: "",
    to: "",
  },
  stats: {
    users_total: 0,
    admins_total: 0,
    roles_total: 0,
    pending_invitations: 0,
    total_revenue_amount: 0,
    period_revenue_amount: 0,
    orders_total: 0,
    period_orders_count: 0,
    paid_orders_count: 0,
    free_claims_count: 0,
    unique_buyers_count: 0,
    average_order_value: 0,
    active_entitlements_count: 0,
    wallet_balance_total: 0,
    total_views: 0,
    total_watch_time_seconds: 0,
    total_bandwidth_gb: 0,
    current_month_costs_usd: 0,
    current_month_profit_usd: 0,
  },
  breakdown: {
    rental_orders_count: 0,
    lifetime_orders_count: 0,
    free_orders_count: 0,
    rental_revenue_amount: 0,
    lifetime_revenue_amount: 0,
  },
  sales_timeline: [],
  recent_transactions: [],
  recent_sales: [],
  top_titles: [],
  summary: {
    catalog_titles_total: 0,
    published_titles_total: 0,
    buyers_total: 0,
  },
  analytics_timeline: [],
  country_breakdown: [],
  cost_overview: {
    storage_cost_usd: 0,
    delivery_cost_usd: 0,
    drm_cost_usd: 0,
    revenue_usd: 0,
    profit_usd: 0,
  },
};

function safeNumber(value: number | null | undefined) {
  return Number.isFinite(value) ? Number(value) : 0;
}

function normalizeDashboard(response: Partial<DashboardResponse> | null | undefined): DashboardResponse {
  return {
    ...EMPTY_DASHBOARD,
    ...response,
    range: {
      ...EMPTY_DASHBOARD.range,
      ...(response?.range ?? {}),
    },
    stats: {
      ...EMPTY_DASHBOARD.stats,
      ...(response?.stats ?? {}),
    },
    breakdown: {
      ...EMPTY_DASHBOARD.breakdown,
      ...(response?.breakdown ?? {}),
    },
    summary: {
      ...EMPTY_DASHBOARD.summary,
      ...(response?.summary ?? {}),
    },
    cost_overview: {
      ...EMPTY_DASHBOARD.cost_overview,
      ...(response?.cost_overview ?? {}),
    },
    sales_timeline: Array.isArray(response?.sales_timeline) ? response.sales_timeline : EMPTY_DASHBOARD.sales_timeline,
    recent_transactions: Array.isArray(response?.recent_transactions) ? response.recent_transactions : EMPTY_DASHBOARD.recent_transactions,
    recent_sales: Array.isArray(response?.recent_sales) ? response.recent_sales : EMPTY_DASHBOARD.recent_sales,
    top_titles: Array.isArray(response?.top_titles) ? response.top_titles : EMPTY_DASHBOARD.top_titles,
    analytics_timeline: Array.isArray(response?.analytics_timeline) ? response.analytics_timeline : EMPTY_DASHBOARD.analytics_timeline,
    country_breakdown: Array.isArray(response?.country_breakdown) ? response.country_breakdown : EMPTY_DASHBOARD.country_breakdown,
  };
}

function formatCurrency(amount: number | null | undefined, currency = "USD") {
  return `${currency} ${safeNumber(amount).toFixed(2)}`;
}

function formatDecimal(value: number | null | undefined, digits = 2) {
  return safeNumber(value).toFixed(digits);
}

function formatDate(value: string | null) {
  if (!value) {
    return "Fără dată";
  }

  return new Date(value).toLocaleString();
}

function formatDuration(seconds: number | null | undefined) {
  const normalizedSeconds = Math.max(0, safeNumber(seconds));
  const hours = Math.floor(normalizedSeconds / 3600);
  const minutes = Math.floor((normalizedSeconds % 3600) / 60);

  if (hours <= 0) {
    return `${minutes}m`;
  }

  return `${hours}h ${minutes}m`;
}

export function Dashboard() {
  const { navigate } = useAdmin();
  const [range, setRange] = React.useState<RangeValue>("30days");
  const [dashboard, setDashboard] = React.useState<DashboardResponse>(EMPTY_DASHBOARD);
  const [isLoading, setIsLoading] = React.useState(false);

  React.useEffect(() => {
    let cancelled = false;

    async function loadDashboard() {
      setIsLoading(true);

      try {
        const response = await adminApi.getDashboard(range);
        if (!cancelled) {
          setDashboard(normalizeDashboard(response));
        }
      } catch {
        if (!cancelled) {
          setDashboard(EMPTY_DASHBOARD);
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    void loadDashboard();

    return () => {
      cancelled = true;
    };
  }, [range]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="page-header">
          <h1 className="page-title">Panou</h1>
          <p className="page-description">
            Venituri, istoric de tranzacții și performanța catalogului pentru intervalul selectat.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" onClick={() => navigate("billing", null, ["Facturare"])}>
            Facturare
          </Button>
          <Button onClick={() => navigate("editor", "new", ["Catalog", "Adaugă titlu"])}>
            <PlusIcon className="mr-2 h-4 w-4" />
            Adăugare rapidă
          </Button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard
          title={`Venit ${dashboard.range.label}`}
          value={formatCurrency(dashboard.stats.period_revenue_amount)}
          icon={DollarSignIcon}
          trendLabel={`${dashboard.stats.paid_orders_count} comenzi plătite`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Comenzi"
          value={dashboard.stats.period_orders_count}
          icon={ShoppingCartIcon}
          trendLabel={`${dashboard.stats.free_claims_count} accesări gratuite`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Cumpărători unici"
          value={dashboard.stats.unique_buyers_count}
          icon={UsersIcon}
          trendLabel={`${formatCurrency(dashboard.stats.average_order_value)} medie per comandă`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Expunere sold wallet"
          value={formatCurrency(dashboard.stats.wallet_balance_total)}
          icon={WalletIcon}
          trendLabel={`${dashboard.stats.active_entitlements_count} accesări active`}
          colorClass="bg-muted"
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard
          title="Views tracking"
          value={dashboard.stats.total_views}
          icon={FilmIcon}
          trendLabel={`${formatDuration(dashboard.stats.total_watch_time_seconds)} watch time`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Bandwidth"
          value={`${formatDecimal(dashboard.stats.total_bandwidth_gb)} GB`}
          icon={WalletIcon}
          trendLabel="Trafic agregat din analytics"
          colorClass="bg-muted"
        />
        <StatsCard
          title="Costuri lună curentă"
          value={formatCurrency(dashboard.stats.current_month_costs_usd)}
          icon={DollarSignIcon}
          trendLabel={`${formatCurrency(dashboard.cost_overview.delivery_cost_usd)} delivery`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Profit lună curentă"
          value={formatCurrency(dashboard.stats.current_month_profit_usd)}
          icon={DollarSignIcon}
          trendLabel={`${formatCurrency(dashboard.cost_overview.storage_cost_usd)} storage`}
          colorClass="bg-muted"
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard
          title="Titluri publicate"
          value={dashboard.summary.published_titles_total}
          icon={FilmIcon}
          trendLabel={`${dashboard.summary.catalog_titles_total} în catalog`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Total cumpărători"
          value={dashboard.summary.buyers_total}
          icon={CreditCardIcon}
          trendLabel={`${formatCurrency(dashboard.stats.total_revenue_amount)} total`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Conturi admin"
          value={dashboard.stats.admins_total}
          icon={UsersIcon}
          trendLabel={`${dashboard.stats.roles_total} roluri configurate`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Invitații în așteptare"
          value={dashboard.stats.pending_invitations}
          icon={AlertCircleIcon}
          trendLabel={`${dashboard.stats.users_total} utilizatori total`}
          colorClass="bg-muted"
        />
      </div>

      <Card>
        <CardHeader className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div>
            <CardTitle>Performanță vânzări</CardTitle>
            <CardDescription>
              Comenzi și venituri din {dashboard.range.from || "intervalul selectat"} până în {dashboard.range.to || "astăzi"}.
            </CardDescription>
          </div>
          <Tabs
            tabs={[
              { id: "3months", label: "Ultimele 3 luni" },
              { id: "30days", label: "Ultimele 30 de zile" },
              { id: "7days", label: "Ultimele 7 zile" },
            ]}
            activeTab={range}
            onChange={(value) => setRange(value as RangeValue)}
          />
        </CardHeader>
        <CardContent className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
          <div className="rounded-lg border bg-background p-4">
            {dashboard.sales_timeline.length > 0 ? (
              <SalesTimeline points={dashboard.sales_timeline} metric="revenue" />
            ) : (
              <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                {isLoading ? "Se încarcă evoluția vânzărilor..." : "Nu există încă tranzacții în acest interval."}
              </div>
            )}
          </div>

          <div className="space-y-4">
            <div className="rounded-lg border bg-background p-4">
              <p className="text-sm text-muted-foreground">Venit din închirieri</p>
              <p className="mt-2 text-2xl font-semibold">
                {formatCurrency(dashboard.breakdown.rental_revenue_amount)}
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                {dashboard.breakdown.rental_orders_count} comenzi de închiriere
              </p>
            </div>

            <div className="rounded-lg border bg-background p-4">
              <p className="text-sm text-muted-foreground">Venit din acces permanent</p>
              <p className="mt-2 text-2xl font-semibold">
                {formatCurrency(dashboard.breakdown.lifetime_revenue_amount)}
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                {dashboard.breakdown.lifetime_orders_count} cumpărări permanente
              </p>
            </div>

            <div className="rounded-lg border bg-background p-4">
              <p className="text-sm text-muted-foreground">Accesări gratuite</p>
              <p className="mt-2 text-2xl font-semibold">{dashboard.breakdown.free_orders_count}</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Titluri deblocate prin acces gratuit sau oferte cu preț zero
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <Card>
          <CardHeader>
            <CardTitle>Tranzacții recente</CardTitle>
            <CardDescription>Ultimele evenimente de wallet și cumpărare din storefront.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {dashboard.recent_sales.length > 0 ? (
              dashboard.recent_sales.map((transaction) => (
                <div key={transaction.id} className="flex flex-col gap-3 rounded-lg border bg-background px-4 py-3 md:flex-row md:items-center md:justify-between">
                  <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <TransactionTypeBadge
                        type={transaction.type}
                        amount={transaction.amount}
                        label={transaction.type_label}
                      />
                      <span className="text-sm font-medium">{transaction.user.name ?? "Utilizator necunoscut"}</span>
                    </div>
                    <p className="truncate text-sm text-muted-foreground">
                      {transaction.content?.title ?? transaction.description ?? "Tranzacție platformă"}
                      {transaction.offer.name ? ` • ${transaction.offer.name}` : ""}
                    </p>
                    <p className="text-xs text-muted-foreground">{formatDate(transaction.processed_at)}</p>
                  </div>
                  <div className="text-right">
                    <div className={`text-sm font-semibold ${transaction.amount < 0 ? "text-foreground" : "text-emerald-600"}`}>
                      {transaction.amount > 0 ? "+" : ""}
                      {formatCurrency(transaction.amount_absolute, transaction.currency)}
                    </div>
                    <p className="text-xs text-muted-foreground">
                      Sold după {formatCurrency(transaction.balance_after, transaction.currency)}
                    </p>
                  </div>
                </div>
              ))
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                Nu există cumpărări înregistrate în acest interval.
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top titluri</CardTitle>
            <CardDescription>Conținutul cu cea mai bună performanță în intervalul selectat.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {dashboard.top_titles.length > 0 ? (
              dashboard.top_titles.map((title, index) => (
                <div key={title.slug ?? `title-${index}`} className="flex items-center gap-3 rounded-lg border bg-background p-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-md border bg-muted text-sm font-semibold">
                    #{index + 1}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{title.title}</p>
                    <p className="text-sm text-muted-foreground">
                      {title.orders_count} comenzi • {title.unique_buyers_count} cumpărători
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="font-semibold">{formatCurrency(title.revenue_amount)}</p>
                    <p className="text-xs text-muted-foreground">{title.free_claims_count} gratuite</p>
                  </div>
                </div>
              ))
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                Nu există încă date de performanță pentru titluri.
              </div>
            )}

            <div className="rounded-lg border bg-background p-4">
              <p className="text-sm text-muted-foreground">Ai nevoie de mai mult detaliu?</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Deschide facturarea ca să vezi istoricul complet de tranzacții și cele mai noi activități comerciale.
              </p>
              <Button variant="outline" className="mt-4 w-full" onClick={() => navigate("billing", null, ["Facturare"])}>
                Deschide facturarea
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <Card>
          <CardHeader>
            <CardTitle>Country analytics</CardTitle>
            <CardDescription>Top țări după views și trafic agregat din analytics.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {dashboard.country_breakdown.length === 0 ? (
              <div className="text-sm text-muted-foreground">Nu există încă date agregate pe țări.</div>
            ) : dashboard.country_breakdown.map((row) => (
              <div key={row.country_code} className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <div className="font-medium">{row.country_code}</div>
                  <div className="text-sm text-muted-foreground">{row.views} views</div>
                </div>
                <div className="text-right text-sm text-muted-foreground">
                  <div>{formatDuration(row.watch_time_seconds)}</div>
                  <div>{formatDecimal(row.bandwidth_gb)} GB</div>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Cost overview</CardTitle>
            <CardDescription>Snapshot pentru luna curentă pe storage, delivery, DRM și profit.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center justify-between text-sm"><span>Storage</span><span>{formatCurrency(dashboard.cost_overview.storage_cost_usd)}</span></div>
            <div className="flex items-center justify-between text-sm"><span>Delivery</span><span>{formatCurrency(dashboard.cost_overview.delivery_cost_usd)}</span></div>
            <div className="flex items-center justify-between text-sm"><span>DRM</span><span>{formatCurrency(dashboard.cost_overview.drm_cost_usd)}</span></div>
            <div className="flex items-center justify-between text-sm"><span>Revenue</span><span>{formatCurrency(dashboard.cost_overview.revenue_usd)}</span></div>
            <div className="flex items-center justify-between text-sm font-semibold"><span>Profit</span><span>{formatCurrency(dashboard.cost_overview.profit_usd)}</span></div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
