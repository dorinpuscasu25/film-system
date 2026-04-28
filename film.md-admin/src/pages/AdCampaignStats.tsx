import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { adminApi } from '../lib/api';

interface Props {
  campaignId: number;
}

export function AdCampaignStats({ campaignId }: Props) {
  const { t } = useTranslation();
  const [days, setDays] = useState(30);
  const [data, setData] = useState<Awaited<ReturnType<typeof adminApi.getAdCampaignStats>> | null>(null);
  const [events, setEvents] = useState<Awaited<ReturnType<typeof adminApi.getAdCampaignEvents>> | null>(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    try {
      const [stats, recent] = await Promise.all([
        adminApi.getAdCampaignStats(campaignId, days),
        adminApi.getAdCampaignEvents(campaignId, { per_page: 50 }),
      ]);
      setData(stats);
      setEvents(recent);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void load();
  }, [campaignId, days]);

  if (loading || !data) return <div className="p-6">{t('common.loading')}</div>;

  const maxEventCount = Math.max(1, ...data.events_chart.map((e) => e.count));
  const maxCountryCount = Math.max(1, ...data.country_chart.map((c) => c.count));

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{data.campaign.name}</h1>
          <div className="text-sm text-zinc-400">{data.campaign.company_name}</div>
        </div>
        <select
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          className="bg-zinc-800/60 text-zinc-100 border border-zinc-700 rounded-md px-3 py-1 text-sm"
        >
          <option value={7}>7 zile</option>
          <option value={30}>30 zile</option>
          <option value={90}>90 zile</option>
        </select>
      </div>

      <div className="grid grid-cols-3 lg:grid-cols-6 gap-3">
        <Stat label={t('ads.stats.impressions')} value={data.campaign.rollups.impressions} />
        <Stat label={t('ads.stats.completes')} value={data.campaign.rollups.completes} />
        <Stat label={t('ads.stats.clicks')} value={data.campaign.rollups.clicks} />
        <Stat label={t('ads.stats.skips')} value={data.campaign.rollups.skips} />
        <Stat label={t('ads.stats.ctr')} value={`${data.campaign.rollups.ctr.toFixed(2)}%`} />
        <Stat label={t('ads.stats.completion_rate')} value={`${data.campaign.rollups.completion_rate.toFixed(2)}%`} />
      </div>

      <section className="bg-zinc-900 border border-zinc-700 rounded-lg p-4">
        <h2 className="text-lg font-medium mb-3">{t('ads.stats.events_chart')}</h2>
        <div className="space-y-2">
          {data.events_chart.map((e) => (
            <div key={e.event} className="flex items-center gap-3 text-sm">
              <span className="w-32 text-zinc-400">{e.event}</span>
              <div className="flex-1 h-6 bg-zinc-800 rounded overflow-hidden">
                <div
                  className="h-full bg-violet-500"
                  style={{ width: `${(e.count / maxEventCount) * 100}%` }}
                />
              </div>
              <span className="w-16 text-right tabular-nums">{e.count}</span>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-zinc-900 border border-zinc-700 rounded-lg p-4">
        <h2 className="text-lg font-medium mb-3">{t('ads.stats.country_chart')}</h2>
        <div className="space-y-2">
          {data.country_chart.map((c) => (
            <div key={c.country} className="flex items-center gap-3 text-sm">
              <span className="w-12 font-mono">{c.country}</span>
              <div className="flex-1 h-4 bg-zinc-800 rounded overflow-hidden">
                <div
                  className="h-full bg-emerald-500"
                  style={{ width: `${(c.count / maxCountryCount) * 100}%` }}
                />
              </div>
              <span className="w-16 text-right tabular-nums">{c.count}</span>
              <span className="w-16 text-right text-zinc-400">{c.percent.toFixed(2)}%</span>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-zinc-900 border border-zinc-700 rounded-lg p-4">
        <h2 className="text-lg font-medium mb-3">{t('ads.stats.events_log')}</h2>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-zinc-800/60">
              <tr>
                <th className="text-left p-2">Eveniment</th>
                <th className="text-left p-2">Țară</th>
                <th className="text-left p-2">Sesiune</th>
                <th className="text-left p-2">IP</th>
                <th className="text-left p-2">Data</th>
              </tr>
            </thead>
            <tbody>
              {events?.items.map((e) => (
                <tr key={e.id} className="border-t border-zinc-700/60">
                  <td className="p-2 font-mono">{e.event_type}</td>
                  <td className="p-2 font-mono">{e.country_code ?? '—'}</td>
                  <td className="p-2 text-zinc-500 text-xs">{e.playback_session_id?.slice(0, 12)}</td>
                  <td className="p-2 text-zinc-500 text-xs">{e.ip_address}</td>
                  <td className="p-2 text-zinc-400">{e.occurred_at?.slice(0, 19).replace('T', ' ')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="bg-zinc-900 border border-zinc-700 rounded-lg p-3">
      <div className="text-xs text-zinc-400">{label}</div>
      <div className="text-2xl font-bold tabular-nums">{value}</div>
    </div>
  );
}
