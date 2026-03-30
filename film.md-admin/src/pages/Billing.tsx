import React from "react";
import { CreditCardIcon, DollarSignIcon, SearchIcon, ShoppingCartIcon, WalletIcon } from "lucide-react";
import { SalesTimeline } from "../components/shared/SalesTimeline";
import { StatsCard } from "../components/shared/StatsCard";
import { TransactionTypeBadge } from "../components/shared/TransactionTypeBadge";
import { Tabs } from "../components/shared/Tabs";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";
import { DashboardResponse } from "../types";

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
};

function formatCurrency(amount: number, currency = "USD") {
  return `${currency} ${amount.toFixed(2)}`;
}

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleString() : "Fără dată";
}

export function Billing() {
  const [range, setRange] = React.useState<RangeValue>("30days");
  const [dashboard, setDashboard] = React.useState<DashboardResponse>(EMPTY_DASHBOARD);
  const [searchTerm, setSearchTerm] = React.useState("");
  const [isLoading, setIsLoading] = React.useState(false);

  React.useEffect(() => {
    let cancelled = false;

    async function loadBilling() {
      setIsLoading(true);

      try {
        const response = await adminApi.getDashboard(range);
        if (!cancelled) {
          setDashboard(response);
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

    void loadBilling();

    return () => {
      cancelled = true;
    };
  }, [range]);

  const filteredTransactions = React.useMemo(() => {
    const normalizedTerm = searchTerm.trim().toLowerCase();

    if (!normalizedTerm) {
      return dashboard.recent_transactions;
    }

    return dashboard.recent_transactions.filter((transaction) =>
      [
        transaction.type_label,
        transaction.description,
        transaction.user.name,
        transaction.user.email,
        transaction.content?.title,
        transaction.content?.slug,
        transaction.offer.name,
        transaction.offer.quality,
      ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(normalizedTerm)),
    );
  }, [dashboard.recent_transactions, searchTerm]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="page-header">
          <h1 className="page-title">Facturare</h1>
          <p className="page-description">
            Istoric complet de tranzacții, mix de venituri și top titluri vândute pentru commerce.
          </p>
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
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard
          title={`Venit ${dashboard.range.label}`}
          value={formatCurrency(dashboard.stats.period_revenue_amount)}
          icon={DollarSignIcon}
          trendLabel={`${dashboard.stats.period_orders_count} comenzi`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Comandă medie"
          value={formatCurrency(dashboard.stats.average_order_value)}
          icon={CreditCardIcon}
          trendLabel={`${dashboard.stats.unique_buyers_count} cumpărători`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Venit total"
          value={formatCurrency(dashboard.stats.total_revenue_amount)}
          icon={ShoppingCartIcon}
          trendLabel={`${dashboard.stats.orders_total} comenzi totale`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Sold total wallet"
          value={formatCurrency(dashboard.stats.wallet_balance_total)}
          icon={WalletIcon}
          trendLabel={`${dashboard.summary.buyers_total} utilizatori plătitori`}
          colorClass="bg-muted"
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <Card>
          <CardHeader>
            <CardTitle>Evoluție venituri</CardTitle>
            <CardDescription>
              Activitatea de vânzări din {dashboard.range.from || "intervalul selectat"} până în {dashboard.range.to || "astăzi"}.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {dashboard.sales_timeline.length > 0 ? (
              <SalesTimeline points={dashboard.sales_timeline} metric="revenue" />
            ) : (
              <div className="flex h-64 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                {isLoading ? "Se încarcă activitatea de facturare..." : "Nu există încă activitate de facturare în acest interval."}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Mix de vânzări</CardTitle>
            <CardDescription>Cum sunt distribuite cumpărările pe tipuri de acces.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="rounded-lg border bg-background p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Închirieri</span>
                <span className="font-semibold">{dashboard.breakdown.rental_orders_count}</span>
              </div>
              <p className="mt-2 text-xl font-semibold">
                {formatCurrency(dashboard.breakdown.rental_revenue_amount)}
              </p>
            </div>
            <div className="rounded-lg border bg-background p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Permanent</span>
                <span className="font-semibold">{dashboard.breakdown.lifetime_orders_count}</span>
              </div>
              <p className="mt-2 text-xl font-semibold">
                {formatCurrency(dashboard.breakdown.lifetime_revenue_amount)}
              </p>
            </div>
            <div className="rounded-lg border bg-background p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Accesări gratuite</span>
                <span className="font-semibold">{dashboard.breakdown.free_orders_count}</span>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">
                Util pentru monitorizarea conținutului promoțional și a funnel-urilor gratuite.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <Card>
          <CardHeader className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
              <CardTitle>Istoric tranzacții</CardTitle>
              <CardDescription>
                Ultimele tranzacții din wallet pentru cumpărări, credite și viitoare refunduri.
              </CardDescription>
            </div>
            <div className="relative w-full max-w-sm">
              <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="pl-9"
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="Caută utilizator, titlu, ofertă..."
              />
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Tip</TableHead>
                  <TableHead>Utilizator</TableHead>
                  <TableHead>Conținut</TableHead>
                  <TableHead>Ofertă</TableHead>
                  <TableHead>Sumă</TableHead>
                  <TableHead>Când</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredTransactions.length > 0 ? (
                  filteredTransactions.map((transaction) => (
                    <TableRow key={transaction.id}>
                      <TableCell>
                        <TransactionTypeBadge
                          type={transaction.type}
                          amount={transaction.amount}
                          label={transaction.type_label}
                        />
                        </TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{transaction.user.name ?? "Utilizator necunoscut"}</p>
                          <p className="text-xs text-muted-foreground">{transaction.user.email ?? "Fără email"}</p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{transaction.content?.title ?? "Tranzacție platformă"}</p>
                          <p className="text-xs text-muted-foreground">{transaction.content?.slug ?? transaction.description}</p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{transaction.offer.name ?? "N/A"}</p>
                          <p className="text-xs text-muted-foreground">
                            {transaction.offer.quality ?? "Fără calitate"}
                            {transaction.offer.offer_type ? ` • ${transaction.offer.offer_type}` : ""}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p className={`font-medium ${transaction.amount < 0 ? "text-foreground" : "text-emerald-600"}`}>
                            {transaction.amount > 0 ? "+" : ""}
                            {formatCurrency(transaction.amount_absolute, transaction.currency)}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            Sold după {formatCurrency(transaction.balance_after, transaction.currency)}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">{formatDate(transaction.processed_at)}</TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={6} className="h-32 text-center text-muted-foreground">
                      {isLoading ? "Se încarcă tranzacțiile..." : "Nu există tranzacții pentru filtrele curente."}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top titluri vândute</CardTitle>
            <CardDescription>Cel mai valoros conținut din intervalul selectat.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {dashboard.top_titles.length > 0 ? (
              dashboard.top_titles.map((title, index) => (
                <div key={title.slug ?? `billing-top-${index}`} className="rounded-lg border bg-background p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-medium">{title.title}</p>
                      <p className="text-sm text-muted-foreground">
                        {title.orders_count} comenzi • {title.unique_buyers_count} cumpărători
                      </p>
                    </div>
                    <span className="text-xs text-muted-foreground">#{index + 1}</span>
                  </div>
                  <div className="mt-3 flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Venit</span>
                    <span className="font-semibold">{formatCurrency(title.revenue_amount)}</span>
                  </div>
                  <div className="mt-1 flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Accesări gratuite</span>
                    <span className="font-medium">{title.free_claims_count}</span>
                  </div>
                </div>
              ))
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                Nu există încă vânzări pe titluri.
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
