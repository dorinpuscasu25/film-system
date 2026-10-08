import { useEffect, useMemo, useState } from "react";
import { BarChart3Icon, MegaphoneIcon, PlusIcon, SearchIcon, Trash2Icon } from "lucide-react";
import { adminApi } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";
import { HelpHint } from "../components/shared/HelpHint";
import { AdCreativeDraft, AdCreativeUpload } from "../components/shared/AdCreativeUpload";
import { ContentMultiSelect } from "../components/shared/ContentMultiSelect";
import { SectionLayout } from "../components/shared/SectionLayout";
import { AdCampaignStats } from "./AdCampaignStats";
import type { AdCampaignOptions, AdminAdCampaign } from "../types";

const PLACEMENT_LABELS: Record<string, { title: string; help: string }> = {
  "pre-roll": { title: "Înainte de film", help: "Reclama rulează imediat ce spectatorul apasă play, înainte să înceapă filmul." },
  "mid-roll": { title: "În mijlocul filmului", help: "Reclama întrerupe filmul la minutul ales. Filmul se reia automat după." },
  "post-roll": { title: "La finalul filmului", help: "Reclama rulează după ce filmul s-a terminat." },
};

const PLATFORM_LABELS: Record<string, string> = {
  web: "Site web",
  ios: "iPhone / iPad",
  tvos: "Apple TV",
  android: "Android",
};

const STATUS_LABELS: Record<string, string> = {
  draft: "Ciornă",
  active: "Activă",
  paused: "Pe pauză",
  completed: "Încheiată",
};

interface CampaignForm {
  name: string;
  company_name: string;
  placements: string[];
  mid_roll_offset_minutes: string;
  mid_roll_every_minutes: string;
  status: string;
  is_active: boolean;
  skip_offset_seconds: string;
  frequency_cap_per_session: string;
  frequency_cap_per_day: string;
  starts_at: string;
  ends_at: string;
  click_through_url: string;
  vast_tag_url: string;
  bid_amount: string;
  /**
   * "all" runs everywhere minus the exclusions; "selected" runs only on the
   * chosen films. The backend derives this from whether the include list is
   * empty, so only one of the two lists is ever meaningful.
   */
  scope_mode: "all" | "selected";
  target_content_ids: number[];
  target_excluded_content_ids: number[];
  target_countries: string[];
  target_age_ratings: string[];
  target_platforms: string[];
  exclude_kids_profiles: boolean;
  creative: AdCreativeDraft | null;
}

const EMPTY_FORM: CampaignForm = {
  name: "",
  company_name: "",
  placements: ["pre-roll"],
  mid_roll_offset_minutes: "",
  mid_roll_every_minutes: "",
  status: "draft",
  is_active: true,
  skip_offset_seconds: "5",
  frequency_cap_per_session: "",
  frequency_cap_per_day: "",
  starts_at: "",
  ends_at: "",
  click_through_url: "",
  vast_tag_url: "",
  bid_amount: "",
  scope_mode: "all",
  target_content_ids: [],
  target_excluded_content_ids: [],
  target_countries: [],
  target_age_ratings: [],
  target_platforms: [],
  exclude_kids_profiles: true,
  creative: null,
};

/** Minutes in the form, seconds in the API. */
// Campaign windows are always entered and shown in Moldova time, whatever
// timezone the admin's computer is set to. The API stores UTC.
const SCHEDULE_TIME_ZONE = "Europe/Chisinau";

const scheduleParts = new Intl.DateTimeFormat("en-GB", {
  timeZone: SCHEDULE_TIME_ZONE,
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** Wall-clock fields of `date` in Moldova, as a UTC timestamp. */
const scheduleWallClock = (date: Date) => {
  const parts = Object.fromEntries(scheduleParts.formatToParts(date).map((p) => [p.type, p.value]));
  return Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute);
};

/** UTC ISO from the API → "YYYY-MM-DDTHH:mm" in Moldova time for the input. */
const toScheduleInput = (iso: string | null | undefined) => {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return new Date(scheduleWallClock(date)).toISOString().slice(0, 16);
};

/** "YYYY-MM-DDTHH:mm" in Moldova time → UTC ISO for the API. */
const fromScheduleInput = (local: string) => {
  if (!local) return null;
  const [datePart, timePart = "00:00"] = local.split("T");
  const [y, m, d] = datePart.split("-").map(Number);
  const [hh, mm] = timePart.split(":").map(Number);
  const wanted = Date.UTC(y, m - 1, d, hh, mm);
  if (Number.isNaN(wanted)) return null;
  // Shift by Moldova's offset; repeat once so a DST boundary between the guess
  // and the result is accounted for.
  let utc = wanted - (scheduleWallClock(new Date(wanted)) - wanted);
  utc = wanted - (scheduleWallClock(new Date(utc)) - utc);
  return new Date(utc).toISOString();
};

const scheduleDisplay = new Intl.DateTimeFormat("ro-RO", {
  timeZone: SCHEDULE_TIME_ZONE,
  day: "numeric",
  month: "long",
  hour: "2-digit",
  minute: "2-digit",
});

/** Plain-language state of the window, so an admin sees at once if it is live. */
function scheduleSummary(startsAt: string, endsAt: string): { text: string; tone: "ok" | "wait" | "bad" } | null {
  const start = fromScheduleInput(startsAt);
  const end = fromScheduleInput(endsAt);
  const now = Date.now();
  if (start && end && new Date(end) <= new Date(start)) {
    return { text: "Data de sfârșit trebuie să fie după data de început.", tone: "bad" };
  }
  if (end && new Date(end).getTime() <= now) {
    return { text: `Perioada s-a încheiat pe ${scheduleDisplay.format(new Date(end))} — reclama nu va rula.`, tone: "bad" };
  }
  if (start && new Date(start).getTime() > now) {
    return { text: `Reclama pornește pe ${scheduleDisplay.format(new Date(start))} (ora Moldovei).`, tone: "wait" };
  }
  if (start || end) {
    return {
      text: end
        ? `Perioada e activă acum, până pe ${scheduleDisplay.format(new Date(end))} (ora Moldovei).`
        : "Perioada e activă acum.",
      tone: "ok",
    };
  }
  return null;
}

const toMinutes = (seconds: number | null | undefined) =>
  seconds === null || seconds === undefined ? "" : String(Math.round(seconds / 60));

function campaignToForm(campaign: AdminAdCampaign): CampaignForm {
  const creative = campaign.creatives[0];

  return {
    name: campaign.name,
    company_name: campaign.company_name ?? "",
    placements: campaign.placements?.length ? campaign.placements : [campaign.placement],
    mid_roll_offset_minutes: toMinutes(campaign.mid_roll_offset_seconds),
    mid_roll_every_minutes: campaign.mid_roll_every_minutes ? String(campaign.mid_roll_every_minutes) : "",
    status: campaign.status,
    is_active: campaign.is_active,
    skip_offset_seconds: campaign.skip_offset_seconds === null ? "" : String(campaign.skip_offset_seconds),
    frequency_cap_per_session: campaign.frequency_cap_per_session ? String(campaign.frequency_cap_per_session) : "",
    frequency_cap_per_day: campaign.frequency_cap_per_day ? String(campaign.frequency_cap_per_day) : "",
    starts_at: toScheduleInput(campaign.starts_at),
    ends_at: toScheduleInput(campaign.ends_at),
    click_through_url: campaign.click_through_url ?? "",
    vast_tag_url: campaign.vast_tag_url ?? "",
    bid_amount: campaign.bid_amount ? String(campaign.bid_amount) : "",
    scope_mode: (campaign.target_content_ids ?? []).length > 0 ? "selected" : "all",
    target_content_ids: campaign.target_content_ids ?? [],
    target_excluded_content_ids: campaign.target_excluded_content_ids ?? [],
    target_countries: campaign.target_countries ?? [],
    target_age_ratings: campaign.target_age_ratings ?? [],
    target_platforms: campaign.target_platforms ?? [],
    exclude_kids_profiles: campaign.exclude_kids_profiles ?? true,
    creative: creative
      ? {
          id: creative.id,
          name: creative.name,
          media_url: creative.media_url,
          mime_type: creative.mime_type ?? "video/mp4",
          duration_seconds: creative.duration_seconds ?? 0,
          width: creative.width,
          height: creative.height,
          is_active: creative.is_active,
        }
      : null,
  };
}

export function AdsManager() {
  const { can } = useAdmin();
  const canManage = can("advertising.manage");

  const [campaigns, setCampaigns] = useState<AdminAdCampaign[]>([]);
  const [options, setOptions] = useState<AdCampaignOptions | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [search, setSearch] = useState("");

  // null = list only; "new" = creating; number = editing that campaign.
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [form, setForm] = useState<CampaignForm>(EMPTY_FORM);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const loadCampaigns = async () => {
    setIsLoading(true);
    try {
      const response = await adminApi.getAdCampaigns();
      setCampaigns(response.items);
      setOptions(response.options);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca campaniile.");
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    void loadCampaigns();
  }, []);

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return campaigns;
    return campaigns.filter(
      (campaign) =>
        campaign.name.toLowerCase().includes(term)
        || (campaign.company_name ?? "").toLowerCase().includes(term),
    );
  }, [campaigns, search]);

  const update = <K extends keyof CampaignForm>(key: K, value: CampaignForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const togglePlacement = (placement: string) =>
    setForm((current) => ({
      ...current,
      placements: current.placements.includes(placement)
        ? current.placements.filter((item) => item !== placement)
        : [...current.placements, placement],
    }));

  const toggleInList = (key: "target_platforms" | "target_age_ratings", option: string) =>
    setForm((current) => ({
      ...current,
      [key]: current[key].includes(option)
        ? current[key].filter((item) => item !== option)
        : [...current[key], option],
    }));

  const openNew = () => {
    setForm(EMPTY_FORM);
    setEditing("new");
    setError(null);
    setNotice(null);
  };

  const openCampaign = (campaign: AdminAdCampaign) => {
    setForm(campaignToForm(campaign));
    setEditing(campaign.id);
    setError(null);
    setNotice(null);
  };

  const numberOrNull = (value: string) => {
    const parsed = Number(value);
    return value.trim() === "" || Number.isNaN(parsed) ? null : parsed;
  };

  const save = async () => {
    setError(null);
    setNotice(null);

    if (form.placements.length === 0) {
      setError("Alege cel puțin un moment în care apare reclama.");
      return;
    }
    if (!form.creative) {
      setError("Încarcă video-ul reclamei.");
      return;
    }
    // An include list that is empty means "everywhere" server-side, which is the
    // opposite of what this choice says — block it rather than silently invert.
    if (form.scope_mode === "selected" && form.target_content_ids.length === 0) {
      setError("Ai ales „Doar la filme alese”, dar nu ai adăugat niciun film. Adaugă cel puțin unul.");
      return;
    }

    setIsSaving(true);

    const midRollMinutes = numberOrNull(form.mid_roll_offset_minutes);

    const payload = {
      name: form.name.trim(),
      company_name: form.company_name.trim() || null,
      placements: form.placements,
      mid_roll_offset_seconds: midRollMinutes === null ? null : Math.round(midRollMinutes * 60),
      mid_roll_every_minutes: numberOrNull(form.mid_roll_every_minutes),
      status: form.status,
      is_active: form.is_active,
      skip_offset_seconds: numberOrNull(form.skip_offset_seconds),
      frequency_cap_per_session: numberOrNull(form.frequency_cap_per_session),
      frequency_cap_per_day: numberOrNull(form.frequency_cap_per_day),
      starts_at: fromScheduleInput(form.starts_at),
      ends_at: fromScheduleInput(form.ends_at),
      click_through_url: form.click_through_url.trim() || null,
      vast_tag_url: form.vast_tag_url.trim() || null,
      bid_amount: numberOrNull(form.bid_amount),
      target_content_ids: form.scope_mode === "selected" ? form.target_content_ids : [],
      target_excluded_content_ids: form.scope_mode === "all" ? form.target_excluded_content_ids : [],
      target_countries: form.target_countries,
      target_age_ratings: form.target_age_ratings,
      target_platforms: form.target_platforms,
      exclude_kids_profiles: form.exclude_kids_profiles,
      creatives: [
        {
          name: form.creative.name,
          media_url: form.creative.media_url,
          mime_type: form.creative.mime_type,
          duration_seconds: form.creative.duration_seconds,
          width: form.creative.width,
          height: form.creative.height,
          is_active: true,
        },
      ],
    };

    try {
      if (editing === "new") {
        await adminApi.createAdCampaign(payload);
        setNotice("Campania a fost creată.");
      } else if (typeof editing === "number") {
        await adminApi.updateAdCampaign(editing, payload);
        setNotice("Modificările au fost salvate.");
      }
      await loadCampaigns();
      setEditing(null);
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Salvarea a eșuat.");
    } finally {
      setIsSaving(false);
    }
  };

  const remove = async (campaign: AdminAdCampaign) => {
    if (!window.confirm(`Ștergi campania „${campaign.name}"? Acțiunea nu poate fi anulată.`)) return;

    try {
      await adminApi.deleteAdCampaign(campaign.id);
      if (editing === campaign.id) setEditing(null);
      await loadCampaigns();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Ștergerea a eșuat.");
    }
  };

  const label = (text: string, help?: string) => (
    <span className="mb-1.5 flex items-center text-sm font-medium">
      {text}
      {help && <HelpHint>{help}</HelpHint>}
    </span>
  );

  const field = "w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary";

  return (
    <SectionLayout
      title="Reclame"
      description="Alege ce reclamă apare, la ce filme și când. Spectatorii o văd pe site și în aplicații."
      navLabel="Publicitate"
      tabs={[
        { id: "campaigns", label: "Campanii", icon: MegaphoneIcon },
        { id: "stats", label: "Statistici", icon: BarChart3Icon },
      ]}
    >
      {(tab) =>
        tab === "stats" ? (
          <AdStatsPanel campaigns={campaigns} />
        ) : editing === null ? (
        <div className="rounded-2xl border border-border bg-card">
          <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
            <div className="relative min-w-60 flex-1">
              <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Caută după numele campaniei sau al companiei…"
                className={`${field} pl-9`}
              />
            </div>
            {canManage && (
              <button
                type="button"
                onClick={openNew}
                className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
              >
                <PlusIcon className="h-4 w-4" />
                Campanie nouă
              </button>
            )}
          </div>

          {isLoading ? (
            <p className="p-8 text-center text-sm text-muted-foreground">Se încarcă…</p>
          ) : filtered.length === 0 ? (
            <div className="flex flex-col items-center gap-3 p-12 text-center">
              <MegaphoneIcon className="h-8 w-8 text-muted-foreground" />
              <p className="text-sm font-medium">
                {campaigns.length === 0 ? "Nu ai nicio campanie încă." : "Nicio campanie nu corespunde căutării."}
              </p>
              {campaigns.length === 0 && canManage && (
                <button type="button" onClick={openNew} className="text-sm text-primary underline">
                  Creează prima campanie
                </button>
              )}
            </div>
          ) : (
            <ul className="divide-y divide-border">
              {filtered.map((campaign) => (
                <li key={campaign.id}>
                  <button
                    type="button"
                    onClick={() => openCampaign(campaign)}
                    className="flex w-full items-center gap-4 p-4 text-left transition-colors hover:bg-muted/40"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium">{campaign.name}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {campaign.company_name || "fără companie"} ·{" "}
                        {(campaign.placements?.length ? campaign.placements : [campaign.placement])
                          .map((placement: string) => PLACEMENT_LABELS[placement]?.title ?? placement)
                          .join(", ")}
                      </p>
                    </div>

                    <div className="hidden shrink-0 text-right text-xs text-muted-foreground sm:block">
                      <p>{campaign.stats.impressions.toLocaleString("ro-MD")} afișări</p>
                      <p>{campaign.stats.clicks.toLocaleString("ro-MD")} click-uri</p>
                    </div>

                    <span
                      className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                        campaign.is_active && campaign.status === "active"
                          ? "bg-emerald-500/15 text-emerald-500"
                          : "bg-muted text-muted-foreground"
                      }`}
                    >
                      {STATUS_LABELS[campaign.status] ?? campaign.status}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : (
        <div className="space-y-6 rounded-2xl border border-border bg-card p-6">
          <div className="flex items-center justify-between gap-4">
            <h2 className="text-lg font-semibold">
              {editing === "new" ? "Campanie nouă" : "Editează campania"}
            </h2>
            <div className="flex items-center gap-2">
              {typeof editing === "number" && canManage && (
                <button
                  type="button"
                  onClick={() => {
                    const campaign = campaigns.find((item) => item.id === editing);
                    if (campaign) void remove(campaign);
                  }}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-destructive/40 px-3 py-2 text-sm text-destructive transition-colors hover:bg-destructive/10"
                >
                  <Trash2Icon className="h-4 w-4" />
                  Șterge
                </button>
              )}
              <button
                type="button"
                onClick={() => setEditing(null)}
                className="rounded-lg border border-border px-4 py-2 text-sm transition-colors hover:bg-muted"
              >
                Înapoi la listă
              </button>
            </div>
          </div>

          {/* 1. Identity */}
          <section className="space-y-4">
            <h3 className="section-title">1. Despre campanie</h3>
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                {label("Nume campanie", "Doar pentru tine, ca s-o recunoști în listă. Spectatorii nu îl văd.")}
                <input value={form.name} onChange={(e) => update("name", e.target.value)} className={field} />
              </div>
              <div>
                {label("Compania care plătește", "Numele clientului. Apare în rapoartele pe care i le trimiți.")}
                <input
                  value={form.company_name}
                  onChange={(e) => update("company_name", e.target.value)}
                  className={field}
                />
              </div>
            </div>
          </section>

          {/* 2. Creative */}
          <section className="space-y-3">
            <h3 className="section-title">2. Video-ul reclamei</h3>
            <p className="text-xs text-muted-foreground">
              Durata și dimensiunile se citesc automat din fișier — nu trebuie să le completezi.
            </p>
            <AdCreativeUpload value={form.creative} onChange={(creative) => update("creative", creative)} />
            <div>
              {label("Link la click", "Unde ajunge spectatorul dacă apasă pe reclamă. Lasă gol dacă reclama nu e apăsabilă.")}
              <input
                value={form.click_through_url}
                onChange={(e) => update("click_through_url", e.target.value)}
                placeholder="https://client.md/oferta"
                className={field}
              />
            </div>
          </section>

          {/* 3. Placement */}
          <section className="space-y-4">
            <h3 className="section-title">3. Când apare reclama</h3>
            <p className="text-xs text-muted-foreground">Poți alege mai multe momente deodată.</p>

            <div className="grid gap-3 sm:grid-cols-3">
              {Object.entries(PLACEMENT_LABELS).map(([value, meta]) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => togglePlacement(value)}
                  className={`rounded-xl border p-4 text-left transition-colors ${
                    form.placements.includes(value)
                      ? "border-primary bg-primary/10"
                      : "border-border hover:bg-muted/40"
                  }`}
                >
                  <span className="block text-sm font-medium">{meta.title}</span>
                  <span className="mt-1 block text-xs text-muted-foreground">{meta.help}</span>
                </button>
              ))}
            </div>

            {form.placements.includes("mid-roll") && (
              <div className="grid gap-4 rounded-xl border border-border bg-muted/20 p-4 md:grid-cols-2">
                <div>
                  {label("Prima întrerupere, la minutul", "După câte minute de film apare prima reclamă din mijloc.")}
                  <input
                    type="number"
                    min={1}
                    value={form.mid_roll_offset_minutes}
                    onChange={(e) => update("mid_roll_offset_minutes", e.target.value)}
                    placeholder="25"
                    className={field}
                  />
                </div>
                <div>
                  {label("Se repetă la fiecare (minute)", "Lasă gol pentru o singură întrerupere. Dacă pui 20, reclama reapare din 20 în 20 de minute.")}
                  <input
                    type="number"
                    min={1}
                    value={form.mid_roll_every_minutes}
                    onChange={(e) => update("mid_roll_every_minutes", e.target.value)}
                    placeholder="fără repetare"
                    className={field}
                  />
                </div>
              </div>
            )}

            <div className="grid gap-4 md:grid-cols-3">
              <div>
                {label("Poate fi sărită după (secunde)", "Butonul „Sari peste” apare după atâtea secunde. Lasă gol dacă reclama nu poate fi sărită.")}
                <input
                  type="number"
                  min={0}
                  value={form.skip_offset_seconds}
                  onChange={(e) => update("skip_offset_seconds", e.target.value)}
                  className={field}
                />
              </div>
              <div>
                {label("Maxim pe vizionare", "De câte ori poate apărea această reclamă în timpul unui singur film. Lasă gol pentru nelimitat.")}
                <input
                  type="number"
                  min={1}
                  value={form.frequency_cap_per_session}
                  onChange={(e) => update("frequency_cap_per_session", e.target.value)}
                  placeholder="nelimitat"
                  className={field}
                />
              </div>
              <div>
                {label("Maxim pe zi / spectator", "De câte ori vede un spectator această reclamă într-o zi, pe toate filmele.")}
                <input
                  type="number"
                  min={1}
                  value={form.frequency_cap_per_day}
                  onChange={(e) => update("frequency_cap_per_day", e.target.value)}
                  placeholder="nelimitat"
                  className={field}
                />
              </div>
            </div>
          </section>

          {/* 4. Targeting */}
          <section className="space-y-4">
            <h3 className="section-title">4. La ce filme apare</h3>

            <div className="grid gap-3 sm:grid-cols-2">
              <button
                type="button"
                onClick={() => update("scope_mode", "all")}
                className={`rounded-xl border p-4 text-left transition-colors ${
                  form.scope_mode === "all" ? "border-primary bg-primary/10" : "border-border hover:bg-muted/40"
                }`}
              >
                <span className="block text-sm font-medium">La toate filmele</span>
                <span className="mt-1 block text-xs text-muted-foreground">
                  Reclama rulează peste tot. Poți exclude anumite filme.
                </span>
              </button>

              <button
                type="button"
                onClick={() => update("scope_mode", "selected")}
                className={`rounded-xl border p-4 text-left transition-colors ${
                  form.scope_mode === "selected" ? "border-primary bg-primary/10" : "border-border hover:bg-muted/40"
                }`}
              >
                <span className="block text-sm font-medium">Doar la filme alese</span>
                <span className="mt-1 block text-xs text-muted-foreground">
                  Reclama rulează exclusiv la filmele pe care le adaugi.
                </span>
              </button>
            </div>

            {form.scope_mode === "selected" ? (
              <div>
                {label("Filmele la care apare", "Reclama rulează doar la aceste filme. Caută după titlu și adaugă câte vrei.")}
                <ContentMultiSelect
                  contents={options?.contents ?? []}
                  value={form.target_content_ids}
                  onChange={(ids) => update("target_content_ids", ids)}
                  emptyLabel="Adaugă cel puțin un film, altfel reclama nu apare nicăieri."
                />
              </div>
            ) : (
              <div>
                {label("Cu excepția acestor filme", "Opțional. Filme la care reclama nu trebuie să apară — restul catalogului rămâne acoperit.")}
                <ContentMultiSelect
                  contents={options?.contents ?? []}
                  value={form.target_excluded_content_ids}
                  onChange={(ids) => update("target_excluded_content_ids", ids)}
                  emptyLabel="Nicio excludere — apare la tot catalogul."
                />
              </div>
            )}

            <div>
              {label("Pe ce dispozitive", "Lasă toate nebifate ca să apară peste tot.")}
              <div className="flex flex-wrap gap-2">
                {(options?.platforms ?? []).map((platform) => (
                  <button
                    key={platform}
                    type="button"
                    onClick={() => toggleInList("target_platforms", platform)}
                    className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${
                      form.target_platforms.includes(platform)
                        ? "border-primary bg-primary/10"
                        : "border-border hover:bg-muted/40"
                    }`}
                  >
                    {PLATFORM_LABELS[platform] ?? platform}
                  </button>
                ))}
              </div>
            </div>

            <div>
              {label("Doar la filme cu aceste clasificări de vârstă", "Lasă nebifat pentru toate clasificările. Util dacă reclama nu e potrivită pentru filme de familie.")}
              <div className="flex flex-wrap gap-2">
                {(options?.age_ratings ?? []).map((rating) => (
                  <button
                    key={rating.value}
                    type="button"
                    onClick={() => toggleInList("target_age_ratings", rating.value)}
                    className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${
                      form.target_age_ratings.includes(rating.value)
                        ? "border-primary bg-primary/10"
                        : "border-border hover:bg-muted/40"
                    }`}
                  >
                    {rating.label}
                  </button>
                ))}
              </div>
            </div>

            <label className="flex items-start gap-3 rounded-xl border border-border p-4">
              <input
                type="checkbox"
                checked={form.exclude_kids_profiles}
                onChange={(e) => update("exclude_kids_profiles", e.target.checked)}
                className="mt-0.5 h-4 w-4"
              />
              <span>
                <span className="block text-sm font-medium">Nu arăta reclama pe profilurile de copii</span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                  Recomandat. Debifează doar dacă reclama e făcută special pentru copii.
                </span>
              </span>
            </label>
          </section>

          {/* 5. Schedule and state */}
          <section className="space-y-4">
            <h3 className="section-title">5. Perioadă și stare</h3>
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                {label("Începe la (ora Moldovei)", "Ora Chișinăului, indiferent de fusul orar al calculatorului tău. Lasă gol ca să pornească imediat ce o activezi.")}
                <input
                  type="datetime-local"
                  value={form.starts_at}
                  onChange={(e) => update("starts_at", e.target.value)}
                  className={field}
                />
              </div>
              <div>
                {label("Se termină la (ora Moldovei)", "Ora Chișinăului, indiferent de fusul orar al calculatorului tău. Lasă gol ca să ruleze până o oprești manual.")}
                <input
                  type="datetime-local"
                  value={form.ends_at}
                  onChange={(e) => update("ends_at", e.target.value)}
                  className={field}
                />
              </div>
            </div>
            {(() => {
              const summary = scheduleSummary(form.starts_at, form.ends_at);
              if (!summary) return null;
              const tone = {
                ok: "border-emerald-200 bg-emerald-50 text-emerald-800",
                wait: "border-amber-200 bg-amber-50 text-amber-800",
                bad: "border-red-200 bg-red-50 text-red-700",
              }[summary.tone];
              return <p className={`rounded-lg border px-4 py-2.5 text-sm ${tone}`}>{summary.text}</p>;
            })()}

            <div className="grid gap-4 md:grid-cols-2">
              <div>
                {label("Stare", "„Activă” înseamnă că rulează. „Ciornă” o ține ascunsă până termini de configurat.")}
                <select value={form.status} onChange={(e) => update("status", e.target.value)} className={field}>
                  {(options?.statuses ?? ["draft", "active", "paused", "completed"]).map((status) => (
                    <option key={status} value={status}>
                      {STATUS_LABELS[status] ?? status}
                    </option>
                  ))}
                </select>
              </div>
              <label className="flex items-center gap-3 self-end rounded-lg border border-border px-4 py-2.5">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => update("is_active", e.target.checked)}
                  className="h-4 w-4"
                />
                <span className="text-sm">Campanie pornită</span>
              </label>
            </div>
          </section>

          {/* 6. Advanced */}
          <details className="rounded-xl border border-border p-4">
            <summary className="cursor-pointer text-sm font-medium">Setări avansate (opțional)</summary>
            <div className="mt-4 grid gap-4 md:grid-cols-2">
              <div>
                {label(
                  "VAST tag URL extern",
                  "Doar dacă reclama vine de la o rețea de publicitate externă (de exemplu Google Ad Manager), care îți dă un link. Dacă ai încărcat video-ul mai sus, lasă gol.",
                )}
                <input
                  value={form.vast_tag_url}
                  onChange={(e) => update("vast_tag_url", e.target.value)}
                  placeholder="lasă gol dacă ai încărcat video-ul"
                  className={field}
                />
              </div>
              <div>
                {label("Prioritate", "Dacă două campanii se potrivesc la același film, rulează cea cu numărul mai mare.")}
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  value={form.bid_amount}
                  onChange={(e) => update("bid_amount", e.target.value)}
                  placeholder="0"
                  className={field}
                />
              </div>
            </div>
          </details>

          {error && <p className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{error}</p>}
          {notice && <p className="rounded-lg bg-emerald-500/10 p-3 text-sm text-emerald-500">{notice}</p>}

          {canManage && (
            <div className="flex items-center gap-3 border-t border-border pt-4">
              <button
                type="button"
                onClick={() => void save()}
                disabled={isSaving || form.name.trim() === ""}
                className="rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
              >
                {isSaving ? "Se salvează…" : editing === "new" ? "Creează campania" : "Salvează modificările"}
              </button>
              <button
                type="button"
                onClick={() => setEditing(null)}
                className="rounded-lg border border-border px-5 py-2.5 text-sm transition-colors hover:bg-muted"
              >
                Anulează
              </button>
            </div>
          )}
        </div>
        )
      }
    </SectionLayout>
  );
}

/** Picks a campaign, then shows its performance report. */
function AdStatsPanel({ campaigns }: { campaigns: AdminAdCampaign[] }) {
  const [selected, setSelected] = useState<number | null>(campaigns[0]?.id ?? null);

  if (campaigns.length === 0) {
    return (
      <p className="rounded-2xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
        Creează o campanie ca să vezi statistici.
      </p>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <label className="mb-1.5 block text-sm font-medium">Campanie</label>
        <select
          value={selected ?? ""}
          onChange={(event) => setSelected(Number(event.target.value))}
          className="w-full max-w-md rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
        >
          {campaigns.map((campaign) => (
            <option key={campaign.id} value={campaign.id}>
              {campaign.name}
            </option>
          ))}
        </select>
      </div>

      {selected !== null && <AdCampaignStats campaignId={selected} />}
    </div>
  );
}
