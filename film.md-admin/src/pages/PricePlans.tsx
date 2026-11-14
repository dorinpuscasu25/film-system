import { useEffect, useMemo, useState, type ReactNode } from "react";
import { LayersIcon, PencilIcon, PlusIcon, SendIcon, Trash2Icon, XIcon } from "lucide-react";
import { Modal } from "../components/shared/Modal";
import { ContentMultiSelect } from "../components/shared/ContentMultiSelect";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi, type ApiRequestError } from "../lib/api";
import type {
  AdCampaignOptionContent,
  PricePlan,
  PricePlanApplyResult,
  PricePlanIndexResponse,
  PricePlanOfferType,
  PricePlanPayload,
  PricePlanPreview,
} from "../types";

const DEFAULT_QUALITIES = ["SD", "HD", "Full HD", "4K"];

const OFFER_TYPE_LABELS: Record<PricePlanOfferType, string> = {
  rental: "Închiriere",
  lifetime: "Pentru totdeauna",
};

interface ItemForm {
  offer_type: PricePlanOfferType;
  quality: string;
  price_amount: string;
  rental_days: string;
}

interface PlanForm {
  name: string;
  description: string;
  currency: string;
  sort_order: string;
  items: ItemForm[];
}

const EMPTY_FORM: PlanForm = {
  name: "",
  description: "",
  currency: "MDL",
  sort_order: "0",
  items: [{ offer_type: "rental", quality: "HD", price_amount: "", rental_days: "2" }],
};

const selectClassName =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring";

function formatMoney(amount: number, currency: string) {
  return `${Number(amount).toLocaleString("ro-RO", { maximumFractionDigits: 2 })} ${currency}`;
}

function accessLabel(offerType: string, rentalDays: number | null | undefined) {
  if (offerType === "rental") {
    return `Închiriere ${rentalDays ?? "?"} ${rentalDays === 1 ? "zi" : "zile"}`;
  }

  return OFFER_TYPE_LABELS[offerType as PricePlanOfferType] ?? offerType;
}

function firstError(error: unknown, fallback: string) {
  const apiError = error as ApiRequestError;
  const fieldError = apiError?.errors ? Object.values(apiError.errors)[0]?.[0] : undefined;

  return fieldError ?? apiError?.message ?? fallback;
}

export function PricePlans() {
  const { can } = useAdmin();
  const canCreate = can("commerce.create_offers");
  const canEdit = can("commerce.edit_offers");

  const [plans, setPlans] = useState<PricePlan[]>([]);
  const [options, setOptions] = useState<PricePlanIndexResponse["options"] | null>(null);
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Plan editor
  const [editorOpen, setEditorOpen] = useState(false);
  const [editing, setEditing] = useState<PricePlan | null>(null);
  const [form, setForm] = useState<PlanForm>(EMPTY_FORM);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState<PricePlanPreview | null>(null);

  // Apply to films
  const [applyPlan, setApplyPlan] = useState<PricePlan | null>(null);
  const [contents, setContents] = useState<AdCampaignOptionContent[]>([]);
  const [selectedContentIds, setSelectedContentIds] = useState<number[]>([]);
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);
  const [applyResults, setApplyResults] = useState<PricePlanApplyResult[] | null>(null);

  const qualities = options?.qualities ?? DEFAULT_QUALITIES;

  async function load() {
    setLoading(true);
    setPageError(null);
    try {
      const response = await adminApi.getPricePlans();
      setPlans(response.items);
      setOptions(response.options);
    } catch {
      setPageError("Nu am putut încărca planurile de preț.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function startCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFormError(null);
    setPreview(null);
    setEditorOpen(true);
  }

  function startEdit(plan: PricePlan) {
    setEditing(plan);
    setForm({
      name: plan.name,
      description: plan.description ?? "",
      currency: plan.currency,
      sort_order: String(plan.sort_order ?? 0),
      items: plan.items.map((item) => ({
        offer_type: item.offer_type,
        quality: item.quality,
        price_amount: String(item.price_amount),
        rental_days: item.rental_days ? String(item.rental_days) : "",
      })),
    });
    setFormError(null);
    setPreview(null);
    setEditorOpen(true);
  }

  function updateItem(index: number, patch: Partial<ItemForm>) {
    setPreview(null);
    setForm((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) => (itemIndex === index ? { ...item, ...patch } : item)),
    }));
  }

  function addItem() {
    setPreview(null);
    setForm((current) => {
      const used = new Set(current.items.map((item) => `${item.offer_type}|${item.quality}`));
      const next =
        (["rental", "lifetime"] as PricePlanOfferType[])
          .flatMap((offerType) => qualities.map((quality) => ({ offer_type: offerType, quality })))
          .find((candidate) => !used.has(`${candidate.offer_type}|${candidate.quality}`)) ??
        { offer_type: "rental" as PricePlanOfferType, quality: qualities[0] };

      return {
        ...current,
        items: [
          ...current.items,
          { ...next, price_amount: "", rental_days: next.offer_type === "rental" ? "2" : "" },
        ],
      };
    });
  }

  function removeItem(index: number) {
    setPreview(null);
    setForm((current) => ({ ...current, items: current.items.filter((_, itemIndex) => itemIndex !== index) }));
  }

  function buildPayload(): PricePlanPayload | null {
    if (!form.name.trim()) {
      setFormError("Dă un nume planului.");
      return null;
    }

    if (form.items.length === 0) {
      setFormError("Adaugă cel puțin un preț.");
      return null;
    }

    const seen = new Set<string>();
    for (const item of form.items) {
      const key = `${item.offer_type}|${item.quality}`;
      if (seen.has(key)) {
        setFormError(`Rândul „${OFFER_TYPE_LABELS[item.offer_type]} · ${item.quality}” apare de două ori.`);
        return null;
      }
      seen.add(key);

      if (item.price_amount === "" || Number(item.price_amount) < 0) {
        setFormError(`Setează prețul pentru „${OFFER_TYPE_LABELS[item.offer_type]} · ${item.quality}”.`);
        return null;
      }

      if (item.offer_type === "rental" && !(Number(item.rental_days) > 0)) {
        setFormError(`Setează numărul de zile pentru închirierea ${item.quality}.`);
        return null;
      }
    }

    return {
      name: form.name.trim(),
      description: form.description.trim() || null,
      currency: (form.currency || "MDL").toUpperCase(),
      sort_order: Number(form.sort_order || 0),
      items: form.items.map((item) => ({
        offer_type: item.offer_type,
        quality: item.quality,
        price_amount: Number(item.price_amount),
        rental_days: item.offer_type === "rental" ? Number(item.rental_days) : null,
      })),
    };
  }

  async function submit() {
    setFormError(null);
    const payload = buildPayload();
    if (!payload) {
      return;
    }

    setSaving(true);
    try {
      if (!editing) {
        await adminApi.createPricePlan(payload);
        setNotice(`Planul „${payload.name}” a fost creat. Aplică-l acum pe filme.`);
        setEditorOpen(false);
        await load();
        return;
      }

      // First save of an edited plan with linked offers: show what will change, ask to confirm.
      if (preview === null && editing.offers_count > 0) {
        const nextPreview = await adminApi.previewPricePlan(editing.id, payload);
        if (nextPreview.updated.length > 0 || nextPreview.detached.length > 0) {
          setPreview(nextPreview);
          return;
        }
      }

      const response = await adminApi.updatePricePlan(editing.id, payload);
      const { updated_offers, detached_offers, affected_contents } = response.result;
      setNotice(
        updated_offers + detached_offers > 0
          ? `Planul „${payload.name}” a fost salvat: ${updated_offers} oferte actualizate în ${affected_contents} filme${
              detached_offers > 0 ? `, ${detached_offers} dezlegate` : ""
            }.`
          : `Planul „${payload.name}” a fost salvat.`,
      );
      setEditorOpen(false);
      setPreview(null);
      await load();
    } catch (error) {
      setFormError(firstError(error, "Salvarea a eșuat. Verifică valorile."));
    } finally {
      setSaving(false);
    }
  }

  async function remove(plan: PricePlan) {
    const message =
      plan.offers_count > 0
        ? `Ștergi planul „${plan.name}”? Cele ${plan.offers_count} oferte legate (${plan.contents_count} filme) rămân cu prețul actual, dar nu se mai actualizează din plan.`
        : `Ștergi planul „${plan.name}”?`;
    if (!confirm(message)) {
      return;
    }

    try {
      await adminApi.deletePricePlan(plan.id);
      setNotice(`Planul „${plan.name}” a fost șters.`);
      await load();
    } catch (error) {
      setPageError(firstError(error, "Nu am putut șterge planul."));
    }
  }

  async function startApply(plan: PricePlan) {
    setApplyPlan(plan);
    setSelectedContentIds([]);
    setApplyResults(null);
    setApplyError(null);

    if (contents.length === 0) {
      try {
        const response = await adminApi.getContentIndex();
        setContents(
          response.items.map((content) => ({ id: content.id, title: content.original_title, slug: content.slug })),
        );
      } catch {
        setApplyError("Nu am putut încărca lista de filme.");
      }
    }
  }

  async function submitApply() {
    if (!applyPlan || selectedContentIds.length === 0) {
      return;
    }

    setApplying(true);
    setApplyError(null);
    try {
      const response = await adminApi.applyPricePlan(applyPlan.id, selectedContentIds);
      setApplyResults(response.results);
      await load();
    } catch (error) {
      setApplyError(firstError(error, "Nu am putut aplica planul."));
    } finally {
      setApplying(false);
    }
  }

  const previewContentsCount = useMemo(
    () => new Set([...(preview?.updated ?? []), ...(preview?.detached ?? [])].map((offer) => offer.content_id)).size,
    [preview],
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Planuri de preț</h1>
          <p className="page-description">
            Setează prețurile o singură dată și leagă-le de filme. Când schimbi prețul unui plan, se actualizează
            automat toate filmele care îl folosesc.
          </p>
        </div>

        {canCreate ? (
          <Button onClick={startCreate}>
            <PlusIcon className="h-4 w-4" />
            Plan nou
          </Button>
        ) : null}
      </div>

      {notice ? (
        <div className="flex items-start justify-between gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm">
          <span>{notice}</span>
          <button type="button" aria-label="Închide" onClick={() => setNotice(null)}>
            <XIcon className="h-4 w-4" />
          </button>
        </div>
      ) : null}
      {pageError ? (
        <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {pageError}
        </div>
      ) : null}

      {loading ? (
        <div className="p-10 text-center text-sm text-muted-foreground">Se încarcă…</div>
      ) : plans.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
            <div className="rounded-md border bg-muted p-3">
              <LayersIcon className="h-5 w-5" />
            </div>
            <div className="font-medium">Niciun plan de preț încă</div>
            <p className="max-w-md text-sm text-muted-foreground">
              Creează, de exemplu, „Standard”, „Premieră” și „Clasic”, apoi aplică-le pe filme. Ofertele legate își
              iau prețul din plan.
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {plans.map((plan) => (
            <Card key={plan.id}>
              <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                <div className="min-w-0 space-y-1">
                  <CardTitle className="truncate">{plan.name}</CardTitle>
                  <CardDescription>
                    {plan.offers_count > 0
                      ? `${plan.contents_count} ${plan.contents_count === 1 ? "film" : "filme"} · ${plan.offers_count} oferte legate`
                      : "Nefolosit încă"}
                  </CardDescription>
                </div>
                {canEdit ? (
                  <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" onClick={() => void startApply(plan)}>
                      <SendIcon className="h-4 w-4" />
                      Aplică pe filme
                    </Button>
                    <Button variant="outline" size="icon" onClick={() => startEdit(plan)} title="Editează">
                      <PencilIcon className="h-4 w-4" />
                    </Button>
                    <Button variant="outline" size="icon" onClick={() => void remove(plan)} title="Șterge">
                      <Trash2Icon className="h-4 w-4 text-destructive" />
                    </Button>
                  </div>
                ) : null}
              </CardHeader>
              <CardContent className="space-y-3 pt-0">
                {plan.description ? <p className="text-sm text-muted-foreground">{plan.description}</p> : null}
                <div className="divide-y rounded-lg border">
                  {plan.items.map((item) => (
                    <div
                      key={`${item.offer_type}-${item.quality}`}
                      className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                    >
                      <span>
                        <span className="font-medium">{item.quality}</span>
                        <span className="text-muted-foreground"> · {accessLabel(item.offer_type, item.rental_days)}</span>
                      </span>
                      <span className="font-medium tabular-nums">{formatMoney(item.price_amount, plan.currency)}</span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={editorOpen}
        onClose={() => setEditorOpen(false)}
        title={editing ? `Editează „${editing.name}”` : "Plan de preț nou"}
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => (preview ? setPreview(null) : setEditorOpen(false))}>
              {preview ? "Înapoi" : "Anulează"}
            </Button>
            <Button onClick={() => void submit()} disabled={saving}>
              {saving ? "Se salvează…" : preview ? "Confirmă și aplică" : "Salvează"}
            </Button>
          </>
        }
      >
        {preview ? (
          <div className="space-y-4">
            <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
              Salvarea modifică <strong>{preview.updated.length}</strong> oferte din{" "}
              <strong>{previewContentsCount}</strong> filme. Prețul nou se vede imediat pe web, iOS și TV. Cumpărările
              deja făcute nu se schimbă.
            </div>
            {preview.updated.length > 0 ? (
              <ChangeList title="Se actualizează">
                {preview.updated.map((offer) => (
                  <ChangeRow
                    key={offer.offer_id}
                    title={offer.content_title ?? `Film #${offer.content_id}`}
                    detail={`${offer.quality} · ${accessLabel(offer.offer_type, offer.new_rental_days ?? offer.rental_days)}`}
                    value={
                      <>
                        <span className="text-muted-foreground line-through">
                          {formatMoney(offer.price_amount, offer.currency)}
                        </span>{" "}
                        → {formatMoney(offer.new_price_amount ?? offer.price_amount, offer.new_currency ?? offer.currency)}
                      </>
                    }
                  />
                ))}
              </ChangeList>
            ) : null}
            {preview.detached.length > 0 ? (
              <ChangeList title="Se dezleagă de plan (rămân cu prețul actual)">
                {preview.detached.map((offer) => (
                  <ChangeRow
                    key={offer.offer_id}
                    title={offer.content_title ?? `Film #${offer.content_id}`}
                    detail={`${offer.quality} · ${accessLabel(offer.offer_type, offer.rental_days)}`}
                    value={formatMoney(offer.price_amount, offer.currency)}
                  />
                ))}
              </ChangeList>
            ) : null}
            {preview.unchanged_count > 0 ? (
              <p className="text-xs text-muted-foreground">{preview.unchanged_count} oferte legate rămân la fel.</p>
            ) : null}
          </div>
        ) : (
          <div className="space-y-5">
            <div className="grid gap-4 md:grid-cols-[1fr_120px]">
              <Field label="Nume">
                <Input
                  value={form.name}
                  placeholder="ex. Standard, Premieră, Clasic"
                  onChange={(event) => setForm({ ...form, name: event.target.value })}
                />
              </Field>
              <Field label="Monedă">
                <Input
                  value={form.currency}
                  maxLength={3}
                  onChange={(event) => {
                    setPreview(null);
                    setForm({ ...form, currency: event.target.value.toUpperCase() });
                  }}
                />
              </Field>
              <Field label="Descriere (opțional)" span={2}>
                <Input
                  value={form.description}
                  placeholder="ex. Filme noi, primele 3 luni după lansare"
                  onChange={(event) => setForm({ ...form, description: event.target.value })}
                />
              </Field>
            </div>

            <div className="space-y-2">
              <div className="text-sm font-medium text-muted-foreground">Prețuri</div>
              <div className="space-y-2">
                {form.items.map((item, index) => (
                  <div
                    key={index}
                    className="grid grid-cols-2 items-end gap-2 rounded-lg border bg-muted/30 p-3 md:grid-cols-[1.3fr_1fr_1fr_0.8fr_auto]"
                  >
                    <Field label="Tip">
                      <select
                        className={selectClassName}
                        value={item.offer_type}
                        onChange={(event) => {
                          const offerType = event.target.value as PricePlanOfferType;
                          updateItem(index, {
                            offer_type: offerType,
                            rental_days: offerType === "rental" ? item.rental_days || "2" : "",
                          });
                        }}
                      >
                        <option value="rental">{OFFER_TYPE_LABELS.rental}</option>
                        <option value="lifetime">{OFFER_TYPE_LABELS.lifetime}</option>
                      </select>
                    </Field>
                    <Field label="Calitate">
                      <select
                        className={selectClassName}
                        value={item.quality}
                        onChange={(event) => updateItem(index, { quality: event.target.value })}
                      >
                        {qualities.map((quality) => (
                          <option key={quality} value={quality}>
                            {quality}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label={`Preț (${form.currency || "MDL"})`}>
                      <Input
                        type="number"
                        min={0}
                        step="0.01"
                        value={item.price_amount}
                        onChange={(event) => updateItem(index, { price_amount: event.target.value })}
                      />
                    </Field>
                    <Field label="Zile">
                      <Input
                        type="number"
                        min={1}
                        max={365}
                        disabled={item.offer_type !== "rental"}
                        value={item.offer_type === "rental" ? item.rental_days : ""}
                        placeholder={item.offer_type === "rental" ? "" : "—"}
                        onChange={(event) => updateItem(index, { rental_days: event.target.value })}
                      />
                    </Field>
                    <Button
                      variant="outline"
                      size="icon"
                      onClick={() => removeItem(index)}
                      title="Elimină rândul"
                      disabled={form.items.length === 1}
                    >
                      <Trash2Icon className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
              <Button variant="outline" size="sm" onClick={addItem}>
                <PlusIcon className="h-4 w-4" />
                Adaugă preț
              </Button>
            </div>

            {editing && editing.offers_count > 0 ? (
              <p className="text-xs text-muted-foreground">
                Planul e folosit de {editing.contents_count} filme. Înainte de salvare îți arăt ce oferte se schimbă.
                Dacă elimini un rând, ofertele lui se dezleagă și își păstrează prețul.
              </p>
            ) : null}

            {formError ? (
              <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                {formError}
              </div>
            ) : null}
          </div>
        )}
        {preview && formError ? (
          <div className="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            {formError}
          </div>
        ) : null}
      </Modal>

      <Modal
        isOpen={applyPlan !== null}
        onClose={() => setApplyPlan(null)}
        title={applyPlan ? `Aplică „${applyPlan.name}” pe filme` : ""}
        size="lg"
        footer={
          applyResults ? (
            <Button onClick={() => setApplyPlan(null)}>Gata</Button>
          ) : (
            <>
              <Button variant="outline" onClick={() => setApplyPlan(null)}>
                Anulează
              </Button>
              <Button onClick={() => void submitApply()} disabled={applying || selectedContentIds.length === 0}>
                {applying
                  ? "Se aplică…"
                  : `Aplică pe ${selectedContentIds.length} ${selectedContentIds.length === 1 ? "film" : "filme"}`}
              </Button>
            </>
          )
        }
      >
        {applyResults ? (
          <ChangeList title="Rezultat">
            {applyResults.map((result) => (
              <ChangeRow
                key={result.content_id}
                title={result.content_title}
                detail={
                  result.skipped_qualities.length > 0
                    ? `Sărite (nu există video în calitatea asta): ${result.skipped_qualities.join(", ")}`
                    : "Toate prețurile din plan au fost aplicate"
                }
                value={`${result.linked} actualizate · ${result.created} create`}
              />
            ))}
          </ChangeList>
        ) : (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Pentru fiecare film, ofertele existente de același tip și aceeași calitate primesc prețul planului și
              rămân legate de el. Ofertele lipsă se creează doar pentru calitățile în care filmul are video încărcat.
            </p>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={contents.length === 0}
                onClick={() => setSelectedContentIds(contents.map((content) => content.id))}
              >
                Selectează toate filmele ({contents.length})
              </Button>
              {selectedContentIds.length > 0 ? (
                <Button variant="ghost" size="sm" onClick={() => setSelectedContentIds([])}>
                  Golește selecția
                </Button>
              ) : null}
            </div>
            <ContentMultiSelect
              contents={contents}
              value={selectedContentIds}
              onChange={setSelectedContentIds}
              emptyLabel="Niciun film selectat."
            />
            {applyError ? (
              <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                {applyError}
              </div>
            ) : null}
          </div>
        )}
      </Modal>
    </div>
  );
}

function ChangeList({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="space-y-2">
      <div className="text-sm font-medium">{title}</div>
      <div className="max-h-72 divide-y overflow-y-auto rounded-lg border">{children}</div>
    </div>
  );
}

function ChangeRow({ title, detail, value }: { title: string; detail: string; value: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
      <div className="min-w-0">
        <div className="truncate font-medium">{title}</div>
        <div className="truncate text-xs text-muted-foreground">{detail}</div>
      </div>
      <div className="shrink-0 text-right tabular-nums">{value}</div>
    </div>
  );
}

function Field({ label, span = 1, children }: { label: string; span?: 1 | 2; children: ReactNode }) {
  return (
    <label className={`flex flex-col gap-2 text-sm font-medium ${span === 2 ? "md:col-span-2" : ""}`}>
      <span className="text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}
