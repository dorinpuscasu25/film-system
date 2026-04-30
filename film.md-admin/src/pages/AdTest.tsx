import { useState } from 'react';
import { CheckCircle2Icon, XCircleIcon, FlaskConicalIcon } from 'lucide-react';
import { adminApi } from '../lib/api';

type Placement = 'pre-roll' | 'mid-roll' | 'post-roll';

interface ResolveResult {
  inputs: { content_id: number; content_title: string; country_code: string | null; placement: string; group: string };
  chosen: {
    id: number;
    name: string;
    company_name: string | null;
    bid_amount: number;
    placement: string;
    skip_offset_seconds: number | null;
    click_through_url: string | null;
    creative: { media_url: string; duration_seconds: number; mime_type: string } | null;
  } | null;
  vast_xml: string | null;
  tracking_pixels: string[];
  eligible_count: number;
  candidates: Array<{
    id: number;
    name: string;
    bid: number;
    placement: string;
    eligible: boolean;
    chosen: boolean;
    reasons_excluded: string[];
    creatives_count: number;
  }>;
}

/**
 * VAST Debug page — pick a film + country + placement and see exactly which
 * campaign will be served, the resolved VAST XML, the tracking pixel URLs,
 * and the breakdown of why other candidates were excluded. Reuses the live
 * AdTargetingService so what you see here is what real users will get.
 */
export function AdTest() {
  const [contentId, setContentId] = useState('');
  const [countryCode, setCountryCode] = useState('MD');
  const [placement, setPlacement] = useState<Placement>('pre-roll');
  const [group, setGroup] = useState('movies');
  const [sessionId, setSessionId] = useState('');
  const [result, setResult] = useState<ResolveResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    if (!contentId) {
      setError('Introdu un content_id');
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await adminApi.testAdResolve({
        content_id: Number(contentId),
        placement,
        country_code: countryCode || undefined,
        group: group || undefined,
        session_id: sessionId || undefined,
      });
      setResult(res);
    } catch (e) {
      setError('Eroare la apelarea endpoint-ului. Verifică content_id.');
      setResult(null);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="p-6 max-w-6xl">
      <div className="flex items-center gap-3 mb-6">
        <div className="rounded-lg bg-violet-500/15 p-2.5 text-violet-400">
          <FlaskConicalIcon className="w-5 h-5" />
        </div>
        <div>
          <h1 className="text-2xl font-semibold">VAST Test</h1>
          <p className="text-sm text-zinc-400">
            Simulează cererea unui player și vezi care campanie ar fi servită + de ce celelalte au fost excluse.
          </p>
        </div>
      </div>

      <div className="rounded-xl border border-zinc-700 bg-zinc-900/60 p-4 mb-6">
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
          <Field label="Content ID">
            <input
              type="number"
              className="input"
              value={contentId}
              onChange={(e) => setContentId(e.target.value)}
              placeholder="42"
            />
          </Field>
          <Field label="Țară (ISO)">
            <input
              className="input"
              value={countryCode}
              onChange={(e) => setCountryCode(e.target.value.toUpperCase())}
              placeholder="MD"
              maxLength={5}
            />
          </Field>
          <Field label="Placement">
            <select className="input" value={placement} onChange={(e) => setPlacement(e.target.value as Placement)}>
              <option value="pre-roll">pre-roll</option>
              <option value="mid-roll">mid-roll</option>
              <option value="post-roll">post-roll</option>
            </select>
          </Field>
          <Field label="Grup">
            <select className="input" value={group} onChange={(e) => setGroup(e.target.value)}>
              <option value="movies">movies</option>
              <option value="trailers">trailers</option>
              <option value="premium">premium</option>
            </select>
          </Field>
          <Field label="Session ID (opțional)">
            <input className="input" value={sessionId} onChange={(e) => setSessionId(e.target.value)} placeholder="auto" />
          </Field>
        </div>
        <div className="mt-4 flex justify-end">
          <button
            onClick={run}
            disabled={loading}
            className="px-4 py-2 rounded-lg bg-violet-600 hover:bg-violet-500 text-white disabled:opacity-50"
          >
            {loading ? 'Se rulează…' : 'Rulează test'}
          </button>
        </div>
        {error && <div className="mt-3 text-sm text-red-400">{error}</div>}
      </div>

      {result && (
        <div className="space-y-6">
          <section className="rounded-xl border border-zinc-700 bg-zinc-900/60 p-4">
            <h2 className="text-lg font-medium mb-3">Campania aleasă</h2>
            {result.chosen ? (
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <KV label="Nume" value={result.chosen.name} />
                <KV label="Companie" value={result.chosen.company_name ?? '—'} />
                <KV label="Bid" value={`$${result.chosen.bid_amount.toFixed(2)}`} />
                <KV label="Skip after" value={`${result.chosen.skip_offset_seconds ?? '—'} sec`} />
                <KV label="Click-through" value={result.chosen.click_through_url ?? '—'} />
                <KV
                  label="Creative"
                  value={result.chosen.creative ? `${result.chosen.creative.duration_seconds}s · ${result.chosen.creative.mime_type}` : '—'}
                />
              </div>
            ) : (
              <div className="text-amber-400 text-sm">⚠ Nicio campanie eligibilă pentru aceste filtre.</div>
            )}
          </section>

          {result.vast_xml && (
            <section className="rounded-xl border border-zinc-700 bg-zinc-900/60 p-4">
              <h2 className="text-lg font-medium mb-3">VAST XML returnat</h2>
              <pre className="overflow-x-auto bg-black/50 p-3 rounded text-xs text-emerald-300 max-h-[400px] overflow-y-auto">
                {result.vast_xml}
              </pre>
              {result.tracking_pixels.length > 0 && (
                <div className="mt-3">
                  <div className="text-xs text-zinc-400 mb-1">Tracking pixel URLs ({result.tracking_pixels.length})</div>
                  <ul className="text-xs space-y-1 font-mono text-zinc-500 max-h-[200px] overflow-y-auto">
                    {result.tracking_pixels.map((url, idx) => (
                      <li key={idx} className="truncate">
                        {url}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>
          )}

          <section className="rounded-xl border border-zinc-700 bg-zinc-900/60 p-4">
            <h2 className="text-lg font-medium mb-3">
              Toate candidatele ({result.candidates.length}) — eligibile: {result.eligible_count}
            </h2>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-zinc-800/60">
                  <tr>
                    <th className="text-left p-2">Status</th>
                    <th className="text-left p-2">Campanie</th>
                    <th className="text-right p-2">Bid</th>
                    <th className="text-left p-2">Placement</th>
                    <th className="text-right p-2">Creatives</th>
                    <th className="text-left p-2">Motive excludere</th>
                  </tr>
                </thead>
                <tbody>
                  {result.candidates.map((c) => (
                    <tr key={c.id} className="border-t border-zinc-700/60">
                      <td className="p-2">
                        {c.chosen ? (
                          <span className="text-emerald-400">★ ales</span>
                        ) : c.eligible ? (
                          <span className="text-sky-400">eligibil</span>
                        ) : (
                          <span className="text-zinc-500">exclus</span>
                        )}
                      </td>
                      <td className="p-2">{c.name}</td>
                      <td className="p-2 text-right">${c.bid.toFixed(2)}</td>
                      <td className="p-2 font-mono">{c.placement}</td>
                      <td className="p-2 text-right">{c.creatives_count}</td>
                      <td className="p-2 text-xs text-zinc-400">
                        {c.reasons_excluded.length === 0 ? '—' : c.reasons_excluded.join('; ')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      )}

      <style>{`.input { width: 100%; background: rgba(39,39,42,0.8); border: 1px solid rgb(63,63,70); border-radius: 6px; padding: 6px 10px; color: rgb(244,244,245); font-size: 14px; }`}</style>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="text-zinc-400 text-xs">{label}</span>
      {children}
    </label>
  );
}

function KV({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-zinc-400">{label}</div>
      <div className="text-sm font-medium break-all">{value}</div>
    </div>
  );
}
