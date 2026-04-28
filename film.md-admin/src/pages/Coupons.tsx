import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { adminApi } from '../lib/api';

interface Coupon {
  id: number;
  code: string;
  name: string;
  description: string | null;
  discount_type: 'percent' | 'fixed' | 'free_access';
  discount_value: number;
  currency: string;
  max_redemptions: number | null;
  redemptions_count: number;
  per_user_limit: number;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  is_currently_valid: boolean;
}

export function Coupons() {
  const { t } = useTranslation();
  const [items, setItems] = useState<Coupon[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Coupon | null>(null);
  const [form, setForm] = useState({
    code: '',
    name: '',
    description: '',
    discount_type: 'percent' as 'percent' | 'fixed' | 'free_access',
    discount_value: 10,
    currency: 'MDL',
    max_redemptions: '' as string | number,
    per_user_limit: 1,
    starts_at: '',
    ends_at: '',
    is_active: true,
  });

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getCoupons();
      setItems(res.items);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function startCreate() {
    setEditing(null);
    setForm({
      code: '',
      name: '',
      description: '',
      discount_type: 'percent',
      discount_value: 10,
      currency: 'MDL',
      max_redemptions: '',
      per_user_limit: 1,
      starts_at: '',
      ends_at: '',
      is_active: true,
    });
    setShowForm(true);
  }

  function startEdit(c: Coupon) {
    setEditing(c);
    setForm({
      code: c.code,
      name: c.name,
      description: c.description ?? '',
      discount_type: c.discount_type,
      discount_value: c.discount_value,
      currency: c.currency,
      max_redemptions: c.max_redemptions ?? '',
      per_user_limit: c.per_user_limit,
      starts_at: c.starts_at?.slice(0, 16) ?? '',
      ends_at: c.ends_at?.slice(0, 16) ?? '',
      is_active: c.is_active,
    });
    setShowForm(true);
  }

  async function submit() {
    const payload: Record<string, unknown> = {
      code: form.code.toUpperCase(),
      name: form.name,
      description: form.description || null,
      discount_type: form.discount_type,
      discount_value: Number(form.discount_value),
      currency: form.currency,
      max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
      per_user_limit: Number(form.per_user_limit),
      starts_at: form.starts_at || null,
      ends_at: form.ends_at || null,
      is_active: form.is_active,
    };
    if (editing) {
      await adminApi.updateCoupon(editing.id, payload);
    } else {
      await adminApi.createCoupon(payload);
    }
    setShowForm(false);
    await load();
  }

  async function remove(id: number) {
    if (!confirm('Confirmă ștergerea?')) return;
    await adminApi.deleteCoupon(id);
    await load();
  }

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-2xl font-semibold">{t('coupons.title')}</h1>
        <button
          onClick={startCreate}
          className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm"
        >
          + {t('coupons.create')}
        </button>
      </div>

      {loading ? (
        <div>{t('common.loading')}</div>
      ) : (
        <div className="overflow-x-auto rounded-md border border-zinc-700">
          <table className="w-full text-sm">
            <thead className="bg-zinc-800/60">
              <tr>
                <th className="text-left p-2">{t('coupons.form.code')}</th>
                <th className="text-left p-2">{t('coupons.form.name')}</th>
                <th className="text-left p-2">{t('coupons.form.discount_type')}</th>
                <th className="text-right p-2">{t('coupons.form.discount_value')}</th>
                <th className="text-right p-2">Folosiri</th>
                <th className="text-left p-2">{t('common.status')}</th>
                <th className="text-right p-2">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id} className="border-t border-zinc-700/60">
                  <td className="p-2 font-mono">{c.code}</td>
                  <td className="p-2">{c.name}</td>
                  <td className="p-2">{t(`coupons.form.discount_types.${c.discount_type}`)}</td>
                  <td className="p-2 text-right">
                    {c.discount_type === 'percent'
                      ? `${c.discount_value}%`
                      : c.discount_type === 'fixed'
                      ? `${c.discount_value} ${c.currency}`
                      : '—'}
                  </td>
                  <td className="p-2 text-right">
                    {c.redemptions_count}
                    {c.max_redemptions ? ` / ${c.max_redemptions}` : ''}
                  </td>
                  <td className="p-2">
                    <span
                      className={`px-2 py-0.5 rounded text-xs ${
                        c.is_currently_valid ? 'bg-emerald-500/20 text-emerald-300' : 'bg-zinc-500/20 text-zinc-300'
                      }`}
                    >
                      {c.is_currently_valid ? t('common.active') : t('common.inactive')}
                    </span>
                  </td>
                  <td className="p-2 text-right space-x-2">
                    <button onClick={() => startEdit(c)} className="text-blue-400 hover:underline">
                      {t('common.edit')}
                    </button>
                    <button onClick={() => remove(c.id)} className="text-red-400 hover:underline">
                      {t('common.delete')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showForm && (
        <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
          <div className="bg-zinc-900 rounded-lg p-6 max-w-2xl w-full border border-zinc-700">
            <h2 className="text-xl font-semibold mb-4">
              {editing ? t('common.edit') : t('coupons.create')}
            </h2>
            <div className="grid grid-cols-2 gap-4">
              <Field label={t('coupons.form.code')}>
                <input
                  className="input"
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                />
              </Field>
              <Field label={t('coupons.form.name')}>
                <input
                  className="input"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
              </Field>
              <Field label={t('coupons.form.discount_type')}>
                <select
                  className="input"
                  value={form.discount_type}
                  onChange={(e) =>
                    setForm({ ...form, discount_type: e.target.value as typeof form.discount_type })
                  }
                >
                  <option value="percent">{t('coupons.form.discount_types.percent')}</option>
                  <option value="fixed">{t('coupons.form.discount_types.fixed')}</option>
                  <option value="free_access">{t('coupons.form.discount_types.free_access')}</option>
                </select>
              </Field>
              <Field label={t('coupons.form.discount_value')}>
                <input
                  type="number"
                  className="input"
                  value={form.discount_value}
                  onChange={(e) => setForm({ ...form, discount_value: Number(e.target.value) })}
                />
              </Field>
              <Field label={t('coupons.form.max_redemptions')}>
                <input
                  type="number"
                  className="input"
                  value={form.max_redemptions}
                  onChange={(e) => setForm({ ...form, max_redemptions: e.target.value })}
                />
              </Field>
              <Field label={t('coupons.form.per_user_limit')}>
                <input
                  type="number"
                  className="input"
                  value={form.per_user_limit}
                  onChange={(e) => setForm({ ...form, per_user_limit: Number(e.target.value) })}
                />
              </Field>
              <Field label={t('coupons.form.starts_at')}>
                <input
                  type="datetime-local"
                  className="input"
                  value={form.starts_at}
                  onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                />
              </Field>
              <Field label={t('coupons.form.ends_at')}>
                <input
                  type="datetime-local"
                  className="input"
                  value={form.ends_at}
                  onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
                />
              </Field>
              <Field label={t('coupons.form.description')} span={2}>
                <textarea
                  className="input min-h-[80px]"
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                />
              </Field>
              <label className="flex items-center gap-2 col-span-2">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                />
                {t('common.active')}
              </label>
            </div>
            <div className="mt-6 flex justify-end gap-2">
              <button
                onClick={() => setShowForm(false)}
                className="px-4 py-2 rounded-md bg-zinc-700 hover:bg-zinc-600"
              >
                {t('common.cancel')}
              </button>
              <button
                onClick={submit}
                className="px-4 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white"
              >
                {t('common.save')}
              </button>
            </div>
          </div>
        </div>
      )}

      <style>{`.input { width: 100%; background: rgba(39,39,42,0.8); border: 1px solid rgb(63,63,70); border-radius: 6px; padding: 6px 10px; color: rgb(244,244,245); }`}</style>
    </div>
  );
}

function Field({ label, span = 1, children }: { label: string; span?: 1 | 2; children: React.ReactNode }) {
  return (
    <label className={`flex flex-col gap-1 text-sm ${span === 2 ? 'col-span-2' : ''}`}>
      <span className="text-zinc-400">{label}</span>
      {children}
    </label>
  );
}
