import React from "react";
import {
  AlertTriangleIcon,
  ArrowDownToLineIcon,
  CheckCircle2Icon,
  ChevronDownIcon,
  ChevronRightIcon,
  CircleDollarSignIcon,
  FileSpreadsheetIcon,
  FilmIcon,
  LandmarkIcon,
  LoaderCircleIcon,
  MapPinIcon,
  PlusIcon,
  ReceiptTextIcon,
  RefreshCwIcon,
  ScaleIcon,
  Settings2Icon,
  ShieldCheckIcon,
  UsersIcon,
} from "lucide-react";
import { Modal } from "../components/shared/Modal";
import { Tabs } from "../components/shared/Tabs";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import { cn } from "../lib/utils";

type Metric = { label: string; purchases: number; amount: number };
type Allocation = {
  holder: string;
  share_percent: number;
  person_type: string | null;
  is_vat_registered: boolean;
  gross_share_amount: number;
  withholding_amount: number;
  net_payable_amount: number;
};
type Sale = {
  id: string;
  purchased_at: string;
  content_id: number;
  film: string;
  country_code: string | null;
  market: "domestic" | "export";
  offer: string | null;
  quality: string | null;
  rental_days: number | null;
  gross_amount: number;
  vat_amount: number;
  net_ex_vat_amount: number;
  platform_share_amount: number;
  payment_method: string;
  sales_channel: string;
  currency: string;
  calculation_status: string;
  allocations: Allocation[];
};
type ReportingData = {
  scope: { is_holder: boolean; creator_ids: number[] };
  summary: {
    purchases: number;
    gross_amount: number;
    vat_amount: number;
    holder_gross_amount: number;
    withholding_amount: number;
    holder_net_amount: number;
    platform_share_amount: number;
    refund_amount: number;
    domestic_amount: number;
    export_amount: number;
    needs_review: number;
    currency: string;
  };
  by_film: Array<{
    content_id: number;
    title: string;
    purchases: number;
    gross_amount: number;
    holder_gross_amount: number;
    withholding_amount: number;
    net_payable_amount: number;
  }>;
  timeline: Metric[];
  countries: Metric[];
  qualities: Metric[];
  durations: Metric[];
  channels: Metric[];
  payment_methods: Metric[];
  transactions: Sale[];
  options: { contents: Array<{ id: number; title: string }>; countries: string[] };
};
type Contract = {
  id: number; content_id: number; content_title: string; share_percent: number; territories: string[] | null;
  effective_from: string; effective_until: string | null; contract_reference: string | null; status: string;
};
type FiscalProfile = {
  id: number; person_type: "PF" | "PJ"; tax_residency: string; is_vat_registered: boolean; vat_rate: number;
  withholding_enabled: boolean; withholding_rate: number; tax_identifier: string | null; iban: string | null;
  payment_currency: string; effective_from: string; effective_until: string | null; status: string;
};
type CreatorProfile = {
  id: number; name: string; company_name: string | null; email: string | null; is_active: boolean;
  contents: Array<{ id: number; title: string }>; contracts: Contract[]; fiscal_profiles: FiscalProfile[];
};
type ProfilesData = {
  creators: CreatorProfile[];
  contents: Array<{ id: number; title: string }>;
  settings: Array<{ id: number; domestic_country_code: string; domestic_vat_rate: number; effective_from: string; effective_until: string | null; is_active: boolean }>;
};

type Tab = "overview" | "transactions" | "profiles";
type Dialog = "contract" | "fiscal" | "settings" | null;
const selectClass = "h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring";

function isoDate(date: Date) { return date.toISOString().slice(0, 10); }
function startOfMonth() { const date = new Date(); date.setDate(1); return isoDate(date); }
function money(value: number, currency = "MDL") {
  return new Intl.NumberFormat("ro-MD", { style: "currency", currency, maximumFractionDigits: 2 }).format(value || 0);
}
function dateLabel(value: string) { return new Date(value).toLocaleString("ro-MD", { dateStyle: "medium", timeStyle: "short" }); }
function currentVersion<T extends { effective_from: string; effective_until: string | null; status: string }>(items: T[]) {
  const today = isoDate(new Date());
  return items.find((item) => item.status === "active" && item.effective_from.slice(0, 10) <= today && (!item.effective_until || item.effective_until.slice(0, 10) >= today));
}

export function Reporting() {
  const { can } = useAdmin();
  const canManage = can("reporting.manage_profiles");
  const [tab, setTab] = React.useState<Tab>("overview");
  const [filters, setFilters] = React.useState({ from: startOfMonth(), to: isoDate(new Date()), content_id: "", country_code: "", market: "all" });
  const [draft, setDraft] = React.useState(filters);
  const [data, setData] = React.useState<ReportingData | null>(null);
  const [profiles, setProfiles] = React.useState<ProfilesData | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [dialog, setDialog] = React.useState<Dialog>(null);
  const [selectedCreator, setSelectedCreator] = React.useState<number | null>(null);
  const [expandedSale, setExpandedSale] = React.useState<string | null>(null);
  const [saving, setSaving] = React.useState(false);
  const [exporting, setExporting] = React.useState<"pdf" | "excel" | null>(null);
  const [captureMessage, setCaptureMessage] = React.useState<string | null>(null);

  const load = React.useCallback(async () => {
    setLoading(true); setError(null);
    try { setData(await adminApi.getRightsReporting<ReportingData>(filters)); }
    catch (requestError) { setError(requestError instanceof Error ? requestError.message : "Raportul nu a putut fi încărcat."); }
    finally { setLoading(false); }
  }, [filters]);

  const loadProfiles = React.useCallback(async () => {
    if (!canManage) return;
    try { setProfiles(await adminApi.getRightsReportingProfiles<ProfilesData>()); }
    catch (requestError) { setError(requestError instanceof Error ? requestError.message : "Profilele nu au putut fi încărcate."); }
  }, [canManage]);

  React.useEffect(() => { void load(); }, [load]);
  React.useEffect(() => { if (tab === "profiles") void loadProfiles(); }, [loadProfiles, tab]);

  const createExport = async (format: "pdf" | "excel") => {
    setExporting(format); setError(null);
    try {
      const scope = format === "pdf" ? "reporting-holder" : "reporting-accounting";
      const response = await adminApi.createExportJob({ format, scope, filters });
      if (response.job.status !== "completed") throw new Error(response.job.error_message ?? "Exportul nu a putut fi finalizat.");
      await adminApi.downloadExportJob(response.job.id, response.job.file_name ?? response.job.meta?.file_name);
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : "Exportul a eșuat."); }
    finally { setExporting(null); }
  };

  const captureMissing = async () => {
    setSaving(true); setCaptureMessage(null);
    try {
      const result = await adminApi.captureMissingReportingSales();
      setCaptureMessage(result.captured ? `${result.captured} cumpărări istorice au fost procesate.` : "Toate cumpărările sunt deja procesate.");
      await load();
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : "Procesarea nu a reușit."); }
    finally { setSaving(false); }
  };

  const currency = data?.summary.currency ?? "MDL";
  const holderView = data?.scope.is_holder ?? false;
  const applyPreset = (days: number | "month") => {
    const to = new Date(); const from = new Date();
    if (days === "month") from.setDate(1); else from.setDate(from.getDate() - days + 1);
    const next = { ...draft, from: isoDate(from), to: isoDate(to) };
    setDraft(next); setFilters(next);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div className="page-header">
          <div className="mb-2 flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-muted-foreground">
            <ShieldCheckIcon className="h-4 w-4 text-emerald-600" /> Registru auditabil · calcule înghețate
          </div>
          <h1 className="page-title">{holderView ? "Performanța filmelor tale" : "Raportare și drepturi"}</h1>
          <p className="page-description">
            {holderView
              ? "Vânzări, taxe și suma netă care îți revine — fără date personale despre cumpărători."
              : "O singură sursă pentru management, titulari, contabilitate și audit fiscal."}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={() => void createExport("pdf")} disabled={exporting !== null}>
            {exporting === "pdf" ? <LoaderCircleIcon className="h-4 w-4 animate-spin" /> : <ArrowDownToLineIcon className="h-4 w-4" />}
            Raport PDF
          </Button>
          {canManage ? (
            <Button onClick={() => void createExport("excel")} disabled={exporting !== null}>
              {exporting === "excel" ? <LoaderCircleIcon className="h-4 w-4 animate-spin" /> : <FileSpreadsheetIcon className="h-4 w-4" />}
              Excel pentru contabilitate
            </Button>
          ) : null}
        </div>
      </div>

      {error ? <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200">{error}</div> : null}

      <Card>
        <CardContent className="p-4">
          <div className="grid gap-3 lg:grid-cols-[1fr_1fr_1.2fr_1fr_auto]">
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground"><span>De la</span><Input type="date" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} /></label>
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground"><span>Până la</span><Input type="date" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} /></label>
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground"><span>Film</span><select className={cn(selectClass, "w-full")} value={draft.content_id} onChange={(e) => setDraft({ ...draft, content_id: e.target.value })}><option value="">Toate filmele</option>{data?.options.contents.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}</select></label>
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground"><span>Piață</span><select className={cn(selectClass, "w-full")} value={draft.market} onChange={(e) => setDraft({ ...draft, market: e.target.value })}><option value="all">Moldova + export</option><option value="domestic">Doar Moldova</option><option value="export">Doar export</option></select></label>
            <div className="flex items-end"><Button className="w-full" onClick={() => setFilters(draft)}><ReceiptTextIcon className="h-4 w-4" /> Aplică</Button></div>
          </div>
          <div className="mt-3 flex flex-wrap gap-2 border-t pt-3">
            <span className="mr-1 self-center text-xs text-muted-foreground">Perioade rapide:</span>
            <Button size="sm" variant="ghost" onClick={() => applyPreset(7)}>7 zile</Button>
            <Button size="sm" variant="ghost" onClick={() => applyPreset(30)}>30 zile</Button>
            <Button size="sm" variant="ghost" onClick={() => applyPreset("month")}>Luna curentă</Button>
          </div>
        </CardContent>
      </Card>

      <Tabs tabs={[
        { id: "overview", label: "Prezentare", icon: CircleDollarSignIcon },
        { id: "transactions", label: holderView ? "Cumpărări" : "Registru vânzări", icon: ReceiptTextIcon },
        ...(canManage ? [{ id: "profiles", label: "Contracte & fiscal", icon: ScaleIcon }] : []),
      ]} activeTab={tab} onChange={(value) => setTab(value as Tab)} />

      {loading ? <LoadingState /> : null}
      {!loading && data && tab === "overview" ? <Overview data={data} holderView={holderView} currency={currency} /> : null}
      {!loading && data && tab === "transactions" ? <Transactions data={data} expanded={expandedSale} onExpand={setExpandedSale} currency={currency} holderView={holderView} /> : null}
      {!loading && tab === "profiles" && canManage ? (
        <Profiles
          data={profiles}
          captureMessage={captureMessage}
          saving={saving}
          onCapture={captureMissing}
          onOpen={(type, creatorId) => { setSelectedCreator(creatorId ?? null); setDialog(type); }}
        />
      ) : null}

      <ConfigurationDialog
        type={dialog}
        creatorId={selectedCreator}
        profiles={profiles}
        saving={saving}
        onClose={() => setDialog(null)}
        onSave={async (payload) => {
          setSaving(true); setError(null);
          try {
            if (dialog === "contract") await adminApi.createRightsContract(payload);
            if (dialog === "fiscal") await adminApi.createFiscalProfile(payload);
            if (dialog === "settings") await adminApi.createReportingSettings(payload);
            setDialog(null); await loadProfiles();
          } catch (requestError) { setError(requestError instanceof Error ? requestError.message : "Versiunea nu a putut fi salvată."); }
          finally { setSaving(false); }
        }}
      />
    </div>
  );
}

function LoadingState() {
  return <div className="flex min-h-64 items-center justify-center rounded-xl border bg-card"><LoaderCircleIcon className="mr-2 h-5 w-5 animate-spin text-primary" /><span className="text-sm text-muted-foreground">Se calculează raportul…</span></div>;
}

function Overview({ data, holderView, currency }: { data: ReportingData; holderView: boolean; currency: string }) {
  const s = data.summary;
  const kpis = holderView ? [
    { label: "Vânzări brute", value: money(s.gross_amount, currency), hint: `${s.purchases} cumpărări`, icon: CircleDollarSignIcon, tone: "text-blue-600 bg-blue-500/10" },
    { label: "Cota ta brută", value: money(s.holder_gross_amount, currency), hint: "conform contractelor valabile", icon: ScaleIcon, tone: "text-violet-600 bg-violet-500/10" },
    { label: "Taxe / rețineri", value: money(s.withholding_amount + s.vat_amount, currency), hint: `rețineri ${money(s.withholding_amount, currency)}`, icon: LandmarkIcon, tone: "text-amber-600 bg-amber-500/10" },
    { label: "Net de plată", value: money(s.holder_net_amount, currency), hint: "după rețineri", icon: CheckCircle2Icon, tone: "text-emerald-600 bg-emerald-500/10" },
  ] : [
    { label: "Încasări brute", value: money(s.gross_amount, currency), hint: `${s.purchases} cumpărări`, icon: CircleDollarSignIcon, tone: "text-blue-600 bg-blue-500/10" },
    { label: "Titularilor", value: money(s.holder_net_amount, currency), hint: `brut ${money(s.holder_gross_amount, currency)}`, icon: UsersIcon, tone: "text-violet-600 bg-violet-500/10" },
    { label: "Taxe și rețineri", value: money(s.vat_amount + s.withholding_amount, currency), hint: `TVA ${money(s.vat_amount, currency)}`, icon: LandmarkIcon, tone: "text-amber-600 bg-amber-500/10" },
    { label: "Rămâne 609 FILM", value: money(s.platform_share_amount, currency), hint: "cotă netă platformă", icon: CheckCircle2Icon, tone: "text-emerald-600 bg-emerald-500/10" },
  ];
  const marketTotal = s.domestic_amount + s.export_amount || 1;

  return <div className="space-y-6">
    {s.needs_review > 0 ? <div className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><AlertTriangleIcon className="mt-0.5 h-5 w-5 shrink-0" /><div><p className="font-semibold">{s.needs_review} tranzacții necesită configurare</p><p className="mt-1 opacity-80">Lipsește un contract sau profil fiscal valabil la data cumpărării. Tranzacțiile rămân vizibile, dar nu sunt considerate finalizate fiscal.</p></div></div> : null}
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">{kpis.map(({ label, value, hint, icon: Icon, tone }) => <Card key={label}><CardContent className="p-5"><div className="flex items-start justify-between"><div><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p><p className="mt-3 text-2xl font-semibold tracking-tight">{value}</p><p className="mt-1 text-xs text-muted-foreground">{hint}</p></div><span className={cn("rounded-xl p-2.5", tone)}><Icon className="h-5 w-5" /></span></div></CardContent></Card>)}</div>
    <div className="grid gap-6 xl:grid-cols-[1.55fr_1fr]">
      <Card><CardHeader><CardTitle>Performanță pe film</CardTitle><CardDescription>Clasament după vânzările brute din perioada selectată.</CardDescription></CardHeader><CardContent><Table><TableHeader><TableRow><TableHead>Film</TableHead><TableHead className="text-right">Cumpărări</TableHead><TableHead className="text-right">Vânzări</TableHead><TableHead className="text-right">{holderView ? "Netul tău" : "Net titulari"}</TableHead></TableRow></TableHeader><TableBody>{data.by_film.slice(0, 8).map((film) => <TableRow key={film.content_id}><TableCell><div className="flex items-center gap-3"><span className="rounded-lg bg-muted p-2"><FilmIcon className="h-4 w-4" /></span><span className="font-medium">{film.title}</span></div></TableCell><TableCell className="text-right tabular-nums">{film.purchases}</TableCell><TableCell className="text-right font-medium tabular-nums">{money(film.gross_amount, currency)}</TableCell><TableCell className="text-right tabular-nums text-emerald-700 dark:text-emerald-400">{money(film.net_payable_amount, currency)}</TableCell></TableRow>)}{data.by_film.length === 0 ? <TableRow><TableCell colSpan={4} className="h-28 text-center text-muted-foreground">Nu sunt vânzări în această perioadă.</TableCell></TableRow> : null}</TableBody></Table></CardContent></Card>
      <Card><CardHeader><CardTitle>Moldova vs. export</CardTitle><CardDescription>Distribuția valorică a vânzărilor.</CardDescription></CardHeader><CardContent className="space-y-6"><div className="flex h-3 overflow-hidden rounded-full bg-muted"><div className="bg-blue-600" style={{ width: `${(s.domestic_amount / marketTotal) * 100}%` }} /><div className="bg-violet-500" style={{ width: `${(s.export_amount / marketTotal) * 100}%` }} /></div><MarketRow label="Moldova" value={s.domestic_amount} total={marketTotal} color="bg-blue-600" currency={currency} /><MarketRow label="Export" value={s.export_amount} total={marketTotal} color="bg-violet-500" currency={currency} /><div className="border-t pt-5"><p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Top țări</p><DimensionBars items={data.countries.slice(0, 5)} currency={currency} /></div></CardContent></Card>
    </div>
    <div className="grid gap-6 lg:grid-cols-3"><DimensionCard title="Calitate" description="FHD vs. 4K" items={data.qualities} currency={currency} /><DimensionCard title="Durata accesului" description="2 zile vs. 7 zile" items={data.durations} currency={currency} /><DimensionCard title="Metoda de plată" description="Preferințele cumpărătorilor" items={data.payment_methods} currency={currency} /></div>
  </div>;
}

function MarketRow({ label, value, total, color, currency }: { label: string; value: number; total: number; color: string; currency: string }) { return <div className="flex items-center justify-between"><div className="flex items-center gap-2"><span className={cn("h-2.5 w-2.5 rounded-full", color)} /><span className="text-sm font-medium">{label}</span></div><div className="text-right"><p className="text-sm font-semibold tabular-nums">{money(value, currency)}</p><p className="text-xs text-muted-foreground">{Math.round((value / total) * 100)}%</p></div></div>; }
function DimensionCard({ title, description, items, currency }: { title: string; description: string; items: Metric[]; currency: string }) { return <Card><CardHeader><CardTitle>{title}</CardTitle><CardDescription>{description}</CardDescription></CardHeader><CardContent><DimensionBars items={items.slice(0, 5)} currency={currency} /></CardContent></Card>; }
function DimensionBars({ items, currency }: { items: Metric[]; currency: string }) { const max = Math.max(...items.map((i) => i.amount), 1); return <div className="space-y-4">{items.map((item) => <div key={item.label}><div className="mb-1.5 flex justify-between gap-4 text-xs"><span className="font-medium">{item.label}</span><span className="tabular-nums text-muted-foreground">{item.purchases} · {money(item.amount, currency)}</span></div><div className="h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${Math.max(4, (item.amount / max) * 100)}%` }} /></div></div>)}{items.length === 0 ? <p className="py-8 text-center text-sm text-muted-foreground">Fără date</p> : null}</div>; }

function Transactions({ data, expanded, onExpand, currency, holderView }: { data: ReportingData; expanded: string | null; onExpand: (id: string | null) => void; currency: string; holderView: boolean }) {
  return <Card><CardHeader><div className="flex items-start justify-between"><div><CardTitle>{holderView ? "Cumpărări detaliate" : "Registrul vânzărilor"}</CardTitle><CardDescription>Fiecare rând folosește profilul valabil exact la data cumpărării.</CardDescription></div><span className="rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">max. 100 rânduri</span></div></CardHeader><CardContent className="p-0"><div className="overflow-x-auto"><Table><TableHeader><TableRow><TableHead className="w-10" /><TableHead>Data / film</TableHead><TableHead>Țară</TableHead><TableHead>Ofertă</TableHead><TableHead className="text-right">Achitat</TableHead><TableHead className="text-right">TVA</TableHead><TableHead className="text-right">{holderView ? "Netul tău" : "Titulari"}</TableHead><TableHead>Status</TableHead></TableRow></TableHeader><TableBody>{data.transactions.map((sale) => <React.Fragment key={sale.id}><TableRow className="cursor-pointer" onClick={() => onExpand(expanded === sale.id ? null : sale.id)}><TableCell>{expanded === sale.id ? <ChevronDownIcon className="h-4 w-4" /> : <ChevronRightIcon className="h-4 w-4" />}</TableCell><TableCell><p className="font-medium">{sale.film}</p><p className="mt-0.5 text-xs text-muted-foreground">{dateLabel(sale.purchased_at)}</p></TableCell><TableCell><div className="flex items-center gap-2"><MapPinIcon className="h-3.5 w-3.5 text-muted-foreground" />{sale.country_code ?? "N/A"}</div><p className="mt-0.5 text-[11px] uppercase text-muted-foreground">{sale.market === "domestic" ? "MD" : "Export"}</p></TableCell><TableCell><p>{sale.offer ?? "—"}</p><p className="text-xs text-muted-foreground">{sale.quality ?? "—"} · {sale.rental_days ? `${sale.rental_days} zile` : "permanent"}</p></TableCell><TableCell className="text-right font-medium tabular-nums">{money(sale.gross_amount, currency)}</TableCell><TableCell className="text-right tabular-nums">{money(sale.vat_amount, currency)}</TableCell><TableCell className="text-right font-medium tabular-nums text-emerald-700 dark:text-emerald-400">{money(sale.allocations.reduce((sum, item) => sum + item.net_payable_amount, 0), currency)}</TableCell><TableCell><StatusBadge status={sale.calculation_status} /></TableCell></TableRow>{expanded === sale.id ? <TableRow className="bg-muted/25"><TableCell /><TableCell colSpan={7}><div className="grid gap-3 py-3 md:grid-cols-3"><Detail label="ID tranzacție" value={sale.id} mono /><Detail label="Canal / plată" value={`${sale.sales_channel} · ${sale.payment_method}`} /><Detail label="Bază fără TVA" value={money(sale.net_ex_vat_amount, currency)} />{sale.allocations.map((allocation) => <div key={allocation.holder} className="rounded-lg border bg-background p-3 md:col-span-3"><div className="flex flex-wrap items-center justify-between gap-2"><div><p className="font-medium">{allocation.holder}</p><p className="text-xs text-muted-foreground">{allocation.person_type ?? "Profil lipsă"} · {allocation.is_vat_registered ? "TVA" : "NTVA"} · cotă {allocation.share_percent}%</p></div><div className="grid grid-cols-3 gap-6 text-right text-xs"><div><p className="text-muted-foreground">Cotă brută</p><p className="mt-1 font-semibold">{money(allocation.gross_share_amount, currency)}</p></div><div><p className="text-muted-foreground">Reținere</p><p className="mt-1 font-semibold">{money(allocation.withholding_amount, currency)}</p></div><div><p className="text-muted-foreground">Net</p><p className="mt-1 font-semibold text-emerald-700 dark:text-emerald-400">{money(allocation.net_payable_amount, currency)}</p></div></div></div></div>)}</div></TableCell></TableRow> : null}</React.Fragment>)}{data.transactions.length === 0 ? <TableRow><TableCell colSpan={8} className="h-40 text-center text-muted-foreground">Nu există cumpărări pentru filtrele selectate.</TableCell></TableRow> : null}</TableBody></Table></div></CardContent></Card>;
}
function Detail({ label, value, mono }: { label: string; value: string; mono?: boolean }) { return <div><p className="text-xs text-muted-foreground">{label}</p><p className={cn("mt-1 text-sm", mono && "font-mono text-xs")}>{value}</p></div>; }
function StatusBadge({ status }: { status: string }) { const ok = status === "calculated"; return <span className={cn("inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-medium", ok ? "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" : "bg-amber-500/10 text-amber-800 dark:text-amber-300")}>{ok ? <CheckCircle2Icon className="h-3 w-3" /> : <AlertTriangleIcon className="h-3 w-3" />}{ok ? "Calculat" : "De verificat"}</span>; }

function Profiles({ data, saving, captureMessage, onCapture, onOpen }: { data: ProfilesData | null; saving: boolean; captureMessage: string | null; onCapture: () => void; onOpen: (type: Exclude<Dialog, null>, creatorId?: number) => void }) {
  const settings = data?.settings[0];
  return <div className="space-y-6">
    <Card><CardContent className="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between"><div className="flex items-start gap-3"><span className="rounded-xl bg-primary/10 p-2.5 text-primary"><Settings2Icon className="h-5 w-5" /></span><div><p className="font-semibold">Reguli fiscale ale platformei</p><p className="mt-1 text-sm text-muted-foreground">Piață locală: {settings?.domestic_country_code ?? "MD"} · TVA inclus: {settings?.domestic_vat_rate ?? 20}% · valabil din {settings?.effective_from?.slice(0, 10) ?? "—"}</p></div></div><div className="flex flex-wrap gap-2"><Button variant="outline" onClick={() => onOpen("settings")}><PlusIcon className="h-4 w-4" />Versiune nouă</Button><Button variant="outline" onClick={onCapture} disabled={saving}><RefreshCwIcon className={cn("h-4 w-4", saving && "animate-spin")} />Procesează istoricul</Button></div></CardContent></Card>
    {captureMessage ? <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">{captureMessage}</div> : null}
    <div className="grid gap-4 xl:grid-cols-2">{data?.creators.map((creator) => { const fiscal = currentVersion(creator.fiscal_profiles); const activeContracts = creator.contracts.filter((contract) => currentVersion([contract]) != null); return <Card key={creator.id}><CardHeader><div className="flex items-start justify-between gap-4"><div><CardTitle className="flex items-center gap-2"><UsersIcon className="h-4 w-4 text-muted-foreground" />{creator.name}</CardTitle><CardDescription>{creator.company_name ?? creator.email ?? "Fără date de contact"}</CardDescription></div><span className={cn("rounded-full px-2.5 py-1 text-[11px] font-medium", fiscal && activeContracts.length ? "bg-emerald-500/10 text-emerald-700" : "bg-amber-500/10 text-amber-800")}>{fiscal && activeContracts.length ? "Configurat" : "Incomplet"}</span></div></CardHeader><CardContent className="space-y-4"><div className="grid gap-3 sm:grid-cols-2"><ProfileBlock icon={ScaleIcon} title="Contractual" value={activeContracts.length ? `${activeContracts.length} filme active` : "Lipsește contractul"} detail={activeContracts.slice(0, 2).map((item) => `${item.content_title} · ${item.share_percent}%`).join("; ") || "Cota și perioada nu sunt setate."} /><ProfileBlock icon={LandmarkIcon} title="Fiscal" value={fiscal ? `${fiscal.person_type} · ${fiscal.is_vat_registered ? "TVA" : "NTVA"}` : "Lipsește profilul"} detail={fiscal ? `${fiscal.tax_residency} · reținere ${fiscal.withholding_enabled ? `${fiscal.withholding_rate}%` : "nu"} · ${fiscal.payment_currency}` : "Calculul net nu poate fi finalizat."} /></div><div className="flex flex-wrap gap-2 border-t pt-4"><Button size="sm" variant="outline" onClick={() => onOpen("contract", creator.id)}><PlusIcon className="h-3.5 w-3.5" />Contract</Button><Button size="sm" variant="outline" onClick={() => onOpen("fiscal", creator.id)}><PlusIcon className="h-3.5 w-3.5" />Profil fiscal</Button></div></CardContent></Card>; })}{data?.creators.length === 0 ? <Card className="xl:col-span-2"><CardContent className="py-16 text-center text-sm text-muted-foreground">Creează mai întâi titularii în secțiunea Creatori.</CardContent></Card> : null}</div>
  </div>;
}
function ProfileBlock({ icon: Icon, title, value, detail }: { icon: React.ComponentType<{ className?: string }>; title: string; value: string; detail: string }) { return <div className="rounded-xl border bg-muted/20 p-4"><div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><Icon className="h-3.5 w-3.5" />{title}</div><p className="mt-3 font-semibold">{value}</p><p className="mt-1 line-clamp-2 text-xs leading-5 text-muted-foreground">{detail}</p></div>; }

function ConfigurationDialog({ type, creatorId, profiles, saving, onClose, onSave }: { type: Dialog; creatorId: number | null; profiles: ProfilesData | null; saving: boolean; onClose: () => void; onSave: (payload: Record<string, unknown>) => Promise<void> }) {
  // Dynamic because the three version forms intentionally share one compact dialog.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const [form, setForm] = React.useState<Record<string, any>>({});
  React.useEffect(() => {
    if (!type) return;
    const creator = profiles?.creators.find((item) => item.id === creatorId);
    const fiscal = creator ? currentVersion(creator.fiscal_profiles) : null;
    const settings = profiles?.settings[0];
    setForm(type === "contract"
      ? { content_creator_id: creatorId ?? "", content_id: "", share_percent: 50, territories: "", effective_from: isoDate(new Date()), effective_until: "", contract_reference: "", notes: "" }
      : type === "fiscal"
        ? { content_creator_id: creatorId ?? "", person_type: fiscal?.person_type ?? "PF", tax_residency: fiscal?.tax_residency ?? "MD", is_vat_registered: fiscal?.is_vat_registered ?? false, vat_rate: fiscal?.vat_rate ?? 20, withholding_enabled: fiscal?.withholding_enabled ?? true, withholding_rate: fiscal?.withholding_rate ?? 12, tax_identifier: fiscal?.tax_identifier ?? "", iban: fiscal?.iban ?? "", payment_currency: fiscal?.payment_currency ?? "MDL", effective_from: isoDate(new Date()), effective_until: "" }
        : { domestic_country_code: settings?.domestic_country_code ?? "MD", domestic_vat_rate: settings?.domestic_vat_rate ?? 20, effective_from: isoDate(new Date()) });
  }, [creatorId, profiles, type]);
  const title = type === "contract" ? "Versiune contractuală nouă" : type === "fiscal" ? "Profil fiscal nou" : "Regulă fiscală nouă";
  const submit = async () => { const payload: Record<string, unknown> = { ...form }; if (type === "contract") payload.territories = String(form.territories || "").split(",").map((v) => v.trim().toUpperCase()).filter(Boolean); Object.keys(payload).forEach((key) => { if (payload[key] === "") payload[key] = null; }); await onSave(payload); };
  return <Modal isOpen={type !== null} onClose={onClose} title={title} footer={<><Button variant="outline" onClick={onClose} disabled={saving}>Anulează</Button><Button onClick={() => void submit()} disabled={saving}>{saving ? <LoaderCircleIcon className="h-4 w-4 animate-spin" /> : <CheckCircle2Icon className="h-4 w-4" />}Salvează versiunea</Button></>}>
    <div className="space-y-4">
      <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">Versiunile sunt append-only: versiunea curentă se încheie automat cu o zi înainte, iar tranzacțiile deja calculate nu se modifică.</div>
      {type !== "settings" ? <Field label="Titular"><select className={cn(selectClass, "w-full")} value={form.content_creator_id ?? ""} onChange={(e) => setForm({ ...form, content_creator_id: Number(e.target.value) })}><option value="">Selectează titularul</option>{profiles?.creators.map((creator) => <option key={creator.id} value={creator.id}>{creator.name}</option>)}</select></Field> : null}
      {type === "contract" ? <><Field label="Film"><select className={cn(selectClass, "w-full")} value={form.content_id ?? ""} onChange={(e) => setForm({ ...form, content_id: Number(e.target.value) })}><option value="">Selectează filmul</option>{profiles?.contents.map((content) => <option key={content.id} value={content.id}>{content.title}</option>)}</select></Field><div className="grid grid-cols-2 gap-4"><Field label="Cota titularului (%)"><Input type="number" min="0.01" max="100" step="0.01" value={form.share_percent ?? ""} onChange={(e) => setForm({ ...form, share_percent: Number(e.target.value) })} /></Field><Field label="Teritorii (coduri ISO)"><Input placeholder="MD, RO, DE — gol = toate" value={form.territories ?? ""} onChange={(e) => setForm({ ...form, territories: e.target.value })} /></Field></div><Field label="Referință contract"><Input placeholder="Contract nr. 12/2026" value={form.contract_reference ?? ""} onChange={(e) => setForm({ ...form, contract_reference: e.target.value })} /></Field></> : null}
      {type === "fiscal" ? <><div className="grid grid-cols-2 gap-4"><Field label="Tip persoană"><select className={cn(selectClass, "w-full")} value={form.person_type ?? "PF"} onChange={(e) => setForm({ ...form, person_type: e.target.value })}><option>PF</option><option>PJ</option></select></Field><Field label="Rezidență fiscală"><Input maxLength={2} value={form.tax_residency ?? "MD"} onChange={(e) => setForm({ ...form, tax_residency: e.target.value.toUpperCase() })} /></Field></div><div className="grid grid-cols-2 gap-4"><ToggleField label="Plătitor TVA" checked={!!form.is_vat_registered} onChange={(value) => setForm({ ...form, is_vat_registered: value })} /><Field label="Cota TVA (%)"><Input type="number" min="0" max="100" step="0.01" disabled={!form.is_vat_registered} value={form.vat_rate ?? 0} onChange={(e) => setForm({ ...form, vat_rate: Number(e.target.value) })} /></Field><ToggleField label="Reținere la sursă" checked={!!form.withholding_enabled} onChange={(value) => setForm({ ...form, withholding_enabled: value })} /><Field label="Procent reținere (%)"><Input type="number" min="0" max="100" step="0.01" disabled={!form.withholding_enabled} value={form.withholding_rate ?? 0} onChange={(e) => setForm({ ...form, withholding_rate: Number(e.target.value) })} /></Field></div><Field label="IDNO / IDNP"><Input value={form.tax_identifier ?? ""} onChange={(e) => setForm({ ...form, tax_identifier: e.target.value })} /></Field><Field label="IBAN"><Input className="font-mono" value={form.iban ?? ""} onChange={(e) => setForm({ ...form, iban: e.target.value.toUpperCase() })} /></Field><Field label="Moneda de plată"><Input maxLength={3} value={form.payment_currency ?? "MDL"} onChange={(e) => setForm({ ...form, payment_currency: e.target.value.toUpperCase() })} /></Field></> : null}
      {type === "settings" ? <div className="grid grid-cols-2 gap-4"><Field label="Țara pieței locale"><Input maxLength={2} value={form.domestic_country_code ?? "MD"} onChange={(e) => setForm({ ...form, domestic_country_code: e.target.value.toUpperCase() })} /></Field><Field label="TVA inclus (%)"><Input type="number" min="0" max="100" step="0.01" value={form.domestic_vat_rate ?? 20} onChange={(e) => setForm({ ...form, domestic_vat_rate: Number(e.target.value) })} /></Field></div> : null}
      <div className="grid grid-cols-2 gap-4"><Field label="Valabil de la"><Input type="date" value={form.effective_from ?? ""} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} /></Field>{type !== "settings" ? <Field label="Valabil până la (opțional)"><Input type="date" value={form.effective_until ?? ""} onChange={(e) => setForm({ ...form, effective_until: e.target.value })} /></Field> : null}</div>
    </div>
  </Modal>;
}
function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="block space-y-1.5"><span className="text-xs font-medium text-muted-foreground">{label}</span>{children}</label>; }
function ToggleField({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) { return <label className="flex h-16 cursor-pointer items-center justify-between rounded-lg border px-3"><span className="text-sm">{label}</span><button type="button" onClick={() => onChange(!checked)} className={cn("relative h-6 w-11 rounded-full transition-colors", checked ? "bg-primary" : "bg-muted-foreground/30")}><span className={cn("absolute top-1 h-4 w-4 rounded-full bg-white transition-all", checked ? "left-6" : "left-1")} /></button></label>; }
