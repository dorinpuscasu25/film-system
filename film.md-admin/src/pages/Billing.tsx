import React from "react";
import {
  CreditCardIcon,
  DollarSignIcon,
  DownloadIcon,
  SearchIcon,
  ShoppingCartIcon,
  WalletIcon,
} from "lucide-react";
import { SalesTimeline } from "../components/shared/SalesTimeline";
import { StatsCard } from "../components/shared/StatsCard";
import { Tabs } from "../components/shared/Tabs";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";
import { CostSettingsResponse, DashboardResponse, ExportJobsResponse } from "../types";
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

const EMPTY_COSTS: CostSettingsResponse = {
  current: null,
  versions: [],
  monthly_costs: [],
  creator_statements: [],
  creators: [],
};

const EMPTY_EXPORTS: ExportJobsResponse = {
  items: [],
};

function formatCurrency(amount: number, currency = "USD") {
  return `${currency} ${amount.toFixed(2)}`;
}

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleString() : "Fără dată";
}

function numberInputValue(value: number | null | undefined) {
  return Number.isFinite(value) ? String(value) : "";
}

export function Billing() {
  const { can } = useAdmin();
  const canManageExports = can("exports.manage");
  const canManageCosts = can("commerce.manage_costs");
  const [range, setRange] = React.useState<RangeValue>("30days");
  const [dashboard, setDashboard] = React.useState<DashboardResponse>(EMPTY_DASHBOARD);
  const [costs, setCosts] = React.useState<CostSettingsResponse>(EMPTY_COSTS);
  const [exportsData, setExportsData] = React.useState<ExportJobsResponse>(EMPTY_EXPORTS);
  const [searchTerm, setSearchTerm] = React.useState("");
  const [isLoading, setIsLoading] = React.useState(false);
  const [isCostLoading, setIsCostLoading] = React.useState(false);
  const [isSavingCosts, setIsSavingCosts] = React.useState(false);
  const [exportingScope, setExportingScope] = React.useState<string | null>(null);
  const [downloadingExportId, setDownloadingExportId] = React.useState<number | null>(null);
  const [costForm, setCostForm] = React.useState({
    storage_cost_per_gb_day: "",
    delivery_cost_per_gb: "",
    drm_cost_per_license: "",
    usd_to_mdl_rate: "",
  });

  const loadBillingDashboard = React.useCallback(async (selectedRange: RangeValue, cancelledRef?: { current: boolean }) => {
    setIsLoading(true);

    try {
      const response = await adminApi.getDashboard(selectedRange);
      if (!cancelledRef?.current) {
        setDashboard(response);
      }
    } catch {
      if (!cancelledRef?.current) {
        setDashboard(EMPTY_DASHBOARD);
      }
    } finally {
      if (!cancelledRef?.current) {
        setIsLoading(false);
      }
    }
  }, []);

  const loadOperationalBilling = React.useCallback(async () => {
    setIsCostLoading(true);

    try {
      const [costResponse, exportResponse] = await Promise.all([
        adminApi.getCostSettings(),
        adminApi.getExports(),
      ]);

      setCosts(costResponse);
      setExportsData(exportResponse);
    } catch {
      setCosts(EMPTY_COSTS);
      setExportsData(EMPTY_EXPORTS);
    } finally {
      setIsCostLoading(false);
    }
  }, []);

  React.useEffect(() => {
    const cancelled = { current: false };
    void loadBillingDashboard(range, cancelled);

    return () => {
      cancelled.current = true;
    };
  }, [loadBillingDashboard, range]);

  React.useEffect(() => {
    void loadOperationalBilling();
  }, [loadOperationalBilling]);

  React.useEffect(() => {
    setCostForm({
      storage_cost_per_gb_day: numberInputValue(costs.current?.storage_cost_per_gb_day),
      delivery_cost_per_gb: numberInputValue(costs.current?.delivery_cost_per_gb),
      drm_cost_per_license: numberInputValue(costs.current?.drm_cost_per_license),
      usd_to_mdl_rate: numberInputValue(costs.current?.usd_to_mdl_rate),
    });
  }, [costs.current]);

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

  const handleCostSave = async () => {
    setIsSavingCosts(true);

    try {
      await adminApi.saveCostSettings({
        storage_cost_per_gb_day: Number(costForm.storage_cost_per_gb_day || 0),
        delivery_cost_per_gb: Number(costForm.delivery_cost_per_gb || 0),
        drm_cost_per_license: Number(costForm.drm_cost_per_license || 0),
        usd_to_mdl_rate: Number(costForm.usd_to_mdl_rate || 0),
      });

      await loadOperationalBilling();
    } finally {
      setIsSavingCosts(false);
    }
  };

  const handleExport = async (format: "excel" | "pdf" | "json", scope: string) => {
    const exportKey = `${format}:${scope}`;
    setExportingScope(exportKey);

    try {
      await adminApi.createExportJob({
        format,
        scope,
        filters: {
          range,
        },
      });

      await loadOperationalBilling();
    } finally {
      setExportingScope(null);
    }
  };

  const handleExportDownload = async (jobId: number, fileName?: string | null) => {
    setDownloadingExportId(jobId);

    try {
      await adminApi.downloadExportJob(jobId, fileName);
    } finally {
      setDownloadingExportId(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="page-header">
          <h1 className="page-title">Facturare</h1>
          <p className="page-description">
            Commerce, cost engine, exporturi și situațiile creatorilor într-un singur loc.
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
          title="Costuri luna curentă"
          value={formatCurrency(dashboard.stats.current_month_costs_usd)}
          icon={ShoppingCartIcon}
          trendLabel={`${costs.monthly_costs.length} linii cost`}
          colorClass="bg-muted"
        />
        <StatsCard
          title="Profit luna curentă"
          value={formatCurrency(dashboard.stats.current_month_profit_usd)}
          icon={WalletIcon}
          trendLabel={`${costs.creator_statements.length} creatori în raport`}
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
            <CardTitle>Motor costuri</CardTitle>
            <CardDescription>Versiunea activă a prețurilor pentru storage, delivery, DRM și curs.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="rounded-lg border bg-background p-4">
              <p className="text-sm text-muted-foreground">Versiune activă din</p>
              <p className="mt-1 font-semibold">{formatDate(costs.current?.effective_from ?? null)}</p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <label className="space-y-2 text-sm">
                <span className="text-muted-foreground">Storage / GB / zi</span>
                <Input
                  type="number"
                  step="0.0001"
                  value={costForm.storage_cost_per_gb_day}
                  disabled={!canManageCosts}
                  onChange={(event) =>
                    setCostForm((current) => ({ ...current, storage_cost_per_gb_day: event.target.value }))
                  }
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="text-muted-foreground">Delivery / GB</span>
                <Input
                  type="number"
                  step="0.0001"
                  value={costForm.delivery_cost_per_gb}
                  disabled={!canManageCosts}
                  onChange={(event) =>
                    setCostForm((current) => ({ ...current, delivery_cost_per_gb: event.target.value }))
                  }
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="text-muted-foreground">DRM / licență</span>
                <Input
                  type="number"
                  step="0.0001"
                  value={costForm.drm_cost_per_license}
                  disabled={!canManageCosts}
                  onChange={(event) =>
                    setCostForm((current) => ({ ...current, drm_cost_per_license: event.target.value }))
                  }
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="text-muted-foreground">Curs USD → MDL</span>
                <Input
                  type="number"
                  step="0.0001"
                  value={costForm.usd_to_mdl_rate}
                  disabled={!canManageCosts}
                  onChange={(event) =>
                    setCostForm((current) => ({ ...current, usd_to_mdl_rate: event.target.value }))
                  }
                />
              </label>
            </div>

            <div className="flex flex-wrap gap-3">
              {canManageCosts ? (
                <Button onClick={() => void handleCostSave()} disabled={isSavingCosts}>
                  {isSavingCosts ? "Se salvează..." : "Publică versiune nouă"}
                </Button>
              ) : null}
              <Button variant="outline" onClick={() => void loadOperationalBilling()} disabled={isCostLoading}>
                Reîmprospătează
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <Card>
          <CardHeader className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
              <CardTitle>Istoric tranzacții</CardTitle>
              <CardDescription>
                Ultimele tranzacții din wallet pentru cumpărări și activitatea operațională vizibilă în platformă.
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
                      <TableCell className="font-medium">{transaction.type_label}</TableCell>
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
            <CardTitle>Exporturi</CardTitle>
            <CardDescription>Pornește exporturi operaționale fără să ieși din zona de billing.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {canManageExports ? (
              <>
                <Button
                  className="w-full justify-start"
                  variant="outline"
                  onClick={() => void handleExport("excel", "billing")}
                  disabled={exportingScope === "excel:billing"}
                >
                  <DownloadIcon className="h-4 w-4" />
                  {exportingScope === "excel:billing" ? "Se pune în coadă..." : "Export Excel billing"}
                </Button>
                <Button
                  className="w-full justify-start"
                  variant="outline"
                  onClick={() => void handleExport("pdf", "creator-statements")}
                  disabled={exportingScope === "pdf:creator-statements"}
                >
                  <DownloadIcon className="h-4 w-4" />
                  {exportingScope === "pdf:creator-statements" ? "Se pune în coadă..." : "Export PDF creatori"}
                </Button>
                <Button
                  className="w-full justify-start"
                  variant="outline"
                  onClick={() => void handleExport("json", "full-platform")}
                  disabled={exportingScope === "json:full-platform"}
                >
                  <DownloadIcon className="h-4 w-4" />
                  {exportingScope === "json:full-platform" ? "Se pune în coadă..." : "Full data export"}
                </Button>
              </>
            ) : (
              <div className="rounded-lg border bg-background p-4 text-sm text-muted-foreground">
                Rolul curent are acces doar la vizualizarea rapoartelor, fără inițiere de exporturi.
              </div>
            )}

            <div className="rounded-lg border bg-background p-4">
              <p className="text-sm text-muted-foreground">Joburi recente</p>
              <p className="mt-1 text-2xl font-semibold">{exportsData.items.length}</p>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <Card>
          <CardHeader>
            <CardTitle>Costuri lunare per film</CardTitle>
            <CardDescription>Storage, delivery, DRM și profit estimate pe luna activă.</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Film</TableHead>
                  <TableHead>Format</TableHead>
                  <TableHead>Storage</TableHead>
                  <TableHead>Delivery</TableHead>
                  <TableHead>DRM</TableHead>
                  <TableHead>Profit</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {costs.monthly_costs.length > 0 ? (
                  costs.monthly_costs.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>
                        <div className="space-y-1">
                          <p className="font-medium">{row.content_title ?? "Titlu lipsă"}</p>
                          <p className="text-xs text-muted-foreground">{row.month}</p>
                        </div>
                      </TableCell>
                      <TableCell>{row.quality ?? "N/A"}</TableCell>
                      <TableCell>{formatCurrency(row.storage_cost_usd)}</TableCell>
                      <TableCell>{formatCurrency(row.delivery_cost_usd)}</TableCell>
                      <TableCell>{formatCurrency(row.drm_cost_usd)}</TableCell>
                      <TableCell className={row.profit_usd >= 0 ? "text-emerald-600" : "text-rose-600"}>
                        {formatCurrency(row.profit_usd)}
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={6} className="h-28 text-center text-muted-foreground">
                      {isCostLoading ? "Se încarcă costurile..." : "Nu există încă agregări de cost pentru luna curentă."}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Situații creatori</CardTitle>
            <CardDescription>Payout estimat și profit pe creator pentru luna curentă.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {costs.creator_statements.length > 0 ? (
              costs.creator_statements.map((statement) => (
                <div key={statement.id} className="rounded-lg border bg-background p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-medium">{statement.creator_name ?? "Creator necunoscut"}</p>
                      <p className="text-sm text-muted-foreground">{statement.month}</p>
                    </div>
                    <span className={`text-sm font-semibold ${statement.profit_usd >= 0 ? "text-emerald-600" : "text-rose-600"}`}>
                      {formatCurrency(statement.profit_usd)}
                    </span>
                  </div>
                  <div className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    <div className="rounded-md border p-3">
                      <p className="text-muted-foreground">Revenue</p>
                      <p className="mt-1 font-medium">{formatCurrency(statement.revenue_usd)}</p>
                    </div>
                    <div className="rounded-md border p-3">
                      <p className="text-muted-foreground">Payout</p>
                      <p className="mt-1 font-medium">{formatCurrency(statement.payout_usd)}</p>
                    </div>
                  </div>
                </div>
              ))
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border bg-background text-sm text-muted-foreground">
                Nu există încă statements pentru creatori.
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <Card>
          <CardHeader>
            <CardTitle>Istoric joburi export</CardTitle>
            <CardDescription>Queue-ul de export pentru admin, creatori și dump complet de platformă.</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Format</TableHead>
                  <TableHead>Scope</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Solicitat de</TableHead>
                  <TableHead>Când</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {exportsData.items.length > 0 ? (
                  exportsData.items.map((job) => (
                    <TableRow key={job.id}>
                      <TableCell className="uppercase">{job.format}</TableCell>
                      <TableCell>{job.scope}</TableCell>
                      <TableCell>
                        <div className="space-y-1">
                          <p>{job.status}</p>
                          {job.error_message ? (
                            <p className="text-xs text-rose-600">{job.error_message}</p>
                          ) : null}
                        </div>
                      </TableCell>
                      <TableCell>{job.requested_by ?? "Sistem"}</TableCell>
                      <TableCell>
                        <div className="space-y-2">
                          <p>{formatDate(job.created_at)}</p>
                          {job.status === "completed" && job.file_path ? (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => void handleExportDownload(job.id, job.file_name)}
                              disabled={downloadingExportId === job.id}
                            >
                              <DownloadIcon className="h-4 w-4" />
                              {downloadingExportId === job.id ? "Se descarcă..." : "Descarcă"}
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={5} className="h-28 text-center text-muted-foreground">
                      {isCostLoading ? "Se încarcă exporturile..." : "Nu există încă joburi de export."}
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
