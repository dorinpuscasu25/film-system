import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { adminApi } from '../lib/api';

interface Party {
  id: number;
  content_id: number;
  content_title: string | null;
  title: string;
  room_code: string;
  scheduled_start_at: string | null;
  actual_start_at: string | null;
  ended_at: string | null;
  status: string;
  is_public: boolean;
  chat_enabled: boolean;
  max_participants: number | null;
}

export function WatchParties() {
  const { t } = useTranslation();
  const [items, setItems] = useState<Party[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    content_id: '' as string | number,
    title: '',
    scheduled_start_at: '',
    is_public: true,
    chat_enabled: true,
    max_participants: '' as string | number,
  });

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getWatchParties();
      setItems(res.items);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void load();
  }, []);

  async function submit() {
    if (!form.content_id || !form.title || !form.scheduled_start_at) return;
    await adminApi.createWatchParty({
      content_id: Number(form.content_id),
      title: form.title,
      scheduled_start_at: new Date(form.scheduled_start_at).toISOString(),
      is_public: form.is_public,
      chat_enabled: form.chat_enabled,
      max_participants: form.max_participants ? Number(form.max_participants) : undefined,
    });
    setShowForm(false);
    setForm({ content_id: '', title: '', scheduled_start_at: '', is_public: true, chat_enabled: true, max_participants: '' });
    await load();
  }

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-2xl font-semibold">{t('watch_parties.title')}</h1>
        <button onClick={() => setShowForm(true)} className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">
          + {t('watch_parties.create')}
        </button>
      </div>

      {loading ? (
        <div>{t('common.loading')}</div>
      ) : (
        <div className="overflow-x-auto rounded-md border border-zinc-700">
          <table className="w-full text-sm">
            <thead className="bg-zinc-800/60">
              <tr>
                <th className="text-left p-2">Titlu</th>
                <th className="text-left p-2">Film</th>
                <th className="text-left p-2">Cod cameră</th>
                <th className="text-left p-2">Start programat</th>
                <th className="text-left p-2">{t('common.status')}</th>
                <th className="text-right p-2">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((p) => (
                <tr key={p.id} className="border-t border-zinc-700/60">
                  <td className="p-2">{p.title}</td>
                  <td className="p-2 text-zinc-400">{p.content_title}</td>
                  <td className="p-2 font-mono">{p.room_code}</td>
                  <td className="p-2 text-zinc-400">{p.scheduled_start_at?.slice(0, 16).replace('T', ' ')}</td>
                  <td className="p-2">
                    {t(`watch_parties.statuses.${p.status}` as never, { defaultValue: p.status }) as string}
                  </td>
                  <td className="p-2 text-right space-x-2">
                    {p.status === 'scheduled' && (
                      <button
                        onClick={async () => {
                          await adminApi.startWatchParty(p.id);
                          await load();
                        }}
                        className="text-emerald-400 hover:underline"
                      >
                        {t('watch_parties.actions.start')}
                      </button>
                    )}
                    {p.status === 'live' && (
                      <button
                        onClick={async () => {
                          await adminApi.endWatchParty(p.id);
                          await load();
                        }}
                        className="text-amber-400 hover:underline"
                      >
                        {t('watch_parties.actions.end')}
                      </button>
                    )}
                    <button
                      onClick={async () => {
                        if (!confirm('Confirmă ștergerea?')) return;
                        await adminApi.deleteWatchParty(p.id);
                        await load();
                      }}
                      className="text-red-400 hover:underline"
                    >
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
          <div className="bg-zinc-900 rounded-lg p-6 max-w-xl w-full border border-zinc-700">
            <h2 className="text-xl font-semibold mb-4">{t('watch_parties.create')}</h2>
            <div className="grid grid-cols-2 gap-4">
              <label className="flex flex-col gap-1 text-sm col-span-2">
                <span className="text-zinc-400">{t('watch_parties.form.content')}</span>
                <input
                  type="number"
                  placeholder="content_id"
                  className="input"
                  value={form.content_id}
                  onChange={(e) => setForm({ ...form, content_id: e.target.value })}
                />
              </label>
              <label className="flex flex-col gap-1 text-sm col-span-2">
                <span className="text-zinc-400">{t('watch_parties.form.title')}</span>
                <input
                  className="input"
                  value={form.title}
                  onChange={(e) => setForm({ ...form, title: e.target.value })}
                />
              </label>
              <label className="flex flex-col gap-1 text-sm">
                <span className="text-zinc-400">{t('watch_parties.form.scheduled_start_at')}</span>
                <input
                  type="datetime-local"
                  className="input"
                  value={form.scheduled_start_at}
                  onChange={(e) => setForm({ ...form, scheduled_start_at: e.target.value })}
                />
              </label>
              <label className="flex flex-col gap-1 text-sm">
                <span className="text-zinc-400">{t('watch_parties.form.max_participants')}</span>
                <input
                  type="number"
                  className="input"
                  value={form.max_participants}
                  onChange={(e) => setForm({ ...form, max_participants: e.target.value })}
                />
              </label>
              <label className="flex items-center gap-2 col-span-2">
                <input
                  type="checkbox"
                  checked={form.is_public}
                  onChange={(e) => setForm({ ...form, is_public: e.target.checked })}
                />
                {t('watch_parties.form.is_public')}
              </label>
              <label className="flex items-center gap-2 col-span-2">
                <input
                  type="checkbox"
                  checked={form.chat_enabled}
                  onChange={(e) => setForm({ ...form, chat_enabled: e.target.checked })}
                />
                {t('watch_parties.form.chat_enabled')}
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
          </div>
        </div>
      )}

      <style>{`.input { width: 100%; background: rgba(39,39,42,0.8); border: 1px solid rgb(63,63,70); border-radius: 6px; padding: 6px 10px; color: rgb(244,244,245); }`}</style>
    </div>
  );
}
