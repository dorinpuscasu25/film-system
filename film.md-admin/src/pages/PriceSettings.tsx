import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DollarSignIcon, HardDriveIcon, KeyRoundIcon, PencilIcon, TagIcon } from 'lucide-react';
import { adminApi } from '../lib/api';
import { useAdmin } from '../hooks/useAdmin';

interface PriceForm {
  storage_cost_per_gb_day: string;
  delivery_cost_per_gb: string;
  drm_cost_per_license: string;
  usd_to_mdl_rate: string;
}

const EMPTY_FORM: PriceForm = {
  storage_cost_per_gb_day: '',
  delivery_cost_per_gb: '',
  drm_cost_per_license: '',
  usd_to_mdl_rate: '',
};

const ORIGINAL_DEFAULTS = {
  storage_cost_per_gb_day: 0.0035,
  delivery_cost_per_gb: 0.005,
  drm_cost_per_license: 0.005,
  usd_to_mdl_rate: 17.5,
};

/**
 * Dedicated "Prețuri" page — single source of truth for the 4 platform-wide
 * pricing inputs used by AnalyticsBufferService::recalculateMonthlyCosts.
 *
 * Saving creates a new cost_settings_versions row with effective_from = now(),
 * so historical months keep their old rates and only the current month is
 * recalculated on the next hourly run.
 */
export function PriceSettings() {
  const { t } = useTranslation();
  const { can } = useAdmin();
  const canEdit = can('commerce.manage_costs');

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
          storage_cost_per_gb_day: String(res.current.storage_cost_per_gb_day ?? ''),
          delivery_cost_per_gb: String(res.current.delivery_cost_per_gb ?? ''),
          drm_cost_per_license: String(res.current.drm_cost_per_license ?? ''),
          usd_to_mdl_rate: String(res.current.usd_to_mdl_rate ?? ''),
        });
        setSavedAt(res.current.effective_from ?? null);
      }
    } catch (e) {
      setError('Nu s-au putut încărca prețurile.');
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
    } catch (e) {
      setError('Salvarea a eșuat. Verifică valorile.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <div className="p-6 text-zinc-400">{t('common.loading')}…</div>;
  }

  return (
    <div className="p-6 max-w-3xl">
      <div className="mb-6">
        <div className="flex items-center gap-2 text-sm text-zinc-400 mb-1">
          <span className="inline-flex items-center gap-1.5 text-rose-400">
            <span className="h-2 w-2 rounded-full bg-rose-500" /> Live
          </span>
          <span className="text-zinc-500">/</span>
          <button onClick={load} className="text-zinc-400 hover:text-zinc-200">
            ↺ Reîncarcă
          </button>
        </div>
      </div>

      <div className="rounded-2xl border border-zinc-700 bg-zinc-900/60 p-6">
        <div className="flex items-start justify-between mb-4">
          <div>
            <h1 className="text-2xl font-semibold">Prețuri</h1>
            <p className="text-sm text-zinc-400 mt-1">
              Recalculul se face o dată în oră pentru luna curentă. Nu afectează lunile precedente.
            </p>
            <p className="text-xs text-zinc-500 mt-2">
              <span className="font-medium">Original:</span> Storage − ${ORIGINAL_DEFAULTS.storage_cost_per_gb_day},
              Delivery − ${ORIGINAL_DEFAULTS.delivery_cost_per_gb}, DRM − ${ORIGINAL_DEFAULTS.drm_cost_per_license}
            </p>
            {savedAt && (
              <p className="text-xs text-emerald-400 mt-1">
                Versiune activă din: {new Date(savedAt).toLocaleString()}
              </p>
            )}
          </div>
          {!editing && canEdit && (
            <button
              onClick={() => setEditing(true)}
              className="rounded-lg border border-violet-500 px-3 py-2 text-violet-400 hover:bg-violet-500/10"
              aria-label="Edit prices"
            >
              <PencilIcon className="h-4 w-4" />
            </button>
          )}
        </div>

        {error && (
          <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/30 p-3 text-sm text-red-300">
            {error}
          </div>
        )}

        <div className="space-y-3">
          <PriceCard
            icon={<TagIcon className="h-5 w-5" />}
            iconBg="bg-violet-500/15 text-violet-400"
            label="Storage cost per Gb"
            value={form.storage_cost_per_gb_day}
            unit="$"
            unitPrefix
            editing={editing}
            onChange={(v) => setForm({ ...form, storage_cost_per_gb_day: v })}
          />
          <PriceCard
            icon={<HardDriveIcon className="h-5 w-5" />}
            iconBg="bg-sky-500/15 text-sky-400"
            label="Delivery cost per Gb"
            value={form.delivery_cost_per_gb}
            unit="$"
            unitPrefix
            editing={editing}
            onChange={(v) => setForm({ ...form, delivery_cost_per_gb: v })}
          />
          <PriceCard
            icon={<KeyRoundIcon className="h-5 w-5" />}
            iconBg="bg-amber-500/15 text-amber-400"
            label="DRM cost per license"
            value={form.drm_cost_per_license}
            unit="$"
            unitPrefix
            editing={editing}
            onChange={(v) => setForm({ ...form, drm_cost_per_license: v })}
          />
          <PriceCard
            icon={<DollarSignIcon className="h-5 w-5" />}
            iconBg="bg-emerald-500/15 text-emerald-400"
            label="Curs Valutar USD/MDL"
            value={form.usd_to_mdl_rate}
            unit="MDL"
            displayPrefix="1 $ ="
            editing={editing}
            onChange={(v) => setForm({ ...form, usd_to_mdl_rate: v })}
          />
        </div>

        {editing && (
          <div className="mt-6 flex justify-end gap-2">
            <button
              onClick={() => {
                setEditing(false);
                void load();
              }}
              className="px-4 py-2 rounded-lg bg-zinc-700 hover:bg-zinc-600 text-sm"
            >
              {t('common.cancel')}
            </button>
            <button
              onClick={save}
              disabled={saving}
              className="px-4 py-2 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-sm disabled:opacity-50"
            >
              {saving ? `${t('common.loading')}…` : t('common.save')}
            </button>
          </div>
        )}
      </div>

      {!canEdit && (
        <p className="mt-3 text-xs text-zinc-500">
          Doar utilizatorii cu permisiunea <code>commerce.manage_costs</code> pot edita prețurile.
        </p>
      )}
    </div>
  );
}

interface PriceCardProps {
  icon: React.ReactNode;
  iconBg: string;
  label: string;
  value: string;
  unit: string;
  unitPrefix?: boolean;
  displayPrefix?: string;
  editing: boolean;
  onChange: (value: string) => void;
}

function PriceCard({ icon, iconBg, label, value, unit, unitPrefix, displayPrefix, editing, onChange }: PriceCardProps) {
  return (
    <div className="flex items-center gap-4 rounded-xl border border-zinc-700/60 bg-zinc-900/40 p-3">
      <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${iconBg}`}>{icon}</div>
      <div className="flex-1">
        <div className="text-xs text-zinc-400">{label}</div>
        {editing ? (
          <div className="mt-1 flex items-center gap-2">
            {displayPrefix && <span className="text-zinc-300 text-sm">{displayPrefix}</span>}
            {unitPrefix && <span className="text-zinc-300">{unit}</span>}
            <input
              type="number"
              step="0.0001"
              min="0"
              value={value}
              onChange={(e) => onChange(e.target.value)}
              className="w-32 bg-zinc-800/80 border border-zinc-600 rounded px-2 py-1 text-zinc-100"
            />
            {!unitPrefix && <span className="text-zinc-300">{unit}</span>}
          </div>
        ) : (
          <div className="mt-0.5 text-lg font-semibold text-zinc-100">
            {displayPrefix && <span className="mr-1 text-zinc-400 font-normal text-sm">{displayPrefix}</span>}
            {unitPrefix ? `${unit}${value}` : `${value} ${unit}`}
          </div>
        )}
      </div>
    </div>
  );
}
