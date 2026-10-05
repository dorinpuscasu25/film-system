import { useEffect, useState, type ElementType } from "react";
import { useTranslation } from "react-i18next";
import {
  DollarSignIcon,
  GiftIcon,
  HardDriveIcon,
  KeyRoundIcon,
  PencilIcon,
  PlusIcon,
  RefreshCwIcon,
  SaveIcon,
  SmartphoneIcon,
  TagIcon,
  TrashIcon,
} from "lucide-react";
import { Button } from "../components/ui/button";
import { SectionLayout } from "../components/shared/SectionLayout";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { adminApi } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";
import { FormField } from "../components/shared/FormField";

interface PriceForm {
  storage_cost_per_gb_day: string;
  delivery_cost_per_gb: string;
  drm_cost_per_license: string;
  usd_to_mdl_rate: string;
}

const EMPTY_FORM: PriceForm = {
  storage_cost_per_gb_day: "",
  delivery_cost_per_gb: "",
  drm_cost_per_license: "",
  usd_to_mdl_rate: "",
};

const ORIGINAL_DEFAULTS = {
  storage_cost_per_gb_day: 0.0035,
  delivery_cost_per_gb: 0.005,
  drm_cost_per_license: 0.005,
  usd_to_mdl_rate: 17.5,
};

interface RegistrationCreditCampaignForm {
  label: string;
  amount: string;
  starts_at: string;
  ends_at: string;
  enabled: boolean;
}

interface RegistrationCreditForm {
  enabled: boolean;
  default_amount: string;
  currency: string;
  campaigns: RegistrationCreditCampaignForm[];
}

const EMPTY_REGISTRATION_CREDIT_FORM: RegistrationCreditForm = {
  enabled: true,
  default_amount: "20",
  currency: "MDL",
  campaigns: [],
};

interface IapPackForm {
  product_id: string;
  credits_mdl: string;
  apple_price_usd: string;
  sort_order: string;
}

interface IapPacksForm {
  commission_rate: string;
  packs: IapPackForm[];
}

const EMPTY_IAP_PACKS_FORM: IapPacksForm = {
  commission_rate: "15",
  packs: [],
};

export function PriceSettings() {
  const { t } = useTranslation();
  const { can } = useAdmin();
  const canEdit = can("commerce.manage_costs");

  const [form, setForm] = useState<PriceForm>(EMPTY_FORM);
  const [editing, setEditing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [registrationCredit, setRegistrationCredit] = useState<RegistrationCreditForm>(EMPTY_REGISTRATION_CREDIT_FORM);
  const [savingRegistrationCredit, setSavingRegistrationCredit] = useState(false);
  const [registrationCreditMessage, setRegistrationCreditMessage] = useState<string | null>(null);
  const [iapPacks, setIapPacks] = useState<IapPacksForm>(EMPTY_IAP_PACKS_FORM);
  const [savingIapPacks, setSavingIapPacks] = useState(false);
  const [iapPacksMessage, setIapPacksMessage] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    try {
      const [res, platformSettings] = await Promise.all([
        adminApi.getCostSettings(),
        adminApi.getPlatformSettings(),
      ]);
      if (res.current) {
        setForm({
          storage_cost_per_gb_day: String(res.current.storage_cost_per_gb_day ?? ""),
          delivery_cost_per_gb: String(res.current.delivery_cost_per_gb ?? ""),
          drm_cost_per_license: String(res.current.drm_cost_per_license ?? ""),
          usd_to_mdl_rate: String(res.current.usd_to_mdl_rate ?? ""),
        });
        setSavedAt(res.current.effective_from ?? null);
      }
      setRegistrationCredit(mapRegistrationCreditSettings(platformSettings.settings.registration_credit));
      setIapPacks(mapIapPacksSettings(platformSettings.settings.iap_credit_packs));
    } catch {
      setError("Nu s-au putut încărca prețurile.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  async function save() {
    setSaving(true);
    setError(null);
    try {
      await adminApi.saveCostSettings({
        storage_cost_per_gb_day: Number(form.storage_cost_per_gb_day),
        delivery_cost_per_gb: Number(form.delivery_cost_per_gb),
        drm_cost_per_license: Number(form.drm_cost_per_license),
        usd_to_mdl_rate: Number(form.usd_to_mdl_rate),
      });
      setEditing(false);
      await load();
    } catch {
      setError("Salvarea a eșuat. Verifică valorile.");
    } finally {
      setSaving(false);
    }
  }

  async function saveRegistrationCredit() {
    setSavingRegistrationCredit(true);
    setRegistrationCreditMessage(null);
    setError(null);

    try {
      await adminApi.savePlatformSettings({
        registration_credit: {
          enabled: registrationCredit.enabled,
          default_amount: Number(registrationCredit.default_amount || 0),
          currency: registrationCredit.currency || "MDL",
          campaigns: registrationCredit.campaigns.map((campaign) => ({
            label: campaign.label,
            amount: Number(campaign.amount || 0),
            starts_at: campaign.starts_at || null,
            ends_at: campaign.ends_at || null,
            enabled: campaign.enabled,
          })),
        },
      });
      setRegistrationCreditMessage("Creditul de înregistrare a fost salvat.");
      await load();
    } catch {
      setError("Nu am putut salva creditul de înregistrare.");
    } finally {
      setSavingRegistrationCredit(false);
    }
  }

  async function saveIapPacks() {
    setSavingIapPacks(true);
    setIapPacksMessage(null);
    setError(null);

    try {
      await adminApi.savePlatformSettings({
        iap_credit_packs: {
          commission_rate: Number(iapPacks.commission_rate || 0) / 100,
          packs: iapPacks.packs.map((pack, index) => ({
            product_id: pack.product_id.trim(),
            credits_mdl: Number(pack.credits_mdl || 0),
            apple_price_usd: pack.apple_price_usd ? Number(pack.apple_price_usd) : null,
            sort_order: Number(pack.sort_order || index + 1),
            is_placeholder: false,
          })),
        },
      });
      setIapPacksMessage("Pachetele App Store au fost salvate.");
      await load();
    } catch {
      setError("Nu am putut salva pachetele App Store.");
    } finally {
      setSavingIapPacks(false);
    }
  }

  function updateIapPack(index: number, patch: Partial<IapPackForm>) {
    setIapPacks((current) => ({
      ...current,
      packs: current.packs.map((pack, packIndex) => (packIndex === index ? { ...pack, ...patch } : pack)),
    }));
  }

  function updateCampaign(index: number, patch: Partial<RegistrationCreditCampaignForm>) {
    setRegistrationCredit((current) => ({
      ...current,
      campaigns: current.campaigns.map((campaign, campaignIndex) =>
        campaignIndex === index ? { ...campaign, ...patch } : campaign,
      ),
    }));
  }

  if (loading) {
    return (
      <Card>
        <CardContent className="p-10 text-center text-sm text-muted-foreground">{t("common.loading")}…</CardContent>
      </Card>
    );
  }

  return (
    <SectionLayout
      title="Setări prețuri"
      description="Costurile de stocare, livrare și DRM, creditul de bun venit și pachetele vândute în aplicația iOS."
      navLabel="Prețuri"
      tabs={[
        { id: "costs", label: "Prețuri active", icon: DollarSignIcon },
        { id: "credit", label: "Credit la înregistrare", icon: GiftIcon },
        { id: "iap", label: "Pachete App Store", icon: SmartphoneIcon },
      ]}
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" onClick={() => void load()}>
            <RefreshCwIcon className="h-4 w-4" />
            Reîncarcă
          </Button>
          {!editing && canEdit ? (
            <Button onClick={() => setEditing(true)}>
              <PencilIcon className="h-4 w-4" />
              Editează
            </Button>
          ) : null}
        </div>
      }
    >
      {(tab) => (
        <div className="space-y-6">
          {error ? (
            <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
              {error}
            </div>
          ) : null}

          {tab === "costs" ? (
            <Card className="w-full">
              <CardHeader className="gap-2">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <CardTitle>Prețuri active</CardTitle>
                    <CardDescription>
                      Valorile curente folosite la recalculul lunar. Modificările se aplică doar versiunilor noi.
                    </CardDescription>
                  </div>
                  <div className="rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground">
                    {savedAt ? `Activă din ${new Date(savedAt).toLocaleString()}` : "Fără versiune salvată"}
                  </div>
                </div>
              </CardHeader>

              <CardContent className="space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                  <PriceCard
                    icon={TagIcon}
                    label="Storage cost per GB"
                    helper={`Original: $${ORIGINAL_DEFAULTS.storage_cost_per_gb_day}`}
                    value={form.storage_cost_per_gb_day}
                    unit="$"
                    unitPrefix
                    editing={editing}
                    onChange={(value) => setForm({ ...form, storage_cost_per_gb_day: value })}
                  />
                  <PriceCard
                    icon={HardDriveIcon}
                    label="Delivery cost per GB"
                    helper={`Original: $${ORIGINAL_DEFAULTS.delivery_cost_per_gb}`}
                    value={form.delivery_cost_per_gb}
                    unit="$"
                    unitPrefix
                    editing={editing}
                    onChange={(value) => setForm({ ...form, delivery_cost_per_gb: value })}
                  />
                  <PriceCard
                    icon={KeyRoundIcon}
                    label="DRM cost per license"
                    helper={`Original: $${ORIGINAL_DEFAULTS.drm_cost_per_license}`}
                    value={form.drm_cost_per_license}
                    unit="$"
                    unitPrefix
                    editing={editing}
                    onChange={(value) => setForm({ ...form, drm_cost_per_license: value })}
                  />
                  <PriceCard
                    icon={DollarSignIcon}
                    label="Curs valutar USD/MDL"
                    helper={`Original: ${ORIGINAL_DEFAULTS.usd_to_mdl_rate} MDL`}
                    value={form.usd_to_mdl_rate}
                    unit="MDL"
                    displayPrefix="1 $ ="
                    editing={editing}
                    onChange={(value) => setForm({ ...form, usd_to_mdl_rate: value })}
                  />
                </div>

                {editing ? (
                  <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
                    <Button
                      variant="outline"
                      onClick={() => {
                        setEditing(false);
                        void load();
                      }}
                    >
                      {t("common.cancel")}
                    </Button>
                    <Button onClick={() => void save()} disabled={saving}>
                      <SaveIcon className="h-4 w-4" />
                      {saving ? `${t("common.loading")}…` : t("common.save")}
                    </Button>
                  </div>
                ) : null}
              </CardContent>
            </Card>
          ) : null}

          {tab === "credit" ? (
            <Card className="w-full">
              <CardHeader className="gap-2">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <CardTitle>Credit la înregistrare</CardTitle>
                    <CardDescription>
                      Controlezi suma primită automat de utilizatorii noi și campaniile active pe intervale.
                    </CardDescription>
                  </div>
                  <div className="rounded-md border bg-muted p-2">
                    <GiftIcon className="h-4 w-4 text-muted-foreground" />
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid gap-4 md:grid-cols-3">
                  <FormField
                    label="Activ"
                    type="toggle"
                    checked={registrationCredit.enabled}
                    helperText="Dacă este oprit, utilizatorii noi primesc 0 MDL."
                    disabled={!canEdit}
                    onChange={(event) =>
                      setRegistrationCredit((current) => ({ ...current, enabled: event.target.checked }))
                    }
                  />
                  <FormField
                    label="Sumă implicită"
                    type="number"
                    min="0"
                    step="0.01"
                    value={registrationCredit.default_amount}
                    disabled={!canEdit}
                    onChange={(event) =>
                      setRegistrationCredit((current) => ({ ...current, default_amount: event.target.value }))
                    }
                    helperText="Se aplică în afara campaniilor active."
                  />
                  <FormField
                    label="Valută"
                    value={registrationCredit.currency}
                    disabled
                    helperText="Wallet-ul platformei folosește MDL."
                  />
                </div>

                <div className="space-y-3 border-t pt-4">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <div className="text-sm font-medium">Campanii pe perioadă</div>
                      <p className="text-sm text-muted-foreground">
                        Prima campanie activă care include data înregistrării suprascrie suma implicită.
                      </p>
                    </div>
                    {canEdit ? (
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                          setRegistrationCredit((current) => ({
                            ...current,
                            campaigns: [
                              { label: "", amount: "100", starts_at: "", ends_at: "", enabled: true },
                              ...current.campaigns,
                            ],
                          }))
                        }
                      >
                        <PlusIcon className="h-4 w-4" />
                        Adaugă campanie
                      </Button>
                    ) : null}
                  </div>

                  {registrationCredit.campaigns.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                      Nu există campanii. Se folosește suma implicită.
                    </div>
                  ) : (
                    registrationCredit.campaigns.map((campaign, index) => (
                      <div key={index} className="grid gap-3 rounded-lg border p-4 md:grid-cols-[minmax(0,1fr)_120px_160px_160px_120px_40px]">
                        <FormField
                          label="Nume"
                          value={campaign.label}
                          disabled={!canEdit}
                          onChange={(event) => updateCampaign(index, { label: event.target.value })}
                        />
                        <FormField
                          label="Sumă"
                          type="number"
                          min="0"
                          step="0.01"
                          value={campaign.amount}
                          disabled={!canEdit}
                          onChange={(event) => updateCampaign(index, { amount: event.target.value })}
                        />
                        <FormField
                          label="De la"
                          type="date"
                          value={campaign.starts_at}
                          disabled={!canEdit}
                          onChange={(event) => updateCampaign(index, { starts_at: event.target.value })}
                        />
                        <FormField
                          label="Până la"
                          type="date"
                          value={campaign.ends_at}
                          disabled={!canEdit}
                          onChange={(event) => updateCampaign(index, { ends_at: event.target.value })}
                        />
                        <FormField
                          label="Activă"
                          type="toggle"
                          checked={campaign.enabled}
                          disabled={!canEdit}
                          onChange={(event) => updateCampaign(index, { enabled: event.target.checked })}
                        />
                        {canEdit ? (
                          <div className="flex items-end justify-end">
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              onClick={() =>
                                setRegistrationCredit((current) => ({
                                  ...current,
                                  campaigns: current.campaigns.filter((_, campaignIndex) => campaignIndex !== index),
                                }))
                              }
                            >
                              <TrashIcon className="h-4 w-4" />
                            </Button>
                          </div>
                        ) : null}
                      </div>
                    ))
                  )}
                </div>

                {registrationCreditMessage ? (
                  <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {registrationCreditMessage}
                  </div>
                ) : null}

                {canEdit ? (
                  <div className="flex justify-end border-t pt-4">
                    <Button onClick={() => void saveRegistrationCredit()} disabled={savingRegistrationCredit}>
                      <SaveIcon className="h-4 w-4" />
                      {savingRegistrationCredit ? "Se salvează..." : "Salvează creditul"}
                    </Button>
                  </div>
                ) : null}
              </CardContent>
            </Card>
          ) : null}

          {tab === "iap" ? (
            <Card className="w-full">
              <CardHeader className="gap-2">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <CardTitle>Pachete de credite App Store (iOS)</CardTitle>
                    <CardDescription>
                      În aplicația iOS utilizatorii cumpără credite prin Apple, care reține comisionul. Pe site (maib)
                      1 MDL = 1 credit, fără comision. Prețul filmelor rămâne același peste tot — comisionul se acoperă
                      aici, prin câte credite primește utilizatorul pentru prețul plătit la Apple.
                    </CardDescription>
                  </div>
                  <div className="rounded-md border bg-muted p-2">
                    <SmartphoneIcon className="h-4 w-4 text-muted-foreground" />
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid gap-4 md:grid-cols-3">
                  <FormField
                    label="Comision Apple (%)"
                    type="number"
                    min="0"
                    max="99"
                    step="1"
                    value={iapPacks.commission_rate}
                    disabled={!canEdit}
                    onChange={(event) => setIapPacks((current) => ({ ...current, commission_rate: event.target.value }))}
                    helperText="15% cu Small Business Program, 30% fără."
                  />
                  <FormField
                    label="Curs USD/MDL"
                    value={form.usd_to_mdl_rate || "—"}
                    disabled
                    helperText="Preluat din „Curs valutar USD/MDL” de mai sus."
                  />
                </div>

                <div className="space-y-3 border-t pt-4">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <div className="text-sm font-medium">Pachete</div>
                      <p className="text-sm text-muted-foreground">
                        Product ID-ul trebuie să fie identic cu produsul (Consumable) din App Store Connect. Prețul USD e
                        doar pentru calcul — prețul real îl stabilește Apple.
                      </p>
                    </div>
                    {canEdit ? (
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                          setIapPacks((current) => ({
                            ...current,
                            packs: [
                              ...current.packs,
                              { product_id: "md.filmoteca.ios.credits.", credits_mdl: "", apple_price_usd: "", sort_order: String(current.packs.length + 1) },
                            ],
                          }))
                        }
                      >
                        <PlusIcon className="h-4 w-4" />
                        Adaugă pachet
                      </Button>
                    ) : null}
                  </div>

                  {iapPacks.packs.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                      Nu există pachete. Aplicația iOS nu va putea vinde credite.
                    </div>
                  ) : (
                    iapPacks.packs.map((pack, index) => {
                      const net = iapNetMdl(pack.apple_price_usd, iapPacks.commission_rate, form.usd_to_mdl_rate);
                      const credits = Number(pack.credits_mdl || 0);
                      const losing = net !== null && credits > net;

                      return (
                        <div key={index} className="space-y-2 rounded-lg border p-4">
                          <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_130px_130px_90px_40px]">
                            <FormField
                              label="Product ID"
                              value={pack.product_id}
                              disabled={!canEdit}
                              onChange={(event) => updateIapPack(index, { product_id: event.target.value })}
                            />
                            <FormField
                              label="Preț Apple (USD)"
                              type="number"
                              min="0"
                              step="0.01"
                              value={pack.apple_price_usd}
                              disabled={!canEdit}
                              onChange={(event) => updateIapPack(index, { apple_price_usd: event.target.value })}
                            />
                            <FormField
                              label="Credite acordate"
                              type="number"
                              min="0"
                              step="1"
                              value={pack.credits_mdl}
                              disabled={!canEdit}
                              onChange={(event) => updateIapPack(index, { credits_mdl: event.target.value })}
                            />
                            <FormField
                              label="Ordine"
                              type="number"
                              min="0"
                              step="1"
                              value={pack.sort_order}
                              disabled={!canEdit}
                              onChange={(event) => updateIapPack(index, { sort_order: event.target.value })}
                            />
                            {canEdit ? (
                              <div className="flex items-end justify-end">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  onClick={() =>
                                    setIapPacks((current) => ({
                                      ...current,
                                      packs: current.packs.filter((_, packIndex) => packIndex !== index),
                                    }))
                                  }
                                >
                                  <TrashIcon className="h-4 w-4" />
                                </Button>
                              </div>
                            ) : null}
                          </div>
                          {net !== null ? (
                            <div className={`flex flex-wrap items-center gap-x-4 gap-y-1 text-xs ${losing ? "text-destructive" : "text-muted-foreground"}`}>
                              <span>Încasezi net după comision: ≈ {net.toFixed(2)} MDL</span>
                              <span>Credite recomandate: {Math.floor(net)}</span>
                              {losing ? <span className="font-medium">Acorzi mai multe credite decât încasezi — pierdere pe fiecare vânzare.</span> : null}
                              {canEdit && credits !== Math.floor(net) ? (
                                <button
                                  type="button"
                                  className="underline underline-offset-2"
                                  onClick={() => updateIapPack(index, { credits_mdl: String(Math.floor(net)) })}
                                >
                                  Aplică recomandarea
                                </button>
                              ) : null}
                            </div>
                          ) : null}
                        </div>
                      );
                    })
                  )}
                </div>

                {iapPacksMessage ? (
                  <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {iapPacksMessage}
                  </div>
                ) : null}

                {canEdit ? (
                  <div className="flex justify-end border-t pt-4">
                    <Button onClick={() => void saveIapPacks()} disabled={savingIapPacks}>
                      <SaveIcon className="h-4 w-4" />
                      {savingIapPacks ? "Se salvează..." : "Salvează pachetele"}
                    </Button>
                  </div>
                ) : null}
              </CardContent>
            </Card>
          ) : null}
        </div>
      )}
    </SectionLayout>
  );
}

function iapNetMdl(priceUsd: string, commissionPercent: string, usdToMdl: string): number | null {
  const price = Number(priceUsd);
  const rate = Number(usdToMdl);
  if (!price || !rate) return null;

  return price * (1 - Number(commissionPercent || 0) / 100) * rate;
}

function mapIapPacksSettings(value: unknown): IapPacksForm {
  const settings = typeof value === "object" && value !== null ? (value as Record<string, unknown>) : {};
  const packs = Array.isArray(settings.packs) ? settings.packs : [];
  const commission = Number(settings.commission_rate ?? 0.15);

  return {
    commission_rate: String(Math.round(commission * 10000) / 100),
    packs: packs.map((item, index) => {
      const pack = typeof item === "object" && item !== null ? (item as Record<string, unknown>) : {};

      return {
        product_id: String(pack.product_id ?? ""),
        credits_mdl: String(pack.credits_mdl ?? ""),
        apple_price_usd: pack.apple_price_usd == null ? "" : String(pack.apple_price_usd),
        sort_order: String(pack.sort_order ?? index + 1),
      };
    }),
  };
}

function mapRegistrationCreditSettings(value: unknown): RegistrationCreditForm {
  const settings = typeof value === "object" && value !== null ? (value as Record<string, unknown>) : {};
  const campaigns = Array.isArray(settings.campaigns) ? settings.campaigns : [];

  return {
    enabled: Boolean(settings.enabled ?? EMPTY_REGISTRATION_CREDIT_FORM.enabled),
    default_amount: String(settings.default_amount ?? EMPTY_REGISTRATION_CREDIT_FORM.default_amount),
    currency: String(settings.currency ?? EMPTY_REGISTRATION_CREDIT_FORM.currency),
    campaigns: campaigns.map((item) => {
      const campaign = typeof item === "object" && item !== null ? (item as Record<string, unknown>) : {};

      return {
        label: String(campaign.label ?? ""),
        amount: String(campaign.amount ?? "0"),
        starts_at: String(campaign.starts_at ?? ""),
        ends_at: String(campaign.ends_at ?? ""),
        enabled: Boolean(campaign.enabled ?? true),
      };
    }),
  };
}

interface PriceCardProps {
  icon: ElementType;
  label: string;
  helper: string;
  value: string;
  unit: string;
  unitPrefix?: boolean;
  displayPrefix?: string;
  editing: boolean;
  onChange: (value: string) => void;
}

function PriceCard({
  icon: Icon,
  label,
  helper,
  value,
  unit,
  unitPrefix,
  displayPrefix,
  editing,
  onChange,
}: PriceCardProps) {
  return (
    <div className="rounded-lg border bg-background p-4">
      <div className="flex items-start gap-3">
        <div className="rounded-md border bg-muted p-2">
          <Icon className="h-4 w-4" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="text-sm font-medium">{label}</div>
          <div className="mt-1 text-xs text-muted-foreground">{helper}</div>

          {editing ? (
            <div className="mt-4 flex flex-wrap items-center gap-2">
              {displayPrefix ? <span className="text-sm text-muted-foreground">{displayPrefix}</span> : null}
              {unitPrefix ? <span className="text-sm text-muted-foreground">{unit}</span> : null}
              <Input
                type="number"
                step="0.0001"
                min="0"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="w-36"
              />
              {!unitPrefix ? <span className="text-sm text-muted-foreground">{unit}</span> : null}
            </div>
          ) : (
            <div className="mt-4 text-2xl font-semibold">
              {displayPrefix ? <span className="mr-2 text-sm font-normal text-muted-foreground">{displayPrefix}</span> : null}
              {unitPrefix ? `${unit}${value || "0"}` : `${value || "0"} ${unit}`}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
