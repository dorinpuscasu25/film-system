import { useEffect, useState } from 'react';
import { CheckCircle2Icon, RefreshCwIcon, XCircleIcon, MinusCircleIcon, ActivityIcon } from 'lucide-react';
import { adminApi } from '../lib/api';

type ProbeStatus = 'pass' | 'fail' | 'skipped';
type SummaryStatus = 'healthy' | 'degraded' | 'down';

interface Probe {
  id: string;
  label: string;
  required: boolean;
  status: ProbeStatus;
  detail: string | null;
  latency_ms: number | null;
}

interface HealthData {
  summary: { total: number; passing: number; failing: number; skipped: number; status: SummaryStatus };
  probes: Probe[];
}

const STATUS_BADGE: Record<SummaryStatus, { label: string; className: string }> = {
  healthy: { label: '✓ Healthy', className: 'bg-emerald-500/20 text-emerald-300' },
  degraded: { label: '⚠ Degraded', className: 'bg-amber-500/20 text-amber-300' },
  down: { label: '✕ Down', className: 'bg-red-500/20 text-red-300' },
};

export function BunnyHealth() {
  const [data, setData] = useState<HealthData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [lastRunAt, setLastRunAt] = useState<Date | null>(null);

  async function run() {
    setLoading(true);
    setError(null);
    try {
      const res = await adminApi.getBunnyHealth();
      setData(res);
      setLastRunAt(new Date());
    } catch (e) {
      setError('Nu s-a putut rula health check.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void run();
  }, []);

  return (
    <div className="p-6 max-w-5xl">
      <div className="flex items-start justify-between mb-6">
        <div className="flex items-center gap-3">
          <div className="rounded-lg bg-orange-500/15 p-2.5 text-orange-400">
            <ActivityIcon className="w-5 h-5" />
          </div>
          <div>
            <h1 className="text-2xl font-semibold">Bunny Health Check</h1>
            <p className="text-sm text-zinc-400">
              Verifică toate cheile API + endpoint-urile Bunny și raportează ce funcționează.
            </p>
          </div>
        </div>
        <button
          onClick={run}
          disabled={loading}
          className="flex items-center gap-2 px-3 py-2 rounded-lg border border-zinc-700 hover:bg-zinc-800 text-sm disabled:opacity-50"
        >
          <RefreshCwIcon className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Rulează din nou
        </button>
      </div>

      {error && (
        <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/30 p-3 text-sm text-red-300">{error}</div>
      )}

      {data && (
        <>
          <div className="mb-6 rounded-xl border border-zinc-700 bg-zinc-900/60 p-5">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-xs uppercase tracking-wide text-zinc-400 mb-1">Status general</div>
                <span className={`inline-block px-3 py-1.5 rounded-md text-sm font-medium ${STATUS_BADGE[data.summary.status].className}`}>
                  {STATUS_BADGE[data.summary.status].label}
                </span>
              </div>
              <div className="grid grid-cols-3 gap-6 text-center">
                <div>
                  <div className="text-2xl font-bold text-emerald-400">{data.summary.passing}</div>
                  <div className="text-xs text-zinc-400">Pass</div>
                </div>
                <div>
                  <div className="text-2xl font-bold text-red-400">{data.summary.failing}</div>
                  <div className="text-xs text-zinc-400">Fail</div>
                </div>
                <div>
                  <div className="text-2xl font-bold text-zinc-400">{data.summary.skipped}</div>
                  <div className="text-xs text-zinc-400">Skipped</div>
                </div>
              </div>
            </div>
            {lastRunAt && (
              <div className="mt-3 text-xs text-zinc-500">Ultima rulare: {lastRunAt.toLocaleString()}</div>
            )}
          </div>

          <div className="rounded-xl border border-zinc-700 bg-zinc-900/60 overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-zinc-800/60">
                <tr>
                  <th className="text-left p-3 w-10">Status</th>
                  <th className="text-left p-3">Probă</th>
                  <th className="text-left p-3">Detaliu</th>
                  <th className="text-right p-3 w-24">Latență</th>
                  <th className="text-center p-3 w-24">Cerut?</th>
                </tr>
              </thead>
              <tbody>
                {data.probes.map((p) => (
                  <tr key={p.id} className="border-t border-zinc-700/60">
                    <td className="p-3">
                      {p.status === 'pass' && <CheckCircle2Icon className="w-5 h-5 text-emerald-400" />}
                      {p.status === 'fail' && <XCircleIcon className="w-5 h-5 text-red-400" />}
                      {p.status === 'skipped' && <MinusCircleIcon className="w-5 h-5 text-zinc-500" />}
                    </td>
                    <td className="p-3">
                      <div className="font-medium">{p.label}</div>
                      <div className="text-xs text-zinc-500 font-mono">{p.id}</div>
                    </td>
                    <td className="p-3 text-zinc-400 text-xs">{p.detail ?? '—'}</td>
                    <td className="p-3 text-right tabular-nums text-zinc-400">
                      {p.latency_ms !== null ? `${p.latency_ms}ms` : '—'}
                    </td>
                    <td className="p-3 text-center">
                      {p.required ? (
                        <span className="text-xs px-2 py-0.5 rounded bg-red-500/15 text-red-300">required</span>
                      ) : (
                        <span className="text-xs text-zinc-500">optional</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-6 rounded-xl border border-zinc-700/60 bg-zinc-900/40 p-4 text-xs text-zinc-400">
            <div className="font-medium text-zinc-300 mb-2">📋 Setup .env</div>
            <pre className="bg-black/40 p-3 rounded overflow-x-auto">{`# Required
MOVIES_BUNNY_API_KEY=...
TRAILERS_BUNNY_API_KEY=...
BUNNY_STREAM_TOKEN_KEY=...
BUNNY_WEBHOOK_SECRET=...

# Optional (only if you want global CDN dashboard)
BUNNY_ACCOUNT_API_KEY=...
BUNNY_CDN_PULL_ZONE_ID=...`}</pre>
          </div>
        </>
      )}
    </div>
  );
}
