import { useEffect, useState, type ElementType } from "react";
import {
  AlertTriangleIcon,
  CheckCircle2Icon,
  ClipboardListIcon,
  MinusCircleIcon,
  RefreshCwIcon,
  XCircleIcon,
} from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";

type ProbeStatus = "pass" | "fail" | "skipped";
type SummaryStatus = "healthy" | "degraded" | "down";

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

const STATUS_LABEL: Record<SummaryStatus, string> = {
  healthy: "Healthy",
  degraded: "Degraded",
  down: "Down",
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
    } catch {
      setError("Nu s-a putut rula health check.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void run();
  }, []);

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Bunny Health Check</h1>
          <p className="page-description">
            Verifică cheile API, endpoint-urile Bunny și configurarea necesară pentru streaming și CDN.
          </p>
        </div>

        <Button variant="outline" onClick={() => void run()} disabled={loading}>
          <RefreshCwIcon className={`h-4 w-4 ${loading ? "animate-spin" : ""}`} />
          Rulează din nou
        </Button>
      </div>

      {error ? (
        <div className="inline-flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          <AlertTriangleIcon className="h-4 w-4" />
          {error}
        </div>
      ) : null}

      {loading && !data ? (
        <Card>
          <CardContent className="p-10 text-center text-sm text-muted-foreground">Se rulează health check…</CardContent>
        </Card>
      ) : null}

      {data ? (
        <>
          <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <CardTitle>Status general</CardTitle>
                <CardDescription>
                  {lastRunAt ? `Ultima rulare: ${lastRunAt.toLocaleString()}` : "Health check-ul nu a fost rulat încă."}
                </CardDescription>
              </div>
              <Badge variant={summaryVariant(data.summary.status)} className="w-fit">
                {STATUS_LABEL[data.summary.status]}
              </Badge>
            </CardHeader>
            <CardContent>
              <div className="grid gap-4 md:grid-cols-4">
                <MetricCard title="Total probe" value={data.summary.total} icon={ClipboardListIcon} />
                <MetricCard title="Pass" value={data.summary.passing} icon={CheckCircle2Icon} />
                <MetricCard title="Fail" value={data.summary.failing} icon={XCircleIcon} />
                <MetricCard title="Skipped" value={data.summary.skipped} icon={MinusCircleIcon} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Rezultate probe</CardTitle>
              <CardDescription>Fiecare probă arată ce configurare lipsește sau ce endpoint nu răspunde corect.</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Status</TableHead>
                    <TableHead>Probă</TableHead>
                    <TableHead>Detaliu</TableHead>
                    <TableHead className="text-right">Latență</TableHead>
                    <TableHead className="text-center">Cerut?</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.probes.map((probe) => (
                    <TableRow key={probe.id}>
                      <TableCell>{probeStatus(probe.status)}</TableCell>
                      <TableCell>
                        <div className="font-medium">{probe.label}</div>
                        <div className="mt-1 font-mono text-xs text-muted-foreground">{probe.id}</div>
                      </TableCell>
                      <TableCell className="max-w-xl text-sm text-muted-foreground">{probe.detail ?? "—"}</TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {probe.latency_ms !== null ? `${probe.latency_ms}ms` : "—"}
                      </TableCell>
                      <TableCell className="text-center">
                        <Badge variant={probe.required ? "archived" : "inactive"}>
                          {probe.required ? "required" : "optional"}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Setup .env</CardTitle>
              <CardDescription>Variabilele necesare pentru stream libraries, token key și integrarea CDN opțională.</CardDescription>
            </CardHeader>
            <CardContent>
              <pre className="admin-scrollbar overflow-x-auto rounded-lg border bg-muted p-4 text-sm text-foreground">
{`# Required
MOVIES_BUNNY_API_KEY=...
TRAILERS_BUNNY_API_KEY=...
BUNNY_STREAM_TOKEN_KEY=...
BUNNY_WEBHOOK_SECRET=...

# Optional (only if you want global CDN dashboard)
BUNNY_ACCOUNT_API_KEY=...
BUNNY_CDN_PULL_ZONE_ID=...`}
              </pre>
            </CardContent>
          </Card>
        </>
      ) : null}
    </div>
  );
}

function MetricCard({ title, value, icon: Icon }: { title: string; value: number; icon: ElementType }) {
  return (
    <div className="rounded-lg border bg-background p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="text-sm text-muted-foreground">{title}</div>
          <div className="mt-2 text-2xl font-semibold">{value.toLocaleString()}</div>
        </div>
        <div className="rounded-md border bg-muted p-2">
          <Icon className="h-4 w-4" />
        </div>
      </div>
    </div>
  );
}

function summaryVariant(status: SummaryStatus) {
  if (status === "healthy") {
    return "active" as const;
  }
  if (status === "degraded") {
    return "ready" as const;
  }
  return "archived" as const;
}

function probeStatus(status: ProbeStatus) {
  if (status === "pass") {
    return (
      <Badge variant="active" className="gap-1">
        <CheckCircle2Icon className="h-3 w-3" />
        Pass
      </Badge>
    );
  }

  if (status === "fail") {
    return (
      <Badge variant="archived" className="gap-1">
        <XCircleIcon className="h-3 w-3" />
        Fail
      </Badge>
    );
  }

  return (
    <Badge variant="inactive" className="gap-1">
      <MinusCircleIcon className="h-3 w-3" />
      Skipped
    </Badge>
  );
}
