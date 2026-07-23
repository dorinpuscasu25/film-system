import React from "react";
import {
  ArrowDownLeftIcon,
  ArrowUpRightIcon,
  BarChart3Icon,
  CheckIcon,
  CircleAlertIcon,
  CircleCheckIcon,
  ClipboardIcon,
  CreditCardIcon,
  DownloadIcon,
  FileSpreadsheetIcon,
  FilterIcon,
  LandmarkIcon,
  LoaderCircleIcon,
  ReceiptTextIcon,
  RotateCcwIcon,
  SearchIcon,
  Settings2Icon,
  WalletCardsIcon,
  XIcon,
} from "lucide-react";
import { Modal } from "../components/shared/Modal";
import { SalesTimeline } from "../components/shared/SalesTimeline";
import { Tabs } from "../components/shared/Tabs";
import { TransactionTypeBadge } from "../components/shared/TransactionTypeBadge";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { Textarea } from "../components/ui/textarea";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import {
  AccountingFilters,
  AccountingTransactionItem,
  AccountingTransactionsResponse,
  CostSettingsResponse,
  DashboardResponse,
  ExportJobsResponse,
  PaymentTopUpItem,
  PaymentTopUpsResponse,
} from "../types";

type BillingTab = "overview" | "accounting" | "wallet" | "payments" | "costs" | "exports";
type RangeValue = "7days" | "30days" | "3months";
type ExportProgress = {
  key: string;
  status: "preparing" | "downloading" | "completed" | "error";
  title: string;
  detail: string;
};

const SELECT_CLASS =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring";

const EMPTY_COSTS: CostSettingsResponse = {
  current: null,
  versions: [],
  monthly_costs: [],
  creator_statements: [],
  creators: [],
};

const EMPTY_EXPORTS: ExportJobsResponse = { items: [] };
const EMPTY_TOP_UPS: PaymentTopUpsResponse = { items: [] };

function localDate(daysAgo = 0) {
  const date = new Date();
  date.setDate(date.getDate() - daysAgo);
  return date.toISOString().slice(0, 10);
}

function initialAccountingFilters(): AccountingFilters {
  return {
    from: localDate(29),
    to: localDate(),
    market: "all",
    direction: "all",
    status: "accounted",
    page: 1,
    per_page: 100,
  };
}

function formatCurrency(amount: number | null | undefined, currency = "MDL") {
  return new Intl.NumberFormat("ro-MD", {
    style: "currency",
    currency,
    maximumFractionDigits: 2,
  }).format(Number.isFinite(amount) ? Number(amount) : 0);
}

function formatSignedCurrency(amount: number, currency = "MDL") {
  const sign = amount > 0 ? "+" : amount < 0 ? "−" : "";
  return `${sign}${formatCurrency(Math.abs(amount), currency)}`;
}

function formatDate(value: string | null | undefined) {
  return value ? new Date(value).toLocaleString("ro-MD") : "Fără dată";
}

function numberInputValue(value: number | null | undefined) {
  return Number.isFinite(value) ? String(value) : "";
}

function billingAddressLabel(topUp: PaymentTopUpItem) {
  const billing = topUp.billing_address;

  if (!billing) return "Fără adresă";

  return [billing.city, billing.administrative_area, billing.country_code]
    .filter(Boolean)
    .join(", ") || "Fără adresă";
}

export function Billing() {
  const { can } = useAdmin();
  const canManageExports = can("exports.manage");
  const canManageCosts = can("commerce.manage_costs");
  const canProcessRefunds = can("commerce.process_refunds");
  const [activeTab, setActiveTab] = React.useState<BillingTab>("overview");
  const [range, setRange] = React.useState<RangeValue>("30days");
  const [dashboard, setDashboard] = React.useState<DashboardResponse | null>(null);
  const [costs, setCosts] = React.useState<CostSettingsResponse>(EMPTY_COSTS);
  const [exportsData, setExportsData] = React.useState<ExportJobsResponse>(EMPTY_EXPORTS);
  const [paymentTopUps, setPaymentTopUps] = React.useState<PaymentTopUpsResponse>(EMPTY_TOP_UPS);
  const [accountingDraft, setAccountingDraft] = React.useState<AccountingFilters>(initialAccountingFilters);
  const [accountingFilters, setAccountingFilters] = React.useState<AccountingFilters>(initialAccountingFilters);
  const [accounting, setAccounting] = React.useState<AccountingTransactionsResponse | null>(null);
  const [walletSearch, setWalletSearch] = React.useState("");
  const [walletType, setWalletType] = React.useState("all");
  const [walletDirection, setWalletDirection] = React.useState("all");
  const [paymentSearch, setPaymentSearch] = React.useState("");
  const [isDashboardLoading, setIsDashboardLoading] = React.useState(true);
  const [isOperationalLoading, setIsOperationalLoading] = React.useState(true);
  const [isAccountingLoading, setIsAccountingLoading] = React.useState(true);
  const [isSavingCosts, setIsSavingCosts] = React.useState(false);
  const [exportingKey, setExportingKey] = React.useState<string | null>(null);
  const [downloadingExportId, setDownloadingExportId] = React.useState<number | null>(null);
  const [exportProgress, setExportProgress] = React.useState<ExportProgress | null>(null);
  const [selectedTopUp, setSelectedTopUp] = React.useState<PaymentTopUpItem | null>(null);
  const [copiedCheckoutId, setCopiedCheckoutId] = React.useState<string | null>(null);
  const [refundForm, setRefundForm] = React.useState({ amount: "", reason: "" });
  const [refundError, setRefundError] = React.useState<string | null>(null);
  const [isRefunding, setIsRefunding] = React.useState(false);
  const [costForm, setCostForm] = React.useState({
    storage_cost_per_gb_day: "",
    delivery_cost_per_gb: "",
    drm_cost_per_license: "",
    usd_to_mdl_rate: "",
  });

  const loadDashboard = React.useCallback(async (selectedRange: RangeValue) => {
    setIsDashboardLoading(true);
    try {
      setDashboard(await adminApi.getDashboard(selectedRange));
    } catch {
      setDashboard(null);
    } finally {
      setIsDashboardLoading(false);
    }
  }, []);

  const loadOperational = React.useCallback(async () => {
    setIsOperationalLoading(true);
    try {
      const [costResponse, exportResponse, paymentResponse] = await Promise.all([
        adminApi.getCostSettings(),
        adminApi.getExports(),
        adminApi.getPaymentTopUps(),
      ]);
      setCosts(costResponse);
      setExportsData(exportResponse);
      setPaymentTopUps(paymentResponse);
    } catch {
      setCosts(EMPTY_COSTS);
      setExportsData(EMPTY_EXPORTS);
      setPaymentTopUps(EMPTY_TOP_UPS);
    } finally {
      setIsOperationalLoading(false);
    }
  }, []);

  const loadAccounting = React.useCallback(async (filters: AccountingFilters) => {
    setIsAccountingLoading(true);
    try {
      setAccounting(await adminApi.getAccountingTransactions(filters));
    } catch {
      setAccounting(null);
    } finally {
      setIsAccountingLoading(false);
    }
  }, []);

  React.useEffect(() => {
    void loadDashboard(range);
  }, [loadDashboard, range]);

  React.useEffect(() => {
    void loadOperational();
  }, [loadOperational]);

  React.useEffect(() => {
    void loadAccounting(accountingFilters);
  }, [accountingFilters, loadAccounting]);

  React.useEffect(() => {
    setCostForm({
      storage_cost_per_gb_day: numberInputValue(costs.current?.storage_cost_per_gb_day),
      delivery_cost_per_gb: numberInputValue(costs.current?.delivery_cost_per_gb),
      drm_cost_per_license: numberInputValue(costs.current?.drm_cost_per_license),
      usd_to_mdl_rate: numberInputValue(costs.current?.usd_to_mdl_rate),
    });
  }, [costs]);

  const filteredWalletTransactions = React.useMemo(() => {
    const search = walletSearch.trim().toLowerCase();
    return (dashboard?.recent_transactions ?? []).filter((transaction) => {
      const matchesSearch =
        search === "" ||
        [
          transaction.type_label,
          transaction.description,
          transaction.user.name,
          transaction.user.email,
          transaction.content?.title,
          transaction.content?.slug,
          transaction.offer.name,
        ]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(search));
      const matchesType = walletType === "all" || transaction.type === walletType;
      const matchesDirection =
        walletDirection === "all" ||
        (walletDirection === "credit" ? transaction.amount > 0 : transaction.amount < 0);

      return matchesSearch && matchesType && matchesDirection;
    });
  }, [dashboard?.recent_transactions, walletDirection, walletSearch, walletType]);

  const filteredTopUps = React.useMemo(() => {
    const search = paymentSearch.trim().toLowerCase();
    if (!search) return paymentTopUps.items;

    return paymentTopUps.items.filter((topUp) =>
      [
        topUp.id,
        topUp.status,
        topUp.provider_order_id,
        topUp.provider_checkout_id,
        topUp.provider_rrn,
        topUp.user.name,
        topUp.user.email,
        topUp.billing_address?.country_code,
        topUp.billing_address?.city,
      ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(search)),
    );
  }, [paymentSearch, paymentTopUps.items]);

  const saveCosts = async () => {
    setIsSavingCosts(true);
    try {
      await adminApi.saveCostSettings({
        storage_cost_per_gb_day: Number(costForm.storage_cost_per_gb_day || 0),
        delivery_cost_per_gb: Number(costForm.delivery_cost_per_gb || 0),
        drm_cost_per_license: Number(costForm.drm_cost_per_license || 0),
        usd_to_mdl_rate: Number(costForm.usd_to_mdl_rate || 0),
      });
      await loadOperational();
    } finally {
      setIsSavingCosts(false);
    }
  };

  const createExport = async (
    format: "excel" | "pdf" | "json",
    scope: string,
    filters: Record<string, unknown> = {},
  ) => {
    const key = `${format}:${scope}:${JSON.stringify(filters)}`;
    const reportName =
      scope === "accounting"
        ? "exportul contabil"
        : scope === "creator-statements"
          ? "raportul producătorilor"
          : "exportul operațional";

    setExportingKey(key);
    setExportProgress({
      key,
      status: "preparing",
      title: "Se pregătește fișierul",
      detail: `Generăm ${reportName}. Poți continua să lucrezi în pagină.`,
    });

    try {
      const response = await adminApi.createExportJob({ format, scope, filters });
      const job = response.job;

      if (job.status !== "completed" || !job.file_path) {
        throw new Error(job.error_message ?? job.meta?.error_message ?? "Exportul nu a putut fi finalizat.");
      }

      setExportProgress({
        key,
        status: "downloading",
        title: "Fișierul este gata",
        detail: "Descărcarea pornește automat.",
      });
      await adminApi.downloadExportJob(job.id, job.file_name ?? job.meta?.file_name);
      setExportProgress({
        key,
        status: "completed",
        title: "Export descărcat",
        detail: "Fișierul a fost salvat și rămâne disponibil în tabul Exporturi.",
      });
      window.setTimeout(() => {
        setExportProgress((current) => (current?.key === key ? null : current));
      }, 5000);
    } catch (error) {
      setExportProgress({
        key,
        status: "error",
        title: "Exportul nu a reușit",
        detail: error instanceof Error ? error.message : "Încearcă din nou.",
      });
    } finally {
      try {
        setExportsData(await adminApi.getExports());
      } catch {
        // Descărcarea rămâne validă chiar dacă istoricul nu se poate reîncărca imediat.
      }
      setExportingKey(null);
    }
  };

  const downloadExport = async (jobId: number, fileName?: string | null) => {
    const key = `download:${jobId}`;
    setDownloadingExportId(jobId);
    setExportProgress({
      key,
      status: "downloading",
      title: "Se descarcă fișierul",
      detail: fileName ?? "Export pregătit anterior",
    });
    try {
      await adminApi.downloadExportJob(jobId, fileName);
      setExportProgress({
        key,
        status: "completed",
        title: "Fișier descărcat",
        detail: fileName ?? "Exportul a fost descărcat.",
      });
      window.setTimeout(() => {
        setExportProgress((current) => (current?.key === key ? null : current));
      }, 5000);
    } catch (error) {
      setExportProgress({
        key,
        status: "error",
        title: "Descărcarea nu a reușit",
        detail: error instanceof Error ? error.message : "Încearcă din nou.",
      });
    } finally {
      setDownloadingExportId(null);
    }
  };

  const copyCheckoutId = async (checkoutId: string) => {
    await navigator.clipboard.writeText(checkoutId);
    setCopiedCheckoutId(checkoutId);
    window.setTimeout(() => {
      setCopiedCheckoutId((current) => (current === checkoutId ? null : current));
    }, 1600);
  };

  const openRefund = (topUp: PaymentTopUpItem) => {
    setSelectedTopUp(topUp);
    setRefundForm({
      amount: topUp.refundable_amount > 0 ? topUp.refundable_amount.toFixed(2) : "",
      reason: "",
    });
    setRefundError(null);
  };

  const closeRefund = () => {
    if (isRefunding) return;
    setSelectedTopUp(null);
    setRefundForm({ amount: "", reason: "" });
    setRefundError(null);
  };

  const submitRefund = async () => {
    if (!selectedTopUp) return;
    setIsRefunding(true);
    setRefundError(null);
    try {
      const response = await adminApi.refundPaymentTopUp(selectedTopUp.id, {
        amount: Number(refundForm.amount || 0),
        reason: refundForm.reason.trim(),
      });
      setPaymentTopUps((current) => ({
        items: current.items.map((item) => (item.id === response.top_up.id ? response.top_up : item)),
      }));
      setSelectedTopUp(null);
      setRefundForm({ amount: "", reason: "" });
      await Promise.all([loadDashboard(range), loadAccounting(accountingFilters)]);
    } catch (requestError) {
      setRefundError(requestError instanceof Error ? requestError.message : "Refundul nu a putut fi procesat.");
    } finally {
      setIsRefunding(false);
    }
  };

  const currentAccountingExportFilters = React.useMemo(() => {
    const filters: Record<string, unknown> = { ...accountingFilters };
    delete filters.page;
    delete filters.per_page;
    return filters;
  }, [accountingFilters]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Finanțe și facturare</h1>
          <p className="page-description">
            Separă banii intrați în platformă, tranzacțiile wallet, plățile, costurile și exporturile contabile.
          </p>
        </div>

        {(activeTab === "overview" || activeTab === "wallet") ? (
          <Tabs
            tabs={[
              { id: "3months", label: "3 luni" },
              { id: "30days", label: "30 zile" },
              { id: "7days", label: "7 zile" },
            ]}
            activeTab={range}
            onChange={(value) => setRange(value as RangeValue)}
          />
        ) : null}
      </div>

      <Tabs
        tabs={[
          { id: "overview", label: "Prezentare", icon: BarChart3Icon },
          { id: "accounting", label: "Contabilitate", icon: LandmarkIcon },
          { id: "wallet", label: "Tranzacții wallet", icon: WalletCardsIcon },
          { id: "payments", label: "Plăți și refunduri", icon: CreditCardIcon },
          { id: "costs", label: "Costuri", icon: Settings2Icon },
          { id: "exports", label: "Exporturi", icon: FileSpreadsheetIcon },
        ]}
        activeTab={activeTab}
        onChange={(value) => setActiveTab(value as BillingTab)}
      />

      {activeTab === "overview" ? (
        <OverviewSection dashboard={dashboard} costs={costs} loading={isDashboardLoading} />
      ) : null}

      {activeTab === "accounting" ? (
        <AccountingSection
          draft={accountingDraft}
          setDraft={setAccountingDraft}
          filters={accountingFilters}
          setFilters={setAccountingFilters}
          data={accounting}
          loading={isAccountingLoading}
          canExport={canManageExports}
          exportingKey={exportingKey}
          onExport={createExport}
          currentExportFilters={currentAccountingExportFilters}
        />
      ) : null}

      {activeTab === "wallet" ? (
        <WalletSection
          dashboard={dashboard}
          loading={isDashboardLoading}
          search={walletSearch}
          setSearch={setWalletSearch}
          type={walletType}
          setType={setWalletType}
          direction={walletDirection}
          setDirection={setWalletDirection}
          items={filteredWalletTransactions}
        />
      ) : null}

      {activeTab === "payments" ? (
        <PaymentsSection
          items={filteredTopUps}
          loading={isOperationalLoading}
          search={paymentSearch}
          setSearch={setPaymentSearch}
          copiedCheckoutId={copiedCheckoutId}
          onCopyCheckoutId={copyCheckoutId}
          canProcessRefunds={canProcessRefunds}
          onRefund={openRefund}
        />
      ) : null}

      {activeTab === "costs" ? (
        <CostsSection
          costs={costs}
          loading={isOperationalLoading}
          canManage={canManageCosts}
          saving={isSavingCosts}
          form={costForm}
          setForm={setCostForm}
          onSave={saveCosts}
        />
      ) : null}

      {activeTab === "exports" ? (
        <ExportsSection
          data={exportsData}
          loading={isOperationalLoading}
          canManage={canManageExports}
          exportingKey={exportingKey}
          downloadingId={downloadingExportId}
          range={range}
          onCreate={createExport}
          onDownload={downloadExport}
        />
      ) : null}

      <ExportProgressNotice
        progress={exportProgress}
        onDismiss={() => setExportProgress(null)}
      />

      <Modal
        isOpen={selectedTopUp !== null}
        onClose={closeRefund}
        title="Refund Pay.Filmoteca"
        footer={
          <>
            <Button variant="outline" onClick={closeRefund} disabled={isRefunding}>
              Anulează
            </Button>
            <Button
              onClick={() => void submitRefund()}
              disabled={isRefunding || !refundForm.amount || !refundForm.reason.trim()}
            >
              <RotateCcwIcon className="h-4 w-4" />
              {isRefunding ? "Se procesează..." : "Trimite refund"}
            </Button>
          </>
        }
      >
        {selectedTopUp ? (
          <div className="space-y-5">
            <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 text-sm sm:grid-cols-2">
              <InfoValue label="Utilizator" value={selectedTopUp.user.email ?? selectedTopUp.user.name ?? "N/A"} />
              <InfoValue
                label="Disponibil refund"
                value={formatCurrency(selectedTopUp.refundable_amount, selectedTopUp.currency)}
              />
              <InfoValue label="Țară facturare" value={selectedTopUp.billing_address?.country_code ?? "N/A"} />
              <InfoValue label="RRN" value={selectedTopUp.provider_rrn ?? "N/A"} mono />
              <div className="sm:col-span-2">
                <p className="text-muted-foreground">checkoutId</p>
                <div className="mt-1 flex items-center gap-2">
                  <p className="break-all font-mono text-xs">{selectedTopUp.provider_checkout_id ?? "N/A"}</p>
                  {selectedTopUp.provider_checkout_id ? (
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      className="h-7 w-7"
                      onClick={() => void copyCheckoutId(selectedTopUp.provider_checkout_id as string)}
                    >
                      {copiedCheckoutId === selectedTopUp.provider_checkout_id ? (
                        <CheckIcon className="h-3.5 w-3.5 text-emerald-600" />
                      ) : (
                        <ClipboardIcon className="h-3.5 w-3.5" />
                      )}
                    </Button>
                  ) : null}
                </div>
              </div>
            </div>

            <label className="space-y-2 text-sm">
              <span className="text-muted-foreground">Suma refund</span>
              <Input
                type="number"
                min="20"
                step="0.01"
                value={refundForm.amount}
                onChange={(event) => setRefundForm((current) => ({ ...current, amount: event.target.value }))}
              />
            </label>

            <label className="space-y-2 text-sm">
              <span className="text-muted-foreground">Motiv refund</span>
              <Textarea
                value={refundForm.reason}
                maxLength={500}
                onChange={(event) => setRefundForm((current) => ({ ...current, reason: event.target.value }))}
                placeholder="Ex: plată dublă, eroare tehnică confirmată..."
              />
            </label>

            {refundError ? (
              <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-700 dark:text-rose-300">
                {refundError}
              </div>
            ) : null}
          </div>
        ) : null}
      </Modal>
    </div>
  );
}

function ExportProgressNotice({
  progress,
  onDismiss,
}: {
  progress: ExportProgress | null;
  onDismiss: () => void;
}) {
  if (!progress) {
    return null;
  }

  const isBusy = progress.status === "preparing" || progress.status === "downloading";
  const isCompleted = progress.status === "completed";
  const toneClass = isCompleted
    ? "border-emerald-500/40 bg-emerald-50 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-50"
    : progress.status === "error"
      ? "border-rose-500/40 bg-rose-50 text-rose-950 dark:bg-rose-950 dark:text-rose-50"
      : "border-sky-500/40 bg-background text-foreground";

  return (
    <div
      role="status"
      aria-live="polite"
      className={`fixed bottom-5 right-5 z-[100] w-[min(380px,calc(100vw-2.5rem))] overflow-hidden rounded-xl border p-4 shadow-2xl ${toneClass}`}
    >
      <div className="flex items-start gap-3">
        {isBusy ? (
          <LoaderCircleIcon className="mt-0.5 h-5 w-5 shrink-0 animate-spin text-sky-600" />
        ) : isCompleted ? (
          <CircleCheckIcon className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
        ) : (
          <CircleAlertIcon className="mt-0.5 h-5 w-5 shrink-0 text-rose-600" />
        )}
        <div className="min-w-0 flex-1">
          <p className="font-semibold">{progress.title}</p>
          <p className="mt-1 text-sm opacity-75">{progress.detail}</p>
        </div>
        {!isBusy ? (
          <button
            type="button"
            aria-label="Închide notificarea"
            className="rounded-md p-1 opacity-60 transition hover:bg-black/5 hover:opacity-100 dark:hover:bg-white/10"
            onClick={onDismiss}
          >
            <XIcon className="h-4 w-4" />
          </button>
        ) : null}
      </div>
      {isBusy ? (
        <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-sky-500/15">
          <div className="h-full w-2/3 animate-pulse rounded-full bg-sky-500" />
        </div>
      ) : null}
    </div>
  );
}

function OverviewSection({
  dashboard,
  costs,
  loading,
}: {
  dashboard: DashboardResponse | null;
  costs: CostSettingsResponse;
  loading: boolean;
}) {
  const stats = dashboard?.stats;
  const costsTotal = stats?.current_month_costs_usd ?? 0;
  const profit = stats?.current_month_profit_usd ?? 0;

  return (
    <div className="space-y-6">
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <FinanceMetric
          label={`Venit ${dashboard?.range.label ?? ""}`}
          value={`+${formatCurrency(stats?.period_revenue_amount ?? 0)}`}
          detail={`${stats?.paid_orders_count ?? 0} comenzi plătite`}
          tone="positive"
          icon={ArrowDownLeftIcon}
        />
        <FinanceMetric
          label="Costuri luna curentă"
          value={`−${formatCurrency(costsTotal, "USD")}`}
          detail={`${costs.monthly_costs.length} poziții de cost`}
          tone="negative"
          icon={ArrowUpRightIcon}
        />
        <FinanceMetric
          label="Profit luna curentă"
          value={formatSignedCurrency(profit, "USD")}
          detail="Venit minus storage, delivery și DRM"
          tone={profit >= 0 ? "positive" : "negative"}
          icon={LandmarkIcon}
        />
        <FinanceMetric
          label="Comandă medie"
          value={formatCurrency(stats?.average_order_value ?? 0)}
          detail={`${stats?.unique_buyers_count ?? 0} cumpărători unici`}
          tone="neutral"
          icon={ReceiptTextIcon}
        />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Evoluția veniturilor din conținut</CardTitle>
          <CardDescription>
            Cumpărările din wallet pentru perioada selectată. Intrările bancare reale sunt în tabul Contabilitate.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {(dashboard?.sales_timeline.length ?? 0) > 0 ? (
            <SalesTimeline points={dashboard?.sales_timeline ?? []} metric="revenue" currency="MDL" />
          ) : (
            <EmptyState label={loading ? "Se încarcă veniturile..." : "Nu există vânzări în acest interval."} />
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function AccountingSection({
  draft,
  setDraft,
  filters,
  setFilters,
  data,
  loading,
  canExport,
  exportingKey,
  onExport,
  currentExportFilters,
}: {
  draft: AccountingFilters;
  setDraft: React.Dispatch<React.SetStateAction<AccountingFilters>>;
  filters: AccountingFilters;
  setFilters: React.Dispatch<React.SetStateAction<AccountingFilters>>;
  data: AccountingTransactionsResponse | null;
  loading: boolean;
  canExport: boolean;
  exportingKey: string | null;
  onExport: (
    format: "excel" | "pdf" | "json",
    scope: string,
    filters?: Record<string, unknown>,
  ) => Promise<void>;
  currentExportFilters: Record<string, unknown>;
}) {
  const summary = data?.summary;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <FilterIcon className="h-4 w-4" />
            Filtre contabilitate
          </CardTitle>
          <CardDescription>
            Registrul pentru contabilitate: bani reali intrați prin top-up plătit și bani reali ieșiți prin refund reușit.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 text-sm md:grid-cols-2">
            <div>
              <p className="font-semibold text-foreground">Cum stabilim Moldova sau extern?</p>
              <p className="mt-1 text-muted-foreground">
                Folosim codul țării din adresa de facturare salvată la momentul plății: MD = Moldova,
                orice alt cod = extern. Dacă țara lipsește, operațiunea apare ca „Necunoscut”.
              </p>
            </div>
            <div>
              <p className="font-semibold text-foreground">De ce este separat de plăți?</p>
              <p className="mt-1 text-muted-foreground">
                Aici vezi numai fluxul financiar contabilizat. Checkout-urile în așteptare, eșuate sau în procesare
                se verifică în tabul Plăți și refunduri.
              </p>
            </div>
          </div>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <FilterField label="De la">
              <Input
                type="date"
                value={draft.from ?? ""}
                onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
              />
            </FilterField>
            <FilterField label="Până la">
              <Input
                type="date"
                value={draft.to ?? ""}
                onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))}
              />
            </FilterField>
            <FilterField label="Piață">
              <select
                className={SELECT_CLASS}
                value={draft.market ?? "all"}
                onChange={(event) =>
                  setDraft((current) => ({
                    ...current,
                    market: event.target.value as AccountingFilters["market"],
                  }))
                }
              >
                <option value="all">Moldova + extern</option>
                <option value="domestic">Doar Moldova</option>
                <option value="international">Doar extern</option>
              </select>
            </FilterField>
            <FilterField label="Sens">
              <select
                className={SELECT_CLASS}
                value={draft.direction ?? "all"}
                onChange={(event) =>
                  setDraft((current) => ({
                    ...current,
                    direction: event.target.value as AccountingFilters["direction"],
                  }))
                }
              >
                <option value="all">Intrări + ieșiri</option>
                <option value="inflow">Doar intrări</option>
                <option value="outflow">Doar ieșiri</option>
              </select>
            </FilterField>
            <FilterField label="Țară facturare">
              <select
                className={SELECT_CLASS}
                value={draft.country_code ?? ""}
                onChange={(event) => setDraft((current) => ({ ...current, country_code: event.target.value }))}
              >
                <option value="">Toate țările</option>
                {(data?.options.countries ?? []).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </FilterField>
            <FilterField label="Regiune">
              <select
                className={SELECT_CLASS}
                value={draft.administrative_area ?? ""}
                onChange={(event) =>
                  setDraft((current) => ({ ...current, administrative_area: event.target.value }))
                }
              >
                <option value="">Toate regiunile</option>
                {(data?.options.regions ?? []).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </FilterField>
            <FilterField label="Status">
              <select
                className={SELECT_CLASS}
                value={draft.status ?? "accounted"}
                onChange={(event) => setDraft((current) => ({ ...current, status: event.target.value }))}
              >
                {(data?.options.statuses ?? [
                  { value: "accounted", label: "Doar contabilizate" },
                  { value: "all", label: "Toate statusurile" },
                ]).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </FilterField>
            <FilterField label="Căutare">
              <div className="relative">
                <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="pl-9"
                  value={draft.search ?? ""}
                  onChange={(event) => setDraft((current) => ({ ...current, search: event.target.value }))}
                  placeholder="Email, RRN, checkout..."
                />
              </div>
            </FilterField>
          </div>

          <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
            <Button
              variant="ghost"
              onClick={() => {
                const next = initialAccountingFilters();
                setDraft(next);
                setFilters(next);
              }}
            >
              <RotateCcwIcon className="h-4 w-4" />
              Resetează
            </Button>
            <Button
              onClick={() => setFilters({ ...draft, page: 1, per_page: filters.per_page ?? 100 })}
              disabled={loading}
            >
              <FilterIcon className="h-4 w-4" />
              {loading ? "Se aplică..." : "Aplică filtrele"}
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <FinanceMetric
          label="Intrări brute"
          value={`+${formatCurrency(summary?.gross_inflow ?? 0, summary?.currency)}`}
          detail="Top-up-uri bancare contabilizate"
          tone="positive"
          icon={ArrowDownLeftIcon}
        />
        <FinanceMetric
          label="Refunduri"
          value={`−${formatCurrency(summary?.refunds ?? 0, summary?.currency)}`}
          detail="Bani returnați prin provider"
          tone="negative"
          icon={ArrowUpRightIcon}
        />
        <FinanceMetric
          label="Net în platformă"
          value={formatSignedCurrency(summary?.net_inflow ?? 0, summary?.currency)}
          detail={`${summary?.accounted_transactions ?? 0} operațiuni contabilizate`}
          tone={(summary?.net_inflow ?? 0) >= 0 ? "positive" : "negative"}
          icon={LandmarkIcon}
        />
        <FinanceMetric
          label="Originea plăților"
          value={`${summary?.domestic_transactions ?? 0} MD / ${summary?.international_transactions ?? 0} extern`}
          detail="După adresa de facturare salvată la plată"
          tone="neutral"
          icon={ReceiptTextIcon}
        />
      </div>

      <Card>
        <CardHeader className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <CardTitle>Registru contabil</CardTitle>
            <CardDescription>
              {data?.pagination.total ?? 0} operațiuni găsite. Sumele necontabilizate sunt afișate cu zero.
            </CardDescription>
          </div>
          {canExport ? (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                onClick={() => void onExport("excel", "accounting", currentExportFilters)}
                disabled={Boolean(exportingKey)}
              >
                <DownloadIcon className="h-4 w-4" />
                Descarcă Excel — filtre curente
              </Button>
              <Button
                variant="outline"
                onClick={() => void onExport("excel", "accounting", { status: "accounted" })}
                disabled={Boolean(exportingKey)}
              >
                <FileSpreadsheetIcon className="h-4 w-4" />
                Descarcă Excel — tot istoricul
              </Button>
            </div>
          ) : null}
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Operațiune</TableHead>
                  <TableHead>Client</TableHead>
                  <TableHead>Facturare</TableHead>
                  <TableHead>Provider</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Sumă</TableHead>
                  <TableHead>Când</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(data?.items ?? []).map((item) => (
                  <AccountingRow key={item.id} item={item} />
                ))}
                {(data?.items.length ?? 0) === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="h-32 text-center text-muted-foreground">
                      {loading ? "Se încarcă registrul contabil..." : "Nu există operațiuni pentru filtrele curente."}
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

function AccountingRow({ item }: { item: AccountingTransactionItem }) {
  const isInflow = item.direction === "inflow";
  const billingParts = [
    item.billing.address_line1,
    item.billing.city,
    item.billing.administrative_area,
    item.billing.postal_code,
  ].filter(Boolean);

  return (
    <TableRow>
      <TableCell>
        <div className="space-y-1">
          <span
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-1 text-xs font-semibold ${
              isInflow
                ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                : "border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300"
            }`}
          >
            {isInflow ? <ArrowDownLeftIcon className="h-3 w-3" /> : <ArrowUpRightIcon className="h-3 w-3" />}
            {item.direction_label}
          </span>
          <p className="max-w-[220px] truncate text-xs text-muted-foreground">{item.description}</p>
        </div>
      </TableCell>
      <TableCell>
        <p className="font-medium">{item.user.name ?? "Client necunoscut"}</p>
        <p className="text-xs text-muted-foreground">{item.user.email ?? "Fără email"}</p>
      </TableCell>
      <TableCell>
        <div className="max-w-[260px] space-y-1">
          <div className="flex items-center gap-2">
            <span className="font-medium">{item.billing.country_code ?? "N/A"}</span>
            <span
              className={`rounded-full px-2 py-0.5 text-[11px] ${
                item.billing.market === "domestic"
                  ? "bg-sky-500/10 text-sky-700 dark:text-sky-300"
                  : "bg-violet-500/10 text-violet-700 dark:text-violet-300"
              }`}
            >
              {item.billing.market_label}
            </span>
          </div>
          <p className="truncate text-xs text-muted-foreground">
            {billingParts.join(", ") || "Adresă indisponibilă"}
          </p>
        </div>
      </TableCell>
      <TableCell>
        <p className="max-w-[180px] truncate font-mono text-xs">{item.provider_checkout_id ?? "Fără checkout"}</p>
        <p className="max-w-[180px] truncate text-xs text-muted-foreground">RRN: {item.provider_rrn ?? "N/A"}</p>
      </TableCell>
      <TableCell>
        <StatusPill status={item.status} accounted={item.is_accounted} />
      </TableCell>
      <TableCell
        className={`text-right font-bold ${
          !item.is_accounted
            ? "text-muted-foreground"
            : isInflow
              ? "text-emerald-600"
              : "text-rose-600"
        }`}
      >
        {formatSignedCurrency(item.signed_amount, item.currency)}
      </TableCell>
      <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
        {formatDate(item.processed_at)}
      </TableCell>
    </TableRow>
  );
}

function WalletSection({
  dashboard,
  loading,
  search,
  setSearch,
  type,
  setType,
  direction,
  setDirection,
  items,
}: {
  dashboard: DashboardResponse | null;
  loading: boolean;
  search: string;
  setSearch: (value: string) => void;
  type: string;
  setType: (value: string) => void;
  direction: string;
  setDirection: (value: string) => void;
  items: DashboardResponse["recent_transactions"];
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Tranzacții wallet</CardTitle>
        <CardDescription>
          Mișcări interne de sold. Minusul este debit din wallet, plusul este credit în wallet.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-3 md:grid-cols-3">
          <div className="relative">
            <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              className="pl-9"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Utilizator, film, ofertă..."
            />
          </div>
          <select className={SELECT_CLASS} value={type} onChange={(event) => setType(event.target.value)}>
            <option value="all">Toate tipurile</option>
            <option value="purchase">Cumpărări</option>
            <option value="top_up">Top-up</option>
            <option value="refund">Refund</option>
            <option value="welcome_bonus">Bonus</option>
            <option value="adjustment">Ajustări</option>
          </select>
          <select
            className={SELECT_CLASS}
            value={direction}
            onChange={(event) => setDirection(event.target.value)}
          >
            <option value="all">Credit + debit</option>
            <option value="credit">Doar plus</option>
            <option value="debit">Doar minus</option>
          </select>
        </div>

        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Tip</TableHead>
                <TableHead>Utilizator</TableHead>
                <TableHead>Conținut</TableHead>
                <TableHead>Ofertă / sursă</TableHead>
                <TableHead className="text-right">Sumă</TableHead>
                <TableHead>Când</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((transaction) => (
                <TableRow key={transaction.id}>
                  <TableCell>
                    <TransactionTypeBadge
                      type={transaction.type}
                      amount={transaction.amount}
                      label={transaction.type_label}
                    />
                  </TableCell>
                  <TableCell>
                    <p className="font-medium">{transaction.user.name ?? "Utilizator necunoscut"}</p>
                    <p className="text-xs text-muted-foreground">{transaction.user.email ?? "Fără email"}</p>
                  </TableCell>
                  <TableCell>
                    <p className="font-medium">{transaction.content?.title ?? "Tranzacție platformă"}</p>
                    <p className="text-xs text-muted-foreground">{transaction.content?.slug ?? transaction.description}</p>
                  </TableCell>
                  <TableCell>
                    <p>{transaction.offer.name ?? transaction.funding_source ?? "N/A"}</p>
                    <p className="text-xs text-muted-foreground">
                      {transaction.offer.quality ?? ""}
                      {transaction.platform_amount || transaction.own_amount
                        ? ` · platformă ${formatCurrency(transaction.platform_amount, transaction.currency)} / proprii ${formatCurrency(
                            transaction.own_amount,
                            transaction.currency,
                          )}`
                        : ""}
                    </p>
                  </TableCell>
                  <TableCell
                    className={`text-right font-bold ${
                      transaction.amount > 0
                        ? "text-emerald-600"
                        : transaction.amount < 0
                          ? "text-rose-600"
                          : "text-muted-foreground"
                    }`}
                  >
                    {formatSignedCurrency(transaction.amount, transaction.currency)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(transaction.processed_at)}
                  </TableCell>
                </TableRow>
              ))}
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="h-32 text-center text-muted-foreground">
                    {loading ? "Se încarcă tranzacțiile..." : "Nu există tranzacții pentru filtrele curente."}
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </div>
        <p className="text-xs text-muted-foreground">
          Sunt afișate cele mai recente {dashboard?.recent_transactions.length ?? 0} tranzacții din interval.
        </p>
      </CardContent>
    </Card>
  );
}

function PaymentsSection({
  items,
  loading,
  search,
  setSearch,
  copiedCheckoutId,
  onCopyCheckoutId,
  canProcessRefunds,
  onRefund,
}: {
  items: PaymentTopUpItem[];
  loading: boolean;
  search: string;
  setSearch: (value: string) => void;
  copiedCheckoutId: string | null;
  onCopyCheckoutId: (checkoutId: string) => Promise<void>;
  canProcessRefunds: boolean;
  onRefund: (topUp: PaymentTopUpItem) => void;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <CardTitle>Plăți și refunduri Pay.Filmoteca</CardTitle>
          <CardDescription>
            Monitor tehnic per checkout: include plăți în așteptare, în procesare, eșuate și plătite și permite
            inițierea refundului. Totalurile contabile finale sunt în tabul Contabilitate.
          </CardDescription>
        </div>
        <div className="relative w-full max-w-md">
          <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-9"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Checkout, RRN, utilizator, țară..."
          />
        </div>
      </CardHeader>
      <CardContent className="p-0">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Utilizator</TableHead>
                <TableHead>Facturare</TableHead>
                <TableHead>Checkout</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Încasat</TableHead>
                <TableHead className="text-right">Refundat</TableHead>
                <TableHead className="text-right">Disponibil</TableHead>
                <TableHead>Când</TableHead>
                <TableHead>Acțiuni</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((topUp) => {
                const canRefund =
                  canProcessRefunds &&
                  topUp.status === "paid" &&
                  Boolean(topUp.provider_checkout_id) &&
                  topUp.refundable_amount >= 20;

                return (
                  <TableRow key={topUp.id}>
                    <TableCell>
                      <p className="font-medium">{topUp.user.name ?? "Utilizator necunoscut"}</p>
                      <p className="text-xs text-muted-foreground">{topUp.user.email ?? "Fără email"}</p>
                    </TableCell>
                    <TableCell>
                      <p className="font-medium">{topUp.billing_address?.country_code ?? "N/A"}</p>
                      <p className="max-w-[180px] truncate text-xs text-muted-foreground">
                        {billingAddressLabel(topUp)}
                      </p>
                    </TableCell>
                    <TableCell>
                      <div className="flex max-w-[220px] items-center gap-2">
                        <p className="truncate font-mono text-xs">{topUp.provider_checkout_id ?? "Fără checkout"}</p>
                        {topUp.provider_checkout_id ? (
                          <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="h-7 w-7 shrink-0"
                            onClick={() => void onCopyCheckoutId(topUp.provider_checkout_id as string)}
                          >
                            {copiedCheckoutId === topUp.provider_checkout_id ? (
                              <CheckIcon className="h-3.5 w-3.5 text-emerald-600" />
                            ) : (
                              <ClipboardIcon className="h-3.5 w-3.5" />
                            )}
                          </Button>
                        ) : null}
                      </div>
                      <p className="text-xs text-muted-foreground">RRN: {topUp.provider_rrn ?? "N/A"}</p>
                    </TableCell>
                    <TableCell>
                      <StatusPill status={topUp.status} accounted={topUp.status === "paid" || topUp.status === "refunded"} />
                    </TableCell>
                    <TableCell className="text-right font-semibold text-emerald-600">
                      +{formatCurrency(topUp.amount, topUp.currency)}
                    </TableCell>
                    <TableCell className="text-right font-semibold text-rose-600">
                      {topUp.refunded_amount > 0 ? `−${formatCurrency(topUp.refunded_amount, topUp.currency)}` : "—"}
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(topUp.refundable_amount, topUp.currency)}</TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDate(topUp.credited_at ?? topUp.created_at)}
                    </TableCell>
                    <TableCell>
                      {canProcessRefunds ? (
                        <Button size="sm" variant="outline" onClick={() => onRefund(topUp)} disabled={!canRefund}>
                          <RotateCcwIcon className="h-4 w-4" />
                          Refund
                        </Button>
                      ) : (
                        <span className="text-xs text-muted-foreground">Fără permisiune</span>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="h-32 text-center text-muted-foreground">
                    {loading ? "Se încarcă plățile..." : "Nu există plăți pentru căutarea curentă."}
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

function CostsSection({
  costs,
  loading,
  canManage,
  saving,
  form,
  setForm,
  onSave,
}: {
  costs: CostSettingsResponse;
  loading: boolean;
  canManage: boolean;
  saving: boolean;
  form: {
    storage_cost_per_gb_day: string;
    delivery_cost_per_gb: string;
    drm_cost_per_license: string;
    usd_to_mdl_rate: string;
  };
  setForm: React.Dispatch<React.SetStateAction<{
    storage_cost_per_gb_day: string;
    delivery_cost_per_gb: string;
    drm_cost_per_license: string;
    usd_to_mdl_rate: string;
  }>>;
  onSave: () => Promise<void>;
}) {
  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Motor de costuri</CardTitle>
          <CardDescription>
            Configurația pentru storage, delivery, DRM și conversia USD → MDL.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {[
              ["storage_cost_per_gb_day", "Storage / GB / zi"],
              ["delivery_cost_per_gb", "Delivery / GB"],
              ["drm_cost_per_license", "DRM / licență"],
              ["usd_to_mdl_rate", "Curs USD → MDL"],
            ].map(([key, label]) => (
              <FilterField key={key} label={label}>
                <Input
                  type="number"
                  step="0.0001"
                  value={form[key as keyof typeof form]}
                  disabled={!canManage}
                  onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}
                />
              </FilterField>
            ))}
          </div>
          {canManage ? (
            <div className="flex justify-end border-t pt-4">
              <Button onClick={() => void onSave()} disabled={saving}>
                {saving ? "Se salvează..." : "Publică versiune nouă"}
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
        <Card>
          <CardHeader>
            <CardTitle>Costuri lunare pe film</CardTitle>
            <CardDescription>Venit, cheltuieli și profit pentru fiecare titlu și format.</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Film</TableHead>
                    <TableHead>Format</TableHead>
                    <TableHead className="text-right">Venit</TableHead>
                    <TableHead className="text-right">Storage</TableHead>
                    <TableHead className="text-right">Delivery</TableHead>
                    <TableHead className="text-right">DRM</TableHead>
                    <TableHead className="text-right">Profit</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {costs.monthly_costs.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>
                        <p className="font-medium">{row.content_title ?? "Titlu lipsă"}</p>
                        <p className="text-xs text-muted-foreground">{row.month}</p>
                      </TableCell>
                      <TableCell>{row.quality ?? "N/A"}</TableCell>
                      <TableCell className="text-right font-semibold text-emerald-600">
                        +{formatCurrency(row.revenue_usd, "USD")}
                      </TableCell>
                      <TableCell className="text-right text-rose-600">
                        −{formatCurrency(row.storage_cost_usd, "USD")}
                      </TableCell>
                      <TableCell className="text-right text-rose-600">
                        −{formatCurrency(row.delivery_cost_usd, "USD")}
                      </TableCell>
                      <TableCell className="text-right text-rose-600">
                        −{formatCurrency(row.drm_cost_usd, "USD")}
                      </TableCell>
                      <TableCell
                        className={`text-right font-bold ${row.profit_usd >= 0 ? "text-emerald-600" : "text-rose-600"}`}
                      >
                        {formatSignedCurrency(row.profit_usd, "USD")}
                      </TableCell>
                    </TableRow>
                  ))}
                  {costs.monthly_costs.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={7} className="h-28 text-center text-muted-foreground">
                        {loading ? "Se încarcă costurile..." : "Nu există agregări pentru luna curentă."}
                      </TableCell>
                    </TableRow>
                  ) : null}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Situații producători</CardTitle>
            <CardDescription>Payout și profit estimat pe producător.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {costs.creator_statements.map((statement) => (
              <div key={statement.id} className="rounded-lg border bg-background p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-medium">{statement.creator_name ?? "Producător necunoscut"}</p>
                    <p className="text-xs text-muted-foreground">{statement.month}</p>
                  </div>
                  <span className={statement.profit_usd >= 0 ? "font-bold text-emerald-600" : "font-bold text-rose-600"}>
                    {formatSignedCurrency(statement.profit_usd, "USD")}
                  </span>
                </div>
                <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                  <InfoValue label="Venit" value={`+${formatCurrency(statement.revenue_usd, "USD")}`} />
                  <InfoValue label="Payout" value={`−${formatCurrency(statement.payout_usd, "USD")}`} />
                </div>
              </div>
            ))}
            {costs.creator_statements.length === 0 ? (
              <EmptyState label={loading ? "Se încarcă situațiile..." : "Nu există situații de plată."} />
            ) : null}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function ExportsSection({
  data,
  loading,
  canManage,
  exportingKey,
  downloadingId,
  range,
  onCreate,
  onDownload,
}: {
  data: ExportJobsResponse;
  loading: boolean;
  canManage: boolean;
  exportingKey: string | null;
  downloadingId: number | null;
  range: RangeValue;
  onCreate: (
    format: "excel" | "pdf" | "json",
    scope: string,
    filters?: Record<string, unknown>,
  ) => Promise<void>;
  onDownload: (jobId: number, fileName?: string | null) => Promise<void>;
}) {
  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Generează raport</CardTitle>
          <CardDescription>
            Fișierul se descarcă automat după generare și rămâne în istoricul de mai jos. Exportul contabil
            conține țara, regiunea, localitatea, codul poștal și adresa de facturare.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {canManage ? (
            <div className="grid gap-3 md:grid-cols-3">
              <Button
                variant="outline"
                className="h-auto justify-start p-4"
                onClick={() => void onCreate("excel", "accounting", { status: "accounted" })}
                disabled={Boolean(exportingKey)}
              >
                <FileSpreadsheetIcon className="h-5 w-5 text-emerald-600" />
                <span className="text-left">
                  <span className="block font-semibold">Excel contabil complet</span>
                  <span className="block text-xs text-muted-foreground">Toate intrările și refundurile</span>
                </span>
              </Button>
              <Button
                variant="outline"
                className="h-auto justify-start p-4"
                onClick={() => void onCreate("excel", "billing", { range })}
                disabled={Boolean(exportingKey)}
              >
                <ReceiptTextIcon className="h-5 w-5 text-sky-600" />
                <span className="text-left">
                  <span className="block font-semibold">Excel operațional</span>
                  <span className="block text-xs text-muted-foreground">Wallet, costuri și producători</span>
                </span>
              </Button>
              <Button
                variant="outline"
                className="h-auto justify-start p-4"
                onClick={() => void onCreate("pdf", "creator-statements", {})}
                disabled={Boolean(exportingKey)}
              >
                <DownloadIcon className="h-5 w-5 text-violet-600" />
                <span className="text-left">
                  <span className="block font-semibold">PDF producători</span>
                  <span className="block text-xs text-muted-foreground">Situații lunare de payout</span>
                </span>
              </Button>
            </div>
          ) : (
            <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
              Rolul curent poate vedea rapoartele, dar nu poate genera exporturi editabile.
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Istoric exporturi</CardTitle>
          <CardDescription>Fișierele generate recent și statusul lor.</CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Format</TableHead>
                <TableHead>Raport</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Solicitat de</TableHead>
                <TableHead>Când</TableHead>
                <TableHead>Fișier</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.items.map((job) => (
                <TableRow key={job.id}>
                  <TableCell className="font-semibold uppercase">{job.format}</TableCell>
                  <TableCell>{job.scope}</TableCell>
                  <TableCell>
                    <StatusPill status={job.status} accounted={job.status === "completed"} />
                    {job.error_message ? <p className="mt-1 text-xs text-rose-600">{job.error_message}</p> : null}
                  </TableCell>
                  <TableCell>{job.requested_by ?? "Sistem"}</TableCell>
                  <TableCell>{formatDate(job.created_at)}</TableCell>
                  <TableCell>
                    {job.status === "completed" && job.file_path ? (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => void onDownload(job.id, job.file_name)}
                        disabled={downloadingId === job.id}
                      >
                        <DownloadIcon className="h-4 w-4" />
                        {downloadingId === job.id ? "Se descarcă..." : "Descarcă"}
                      </Button>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                </TableRow>
              ))}
              {data.items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="h-28 text-center text-muted-foreground">
                    {loading ? "Se încarcă exporturile..." : "Nu există exporturi generate."}
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}

function FinanceMetric({
  label,
  value,
  detail,
  tone,
  icon: Icon,
}: {
  label: string;
  value: string;
  detail: string;
  tone: "positive" | "negative" | "neutral";
  icon: React.ElementType;
}) {
  const className =
    tone === "positive"
      ? "border-emerald-500/30 bg-emerald-500/5"
      : tone === "negative"
        ? "border-rose-500/30 bg-rose-500/5"
        : "";
  const valueClass =
    tone === "positive" ? "text-emerald-600" : tone === "negative" ? "text-rose-600" : "text-foreground";

  return (
    <Card className={className}>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
        <div>
          <CardDescription>{label}</CardDescription>
          <CardTitle className={`mt-2 text-2xl ${valueClass}`}>{value}</CardTitle>
        </div>
        <div className="rounded-lg border bg-background p-2">
          <Icon className={`h-5 w-5 ${valueClass}`} />
        </div>
      </CardHeader>
      <CardContent>
        <p className="text-xs text-muted-foreground">{detail}</p>
      </CardContent>
    </Card>
  );
}

function StatusPill({ status, accounted }: { status: string; accounted: boolean }) {
  const isFailed = ["failed", "canceled"].includes(status);
  const className = accounted
    ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
    : isFailed
      ? "border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300"
      : "border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300";

  return <span className={`inline-flex rounded-full border px-2 py-1 text-xs font-medium ${className}`}>{status}</span>;
}

function FilterField({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="space-y-2 text-sm">
      <span className="font-medium text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}

function InfoValue({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`mt-1 font-medium ${mono ? "font-mono text-xs" : ""}`}>{value}</p>
    </div>
  );
}

function EmptyState({ label }: { label: string }) {
  return (
    <div className="flex h-40 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
      {label}
    </div>
  );
}
