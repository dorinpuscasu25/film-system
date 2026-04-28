import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { adminApi } from '../lib/api';

export function GeoStats() {
  const { t } = useTranslation();
  const [days, setDays] = useState(30);
  const [data, setData] = useState<Awaited<ReturnType<typeof adminApi.getGeoStats>> | null>(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getGeoStats(days);
      setData(res);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void load();
  }, [days]);

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-2xl font-semibold">{t('geo.title')}</h1>
        <select
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          className="bg-zinc-800/60 text-zinc-100 border border-zinc-700 rounded-md px-3 py-1 text-sm"
        >
          <option value={7}>7 zile</option>
          <option value={30}>30 zile</option>
          <option value={90}>90 zile</option>
          <option value={365}>1 an</option>
        </select>
      </div>

      {loading || !data ? (
        <div>{t('common.loading')}</div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-4 mb-6">
            <div className="bg-zinc-900 border border-zinc-700 rounded-lg p-4">
              <div className="text-zinc-400 text-sm">{t('geo.total_views')}</div>
              <div className="text-3xl font-bold">{data.totals.total_views.toLocaleString()}</div>
            </div>
            <div className="bg-zinc-900 border border-zinc-700 rounded-lg p-4">
              <div className="text-zinc-400 text-sm">{t('geo.unique_countries')}</div>
              <div className="text-3xl font-bold">{data.totals.unique_countries}</div>
            </div>
          </div>

          <div className="overflow-x-auto rounded-md border border-zinc-700">
            <table className="w-full text-sm">
              <thead className="bg-zinc-800/60">
                <tr>
                  <th className="text-left p-2">{t('geo.country')}</th>
                  <th className="text-right p-2">{t('geo.views')}</th>
                  <th className="text-right p-2">{t('geo.sessions')}</th>
                  <th className="text-right p-2">{t('geo.users')}</th>
                  <th className="text-right p-2">{t('geo.percent')}</th>
                  <th className="p-2 w-1/3">Distribuție</th>
                </tr>
              </thead>
              <tbody>
                {data.countries.map((c) => (
                  <tr key={c.country} className="border-t border-zinc-700/60">
                    <td className="p-2 font-mono">{c.country}</td>
                    <td className="p-2 text-right">{c.views.toLocaleString()}</td>
                    <td className="p-2 text-right">{c.sessions.toLocaleString()}</td>
                    <td className="p-2 text-right">{c.users.toLocaleString()}</td>
                    <td className="p-2 text-right">{c.percent.toFixed(2)}%</td>
                    <td className="p-2">
                      <div className="h-2 bg-zinc-800 rounded">
                        <div className="h-2 bg-emerald-500 rounded" style={{ width: `${Math.min(100, c.percent)}%` }} />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
