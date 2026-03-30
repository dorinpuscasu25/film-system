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
    label: "Last 30 days",
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
  return value ? new Date(value).toLocaleString() : "No timestamp";
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
          <h1 className="page-title">Billing</h1>
          <p className="page-description">
            Full transaction history, revenue mix și top-selling titles pentru commerce.
          </p>
        </div>

        <Tabs
          tabs={[
            { id: "3months", label: "Last 3 months" },
            { id: "30days", label: "Last 30 days" },
            { id: "7days", label: "Last 7 days" },
          ]}
          activeTab={range}
          onChange={(value) => setRange(value as RangeValue)}
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatsCard
          title={`Revenue ${dashboard.range.label}`}
          value={formatCurrency(dashboard.stats.period_revenue_amount)}
          icon={DollarSignIcon}
          trendLabel={`${dashboard.stats.period_orders_count} orders`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Average Order"
          value={formatCurrency(dashboard.stats.average_order_value)}
          icon={CreditCardIcon}
          trendLabel={`${dashboard.stats.unique_buyers_count} buyers`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="All-time Revenue"
          value={formatCurrency(dashboard.stats.total_revenue_amount)}
          icon={ShoppingCartIcon}
          trendLabel={`${dashboard.stats.orders_total} total orders`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Wallet Float"
          value={formatCurrency(dashboard.stats.wallet_balance_total)}
          icon={WalletIcon}
          trendLabel={`${dashboard.summary.buyers_total} paying users`}
          colorClass="bg-muted"
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <Card>
          <CardHeader>
            <CardTitle>Revenue Timeline</CardTitle>
            <CardDescription>
              Sales activity from {dashboard.range.from || "the selected range"} to {dashboard.range.to || "today"}.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {dashboard.sales_timeline.length > 0 ? (
              <SalesTimeline points={dashboard.sales_timeline} metric="revenue" />
            ) : (
              <div className="flex h-64 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                {isLoading ? "Loading billing activity..." : "No billing activity in this range yet."}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Sales Mix</CardTitle>
            <CardDescription>How purchases are distributed by access type.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="rounded-lg border bg-background p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Rentals</span>
                <span className="font-semibold">{dashboard.breakdown.rental_orders_count}</span>
              </div>
              <p className="mt-2 text-xl font-semibold">
                {formatCurrency(dashboard.breakdown.rental_revenue_amount)}
              </p>
            </div>
            <div className="rounded-lg border bg-background p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Lifetime</span>
                <span className="font-semibold">{dashboard.breakdown.lifetime_orders_count}</span>
              </div>
              <p className="mt-2 text-xl font-semibold">
                {formatCurrency(dashboard.breakdown.lifetime_revenue_amount)}
              </p>
            </div>
            <div className="rounded-lg border bg-background p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Free claims</span>
                <span className="font-semibold">{dashboard.breakdown.free_orders_count}</span>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">
                Useful for monitoring promotional content and free funnels.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <Card>
          <CardHeader className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
              <CardTitle>Transaction History</CardTitle>
              <CardDescription>
                Latest wallet transactions across purchases, credits and future refunds.
              </CardDescription>
            </div>
            <div className="relative w-full max-w-sm">
              <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="pl-9"
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="Search user, title, offer..."
              />
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>User</TableHead>
                  <TableHead>Content</TableHead>
                  <TableHead>Offer</TableHead>
                  <TableHead>Amount</TableHead>
                  <TableHead>When</TableHead>
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
                          <p className="font-medium">{transaction.user.name ?? "Unknown user"}</p>
                          <p className="text-xs text-muted-foreground">{transaction.user.email ?? "No email"}</p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{transaction.content?.title ?? "Platform transaction"}</p>
                          <p className="text-xs text-muted-foreground">{transaction.content?.slug ?? transaction.description}</p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{transaction.offer.name ?? "N/A"}</p>
                          <p className="text-xs text-muted-foreground">
                            {transaction.offer.quality ?? "No quality"}
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
                            Balance after {formatCurrency(transaction.balance_after, transaction.currency)}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">{formatDate(transaction.processed_at)}</TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={6} className="h-32 text-center text-muted-foreground">
                      {isLoading ? "Loading transactions..." : "No transactions found for the current filters."}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top Selling Titles</CardTitle>
            <CardDescription>Most valuable content in the selected time window.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {dashboard.top_titles.length > 0 ? (
              dashboard.top_titles.map((title, index) => (
                <div key={title.slug ?? `billing-top-${index}`} className="rounded-lg border bg-background p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-medium">{title.title}</p>
                      <p className="text-sm text-muted-foreground">
                        {title.orders_count} orders • {title.unique_buyers_count} buyers
                      </p>
                    </div>
                    <span className="text-xs text-muted-foreground">#{index + 1}</span>
                  </div>
                  <div className="mt-3 flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Revenue</span>
                    <span className="font-semibold">{formatCurrency(title.revenue_amount)}</span>
                  </div>
                  <div className="mt-1 flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Free claims</span>
                    <span className="font-medium">{title.free_claims_count}</span>
                  </div>
                </div>
              ))
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                No title sales yet.
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
