import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FileSpreadsheetIcon, FileTextIcon, LoaderIcon } from 'lucide-react';
import { adminApi } from '../lib/api';
import { downloadAdReportExcel, downloadAdReportPdf } from '../lib/adReport';

interface Props {
  campaignId: number;
}

export function AdCampaignStats({ campaignId }: Props) {
  const { t } = useTranslation();
  const [days, setDays] = useState(30);
  const [data, setData] = useState<Awaited<ReturnType<typeof adminApi.getAdCampaignStats>> | null>(null);
  const [events, setEvents] = useState<Awaited<ReturnType<typeof adminApi.getAdCampaignEvents>> | null>(null);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState<'excel' | 'pdf' | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);

  async function exportReport(format: 'excel' | 'pdf') {
    if (!data || exporting) return;
    setExporting(format);
    setExportError(null);
    try {
      await (format === 'excel' ? downloadAdReportExcel(data) : downloadAdReportPdf(data));
    } catch (error) {
      console.error(error);
      setExportError(t('ads.stats.export_failed'));
    } finally {
      setExporting(null);
    }
  }

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
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{data.campaign.name}</h1>
          <div className="text-sm text-muted-foreground">{data.campaign.company_name}</div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={days}
            onChange={(e) => setDays(Number(e.target.value))}
            className="rounded-lg border border-border bg-background px-3 py-1.5 text-sm outline-none focus:border-primary"
          >
            <option value={7}>7 zile</option>
            <option value={30}>30 zile</option>
            <option value={90}>90 zile</option>
          </select>
          <button
            type="button"
            onClick={() => void exportReport('excel')}
            disabled={exporting !== null}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-60"
          >
            {exporting === 'excel' ? <LoaderIcon className="h-4 w-4 animate-spin" /> : <FileSpreadsheetIcon className="h-4 w-4 text-emerald-600" />}
            {t('ads.stats.export_excel')}
          </button>
          <button
            type="button"
            onClick={() => void exportReport('pdf')}
            disabled={exporting !== null}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-60"
          >
            {exporting === 'pdf' ? <LoaderIcon className="h-4 w-4 animate-spin" /> : <FileTextIcon className="h-4 w-4 text-red-600" />}
            {t('ads.stats.export_pdf')}
          </button>
        </div>
      </div>
      {exportError && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{exportError}</div>}

      <div className="grid grid-cols-3 lg:grid-cols-6 gap-3">
        <Stat label={t('ads.stats.impressions')} value={data.campaign.rollups.impressions} />
        <Stat label={t('ads.stats.completes')} value={data.campaign.rollups.completes} />
        <Stat label={t('ads.stats.clicks')} value={data.campaign.rollups.clicks} />
        <Stat label={t('ads.stats.skips')} value={data.campaign.rollups.skips} />
        <Stat label={t('ads.stats.ctr')} value={`${data.campaign.rollups.ctr.toFixed(2)}%`} />
        <Stat label={t('ads.stats.completion_rate')} value={`${data.campaign.rollups.completion_rate.toFixed(2)}%`} />
      </div>

      <section className="rounded-xl border border-border bg-card p-4">
        <h2 className="text-lg font-medium mb-3">{t('ads.stats.events_chart')}</h2>
        <div className="space-y-2">
          {data.events_chart.map((e) => (
            <div key={e.event} className="flex items-center gap-3 text-sm">
              <span className="w-32 text-muted-foreground">{e.event}</span>
              <div className="h-6 flex-1 overflow-hidden rounded bg-muted">
                <div
                  className="h-full bg-primary"
                  style={{ width: `${(e.count / maxEventCount) * 100}%` }}
                />
              </div>
              <span className="w-16 text-right tabular-nums">{e.count}</span>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-xl border border-border bg-card p-4">
        <h2 className="text-lg font-medium mb-3">{t('ads.stats.country_chart')}</h2>
        <div className="space-y-2">
          {data.country_chart.map((c) => (
            <div key={c.country} className="flex items-center gap-3 text-sm">
              <span className="w-12 font-mono">{c.country}</span>
              <div className="h-4 flex-1 overflow-hidden rounded bg-muted">
                <div
                  className="h-full bg-emerald-500"
                  style={{ width: `${(c.count / maxCountryCount) * 100}%` }}
                />
              </div>
              <span className="w-16 text-right tabular-nums">{c.count}</span>
              <span className="w-16 text-right text-muted-foreground">{c.percent.toFixed(2)}%</span>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-xl border border-border bg-card p-4">
        <h2 className="text-lg font-medium mb-3">{t('ads.stats.events_log')}</h2>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-muted/60">
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
                <tr key={e.id} className="border-t border-border">
                  <td className="p-2 font-mono">{e.event_type}</td>
                  <td className="p-2 font-mono">{e.country_code ?? '—'}</td>
                  <td className="p-2 text-xs text-muted-foreground">{e.playback_session_id?.slice(0, 12)}</td>
                  <td className="p-2 text-xs text-muted-foreground">{e.ip_address}</td>
                  <td className="p-2 text-muted-foreground">{e.occurred_at?.slice(0, 19).replace('T', ' ')}</td>
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
    <div className="rounded-xl border border-border bg-card p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="text-2xl font-bold tabular-nums">{value}</div>
    </div>
  );
}
