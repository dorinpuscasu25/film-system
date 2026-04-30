import { useState, type ReactNode } from "react";
import { AlertTriangleIcon, CheckCircle2Icon, FlaskConicalIcon, PlayIcon, XCircleIcon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";

type Placement = "pre-roll" | "mid-roll" | "post-roll";

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

export function AdTest() {
  const [contentId, setContentId] = useState("");
  const [countryCode, setCountryCode] = useState("MD");
  const [placement, setPlacement] = useState<Placement>("pre-roll");
  const [group, setGroup] = useState("movies");
  const [sessionId, setSessionId] = useState("");
  const [result, setResult] = useState<ResolveResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    if (!contentId) {
      setError("Introdu un content_id");
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
    } catch {
      setError("Eroare la apelarea endpoint-ului. Verifică content_id.");
      setResult(null);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">VAST Test</h1>
          <p className="page-description">
            Simulează cererea unui player și vezi ce campanie este servită, ce XML se întoarce și de ce au fost excluse celelalte candidate.
          </p>
        </div>

        <div className="rounded-md border bg-muted p-2">
          <FlaskConicalIcon className="h-5 w-5" />
        </div>
      </div>

      <Card className="w-full">
        <CardHeader>
          <CardTitle>Parametri test</CardTitle>
          <CardDescription>Folosește aceleași filtre pe care le primește playerul în runtime.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <Field label="Content ID">
              <Input
                type="number"
                value={contentId}
                onChange={(event) => setContentId(event.target.value)}
                placeholder="42"
              />
            </Field>
            <Field label="Țară (ISO)">
              <Input
                value={countryCode}
                onChange={(event) => setCountryCode(event.target.value.toUpperCase())}
                placeholder="MD"
                maxLength={5}
              />
            </Field>
            <Field label="Placement">
              <select
                className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                value={placement}
                onChange={(event) => setPlacement(event.target.value as Placement)}
              >
                <option value="pre-roll">pre-roll</option>
                <option value="mid-roll">mid-roll</option>
                <option value="post-roll">post-roll</option>
              </select>
            </Field>
            <Field label="Grup">
              <select
                className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                value={group}
                onChange={(event) => setGroup(event.target.value)}
              >
                <option value="movies">movies</option>
                <option value="trailers">trailers</option>
                <option value="premium">premium</option>
              </select>
            </Field>
            <Field label="Session ID">
              <Input value={sessionId} onChange={(event) => setSessionId(event.target.value)} placeholder="opțional" />
            </Field>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            {error ? (
              <div className="inline-flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                <AlertTriangleIcon className="h-4 w-4" />
                {error}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">Rezultatul va reflecta regulile live din AdTargetingService.</p>
            )}
            <Button onClick={() => void run()} disabled={loading}>
              <PlayIcon className="h-4 w-4" />
              {loading ? "Se rulează…" : "Rulează test"}
            </Button>
          </div>
        </CardContent>
      </Card>

      {result ? (
        <div className="space-y-6">
          <div className="grid gap-4 md:grid-cols-3">
            <ResultMetric title="Candidate" value={result.candidates.length} />
            <ResultMetric title="Eligibile" value={result.eligible_count} />
            <ResultMetric title="Tracking pixels" value={result.tracking_pixels.length} />
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Campania aleasă</CardTitle>
              <CardDescription>
                {result.chosen ? "Această campanie ar fi servită playerului." : "Nu există campanie eligibilă pentru filtrele curente."}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {result.chosen ? (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                  <KV label="Nume" value={result.chosen.name} />
                  <KV label="Companie" value={result.chosen.company_name ?? "—"} />
                  <KV label="Bid" value={`$${result.chosen.bid_amount.toFixed(2)}`} />
                  <KV label="Placement" value={result.chosen.placement} />
                  <KV label="Skip after" value={`${result.chosen.skip_offset_seconds ?? "—"} sec`} />
                  <KV label="Click-through" value={result.chosen.click_through_url ?? "—"} />
                  <KV
                    label="Creative"
                    value={
                      result.chosen.creative
                        ? `${result.chosen.creative.duration_seconds}s · ${result.chosen.creative.mime_type}`
                        : "—"
                    }
                  />
                </div>
              ) : (
                <div className="flex items-center gap-2 rounded-md border bg-muted px-4 py-3 text-sm text-muted-foreground">
                  <AlertTriangleIcon className="h-4 w-4" />
                  Nicio campanie nu trece filtrele selectate.
                </div>
              )}
            </CardContent>
          </Card>

          {result.vast_xml ? (
            <Card>
              <CardHeader>
                <CardTitle>VAST XML returnat</CardTitle>
                <CardDescription>XML-ul final care ar fi livrat către player.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <pre className="admin-scrollbar max-h-[380px] overflow-auto rounded-lg border bg-muted p-4 text-xs text-foreground">
                  {result.vast_xml}
                </pre>
                {result.tracking_pixels.length > 0 ? (
                  <div className="space-y-2">
                    <div className="text-sm font-medium">Tracking pixel URLs ({result.tracking_pixels.length})</div>
                    <div className="admin-scrollbar max-h-[180px] space-y-2 overflow-auto rounded-lg border bg-background p-3">
                      {result.tracking_pixels.map((url, index) => (
                        <div key={`${url}-${index}`} className="truncate font-mono text-xs text-muted-foreground">
                          {url}
                        </div>
                      ))}
                    </div>
                  </div>
                ) : null}
              </CardContent>
            </Card>
          ) : null}

          <Card>
            <CardHeader>
              <CardTitle>Candidate evaluate</CardTitle>
              <CardDescription>Lista completă a campaniilor analizate și motivele de excludere.</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Status</TableHead>
                    <TableHead>Campanie</TableHead>
                    <TableHead className="text-right">Bid</TableHead>
                    <TableHead>Placement</TableHead>
                    <TableHead className="text-right">Creatives</TableHead>
                    <TableHead>Motive excludere</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {result.candidates.map((candidate) => (
                    <TableRow key={candidate.id}>
                      <TableCell>{candidateStatus(candidate)}</TableCell>
                      <TableCell className="font-medium">{candidate.name}</TableCell>
                      <TableCell className="text-right">${candidate.bid.toFixed(2)}</TableCell>
                      <TableCell className="font-mono text-xs">{candidate.placement}</TableCell>
                      <TableCell className="text-right">{candidate.creatives_count}</TableCell>
                      <TableCell className="max-w-md text-xs text-muted-foreground">
                        {candidate.reasons_excluded.length === 0 ? "—" : candidate.reasons_excluded.join("; ")}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      ) : null}
    </div>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-2 text-sm font-medium">
      <span className="text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}

function KV({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border bg-background p-4">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-2 break-all text-sm font-medium">{value}</div>
    </div>
  );
}

function ResultMetric({ title, value }: { title: string; value: number }) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div className="space-y-1">
          <CardDescription>{title}</CardDescription>
          <CardTitle>{value.toLocaleString()}</CardTitle>
        </div>
        <div className="rounded-md border bg-muted p-2">
          <FlaskConicalIcon className="h-4 w-4" />
        </div>
      </CardHeader>
    </Card>
  );
}

function candidateStatus(candidate: ResolveResult["candidates"][number]) {
  if (candidate.chosen) {
    return (
      <Badge variant="active" className="gap-1">
        <CheckCircle2Icon className="h-3 w-3" />
        ales
      </Badge>
    );
  }

  if (candidate.eligible) {
    return (
      <Badge variant="featured" className="gap-1">
        <CheckCircle2Icon className="h-3 w-3" />
        eligibil
      </Badge>
    );
  }

  return (
    <Badge variant="inactive" className="gap-1">
      <XCircleIcon className="h-3 w-3" />
      exclus
    </Badge>
  );
}
