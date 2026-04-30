import { useEffect, useState, type ElementType } from "react";
import { useTranslation } from "react-i18next";
import {
  DollarSignIcon,
  HardDriveIcon,
  KeyRoundIcon,
  PencilIcon,
  RefreshCwIcon,
  SaveIcon,
  TagIcon,
} from "lucide-react";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { adminApi } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";

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

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getCostSettings();
      if (res.current) {
        setForm({
          storage_cost_per_gb_day: String(res.current.storage_cost_per_gb_day ?? ""),
          delivery_cost_per_gb: String(res.current.delivery_cost_per_gb ?? ""),
          drm_cost_per_license: String(res.current.drm_cost_per_license ?? ""),
          usd_to_mdl_rate: String(res.current.usd_to_mdl_rate ?? ""),
        });
        setSavedAt(res.current.effective_from ?? null);
      }
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

  if (loading) {
    return (
      <Card>
        <CardContent className="p-10 text-center text-sm text-muted-foreground">{t("common.loading")}…</CardContent>
      </Card>
    );
  }

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Setări prețuri</h1>
          <p className="page-description">
            Configurezi costurile de stocare, livrare, DRM și cursul valutar folosite în calculele platformei.
          </p>
        </div>

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
      </div>

      {error ? (
        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}

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

      {!canEdit ? (
        <p className="text-xs text-muted-foreground">
          Doar utilizatorii cu permisiunea <code>commerce.manage_costs</code> pot edita prețurile.
        </p>
      ) : null}
    </div>
  );
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
