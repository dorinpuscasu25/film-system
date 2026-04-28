import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { adminApi } from '../lib/api';

interface Creator {
  id: number;
  name: string;
  email: string | null;
  company_name: string | null;
  platform_fee_percent: number;
  is_active: boolean;
  user: { id: number; name: string; email: string } | null;
  content_count: number;
  contents: Array<{ id: number; title: string }>;
}

export function ContentCreators() {
  const { t } = useTranslation();
  const [items, setItems] = useState<Creator[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Creator | null>(null);
  const [statementsFor, setStatementsFor] = useState<Creator | null>(null);
  const [statements, setStatements] = useState<Awaited<ReturnType<typeof adminApi.getCreatorStatements>>['items']>([]);
  const [form, setForm] = useState({
    user_id: '' as string | number,
    name: '',
    email: '',
    company_name: '',
    platform_fee_percent: 70,
    is_active: true,
    content_ids: [] as number[],
  });

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getContentCreators();
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
      user_id: '',
      name: '',
      email: '',
      company_name: '',
      platform_fee_percent: 70,
      is_active: true,
      content_ids: [],
    });
    setShowForm(true);
  }
  function startEdit(c: Creator) {
    setEditing(c);
    setForm({
      user_id: c.user?.id ?? '',
      name: c.name,
      email: c.email ?? '',
      company_name: c.company_name ?? '',
      platform_fee_percent: c.platform_fee_percent,
      is_active: c.is_active,
      content_ids: c.contents.map((x) => x.id),
    });
    setShowForm(true);
  }
  async function submit() {
    const payload: Record<string, unknown> = {
      user_id: form.user_id ? Number(form.user_id) : null,
      name: form.name,
      email: form.email || null,
      company_name: form.company_name || null,
      platform_fee_percent: Number(form.platform_fee_percent),
      is_active: form.is_active,
      content_ids: form.content_ids,
    };
    if (editing) await adminApi.updateContentCreator(editing.id, payload);
    else await adminApi.createContentCreator(payload);
    setShowForm(false);
    await load();
  }
  async function remove(id: number) {
    if (!confirm('Confirmă ștergerea?')) return;
    await adminApi.deleteContentCreator(id);
    await load();
  }
  async function viewStatements(c: Creator) {
    setStatementsFor(c);
    const res = await adminApi.getCreatorStatements(c.id);
    setStatements(res.items);
  }

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-2xl font-semibold">{t('creators.title')}</h1>
        <button onClick={startCreate} className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">
          + {t('creators.create')}
        </button>
      </div>

      {loading ? (
        <div>{t('common.loading')}</div>
      ) : (
        <div className="overflow-x-auto rounded-md border border-zinc-700">
          <table className="w-full text-sm">
            <thead className="bg-zinc-800/60">
              <tr>
                <th className="text-left p-2">{t('common.name')}</th>
                <th className="text-left p-2">Email</th>
                <th className="text-left p-2">{t('creators.form.user')}</th>
                <th className="text-right p-2">Comision %</th>
                <th className="text-right p-2">Filme</th>
                <th className="text-left p-2">{t('common.status')}</th>
                <th className="text-right p-2">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id} className="border-t border-zinc-700/60">
                  <td className="p-2">{c.name}</td>
                  <td className="p-2 text-zinc-400">{c.email}</td>
                  <td className="p-2 text-zinc-400">{c.user?.email ?? '—'}</td>
                  <td className="p-2 text-right">{c.platform_fee_percent}%</td>
                  <td className="p-2 text-right">{c.content_count}</td>
                  <td className="p-2">{c.is_active ? t('common.active') : t('common.inactive')}</td>
                  <td className="p-2 text-right space-x-2">
                    <button onClick={() => viewStatements(c)} className="text-emerald-400 hover:underline">
                      {t('creators.statements')}
                    </button>
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
        <Modal onClose={() => setShowForm(false)} title={editing ? t('common.edit') : t('creators.create')}>
          <div className="grid grid-cols-2 gap-4">
            <Field label={t('creators.form.name')}>
              <input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field label={t('creators.form.email')}>
              <input className="input" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </Field>
            <Field label={t('creators.form.user')}>
              <input
                type="number"
                placeholder="user_id"
                className="input"
                value={form.user_id}
                onChange={(e) => setForm({ ...form, user_id: e.target.value })}
              />
            </Field>
            <Field label={t('creators.form.company_name')}>
              <input
                className="input"
                value={form.company_name}
                onChange={(e) => setForm({ ...form, company_name: e.target.value })}
              />
            </Field>
            <Field label={t('creators.form.platform_fee_percent')}>
              <input
                type="number"
                min={0}
                max={100}
                className="input"
                value={form.platform_fee_percent}
                onChange={(e) => setForm({ ...form, platform_fee_percent: Number(e.target.value) })}
              />
            </Field>
            <Field label={t('creators.form.content_ids')} span={2}>
              <input
                placeholder="1, 5, 12 (separate prin virgulă)"
                className="input"
                value={form.content_ids.join(', ')}
                onChange={(e) =>
                  setForm({
                    ...form,
                    content_ids: e.target.value
                      .split(',')
                      .map((x) => Number(x.trim()))
                      .filter((n) => Number.isFinite(n) && n > 0),
                  })
                }
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
            <button onClick={() => setShowForm(false)} className="px-4 py-2 rounded-md bg-zinc-700 hover:bg-zinc-600">
              {t('common.cancel')}
            </button>
            <button onClick={submit} className="px-4 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white">
              {t('common.save')}
            </button>
          </div>
        </Modal>
      )}

      {statementsFor && (
        <Modal onClose={() => setStatementsFor(null)} title={`${t('creators.statements')} — ${statementsFor.name}`}>
          {statements.length === 0 ? (
            <div className="text-zinc-400 text-sm">Nu există statemente încă.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-zinc-800/60">
                <tr>
                  <th className="text-left p-2">Lună</th>
                  <th className="text-right p-2">Venit USD</th>
                  <th className="text-right p-2">Costuri USD</th>
                  <th className="text-right p-2">Plată USD</th>
                  <th className="text-right p-2">Profit USD</th>
                  <th className="text-left p-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {statements.map((s) => (
                  <tr key={s.id} className="border-t border-zinc-700/60">
                    <td className="p-2">{s.month}</td>
                    <td className="p-2 text-right">{s.revenue_usd.toFixed(2)}</td>
                    <td className="p-2 text-right">{s.costs_usd.toFixed(2)}</td>
                    <td className="p-2 text-right">{s.payout_usd.toFixed(2)}</td>
                    <td className="p-2 text-right">{s.profit_usd.toFixed(2)}</td>
                    <td className="p-2">{s.is_locked ? '🔒 lock' : 'open'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Modal>
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

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div className="bg-zinc-900 rounded-lg p-6 max-w-3xl w-full border border-zinc-700">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold">{title}</h2>
          <button onClick={onClose} className="text-zinc-400 hover:text-zinc-200">
            ✕
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
