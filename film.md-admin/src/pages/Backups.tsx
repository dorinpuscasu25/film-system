import { useCallback, useEffect, useMemo, useState, type ElementType, type ReactNode } from "react";
import {
  AlertTriangleIcon,
  CalendarClockIcon,
  CheckCircle2Icon,
  CloudIcon,
  DatabaseBackupIcon,
  DownloadIcon,
  FlaskConicalIcon,
  HardDriveIcon,
  HistoryIcon,
  LockIcon,
  LockOpenIcon,
  MailIcon,
  MinusCircleIcon,
  PlayIcon,
  RefreshCwIcon,
  SaveIcon,
  ShieldCheckIcon,
  TrashIcon,
  XCircleIcon,
} from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { Modal } from "../components/shared/Modal";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Switch } from "../components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../components/ui/tabs";
import { Textarea } from "../components/ui/textarea";
import {
  adminApi,
  type BackupArtifact,
  type BackupComponent,
  type BackupRun,
  type BackupRunStatus,
  type BackupSettings,
  type BackupSettingsPayload,
  type BackupsResponse,
} from "../lib/api";

const COMPONENTS: Array<{ key: BackupComponent; label: string; description: string }> = [
  {
    key: "database",
    label: "Baza de date principală",
    description: "Utilizatori, filme, achiziții, portofele, raportare. Dump PostgreSQL verificat cu pg_restore.",
  },
  {
    key: "analytics",
    label: "Baza de date analytics",
    description: "Agregatele de vizionări și reclame. Sărită automat dacă folosește aceeași bază ca aplicația.",
  },
  {
    key: "redis",
    label: "Redis",
    description: "Cozi, sesiuni și buffer-ul de analytics încă nescris în bază. Util, dar nu critic.",
  },
  {
    key: "media",
    label: "Oglindă media (bucket S3/R2)",
    description: "Sincronizare incrementală a imaginilor din bucket în destinația off-site. Necesită rclone activ.",
  },
];

const STATUS_LABEL: Record<BackupRunStatus, string> = {
  queued: "În coadă",
  running: "Rulează",
  completed: "Reușit",
  partial: "Parțial",
  failed: "Eșuat",
};

const TYPE_LABEL: Record<BackupRun["type"], string> = {
  backup: "Backup",
  restore_test: "Test restaurare",
  restore: "Restaurare",
};

const TRIGGER_LABEL: Record<BackupRun["trigger"], string> = {
  manual: "manual",
  scheduled: "programat",
  cli: "CLI",
  pre_restore: "înainte de restaurare",
};

const REMOTE_LABEL: Record<BackupRun["remote_status"], string> = {
  skipped: "—",
  pending: "în așteptare",
  uploading: "se încarcă",
  uploaded: "încărcat",
  failed: "eșuat",
  deleted: "șters",
};

type SchedulePreset = "daily" | "12h" | "6h" | "weekly" | "custom";

export function Backups() {
  const [data, setData] = useState<BackupsResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [typeFilter, setTypeFilter] = useState<BackupRun["type"] | "">("");
  const [statusFilter, setStatusFilter] = useState<BackupRunStatus | "">("");
  const [selectedRunId, setSelectedRunId] = useState<number | null>(null);
  const [startOpen, setStartOpen] = useState(false);

  const load = useCallback(
    async (quiet = false) => {
      if (!quiet) {
        setLoading(true);
      }
      try {
        const response = await adminApi.getBackups({ page, type: typeFilter, status: statusFilter });
        setData(response);
        setError(null);
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : "Nu s-au putut încărca backup-urile.");
      } finally {
        setLoading(false);
      }
    },
    [page, typeFilter, statusFilter],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const hasActiveRun =
    data?.overview.active_run_id != null || (data?.runs ?? []).some((run) => run.status === "queued" || run.status === "running");

  useEffect(() => {
    if (!hasActiveRun) {
      return;
    }
    const timer = window.setInterval(() => void load(true), 4000);
    return () => window.clearInterval(timer);
  }, [hasActiveRun, load]);

  function flash(message: string) {
    setNotice(message);
    window.setTimeout(() => setNotice((current) => (current === message ? null : current)), 5000);
  }

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Backup-uri</h1>
          <p className="page-description">
            Backup-uri automate și manuale pentru baza de date, cu verificare, copie off-site, retenție și test de restaurare.
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={() => void load()} disabled={loading}>
            <RefreshCwIcon className={`h-4 w-4 ${loading ? "animate-spin" : ""}`} />
            Reîncarcă
          </Button>
          <Button onClick={() => setStartOpen(true)} disabled={!data || hasActiveRun}>
            <PlayIcon className="h-4 w-4" />
            Backup acum
          </Button>
        </div>
      </div>

      {error ? <Alert tone="error">{error}</Alert> : null}
      {notice ? <Alert tone="success">{notice}</Alert> : null}

      {data ? (
        <>
          <HealthAlerts data={data} />

          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <MetricCard
              title="Ultimul backup reușit"
              icon={ShieldCheckIcon}
              value={data.overview.last_success_at ? formatRelative(data.overview.last_success_at) : "Niciodată"}
              detail={data.overview.last_success_at ? formatDate(data.overview.last_success_at) : "Rulează primul backup"}
              tone={data.overview.is_stale ? "error" : "default"}
            />
            <MetricCard
              title="Următorul backup"
              icon={CalendarClockIcon}
              value={data.overview.next_run_at ? formatRelative(data.overview.next_run_at) : "Dezactivat"}
              detail={data.overview.next_run_at ? formatDate(data.overview.next_run_at) : "Programarea este oprită"}
            />
            <MetricCard
              title="Backup-uri stocate local"
              icon={DatabaseBackupIcon}
              value={String(data.overview.stored_backups)}
              detail={formatBytes(data.overview.stored_bytes)}
            />
            <MetricCard
              title="Spațiu liber pe disc"
              icon={HardDriveIcon}
              value={data.overview.disk.free_bytes != null ? formatBytes(data.overview.disk.free_bytes) : "—"}
              detail={
                data.overview.disk.total_bytes != null
                  ? `din ${formatBytes(data.overview.disk.total_bytes)} · ${data.overview.disk.path}`
                  : data.overview.disk.path
              }
            />
          </div>

          <Tabs defaultValue="history" className="space-y-4">
            <TabsList>
              <TabsTrigger value="history">Istoric</TabsTrigger>
              <TabsTrigger value="settings">Setări</TabsTrigger>
              <TabsTrigger value="pre-migration">Pre-migrare ({data.pre_migration.length})</TabsTrigger>
              <TabsTrigger value="restore">Restaurare</TabsTrigger>
            </TabsList>

            <TabsContent value="history">
              <HistoryTab
                data={data}
                typeFilter={typeFilter}
                statusFilter={statusFilter}
                onTypeFilter={(value) => {
                  setTypeFilter(value);
                  setPage(1);
                }}
                onStatusFilter={(value) => {
                  setStatusFilter(value);
                  setPage(1);
                }}
                onPage={setPage}
                onOpen={setSelectedRunId}
              />
            </TabsContent>

            <TabsContent value="settings">
              <SettingsTab
                data={data}
                onSaved={(response) => {
                  setData(response);
                  flash("Setările au fost salvate.");
                }}
              />
            </TabsContent>

            <TabsContent value="pre-migration">
              <PreMigrationTab dumps={data.pre_migration} onError={setError} />
            </TabsContent>

            <TabsContent value="restore">
              <RestoreTab data={data} />
            </TabsContent>
          </Tabs>
        </>
      ) : loading ? (
        <Card>
          <CardContent className="p-10 text-center text-sm text-muted-foreground">Se încarcă…</CardContent>
        </Card>
      ) : null}

      {data && startOpen ? (
        <StartBackupModal
          settings={data.settings}
          onClose={() => setStartOpen(false)}
          onStarted={(run) => {
            setStartOpen(false);
            flash(`Backup-ul ${run.name} a pornit.`);
            setSelectedRunId(run.id);
            void load(true);
          }}
        />
      ) : null}

      {selectedRunId !== null ? (
        <RunDetailsModal
          runId={selectedRunId}
          onClose={() => setSelectedRunId(null)}
          onChanged={() => void load(true)}
          onOpenRun={setSelectedRunId}
          onNotice={flash}
        />
      ) : null}
    </div>
  );
}

function HealthAlerts({ data }: { data: BackupsResponse }) {
  const problems: string[] = [];
  const { tools, overview, settings } = data;

  if (!tools.pg_dump.available) {
    problems.push("pg_dump nu este instalat în acest container. Reconstruiește imaginea Docker a API-ului.");
  } else if (!tools.postgres_server.compatible) {
    problems.push(
      `pg_dump (${tools.pg_dump.version}) este mai vechi decât serverul PostgreSQL ${tools.postgres_server.version}. Backup-ul va eșua până la actualizarea clientului.`,
    );
  }
  if (!overview.disk.writable) {
    problems.push(`Directorul de backup ${overview.disk.path} nu poate fi scris.`);
  }
  if (settings.remote.enabled && !tools.rclone.available) {
    problems.push("Copia off-site este activă, dar rclone nu este instalat.");
  }
  if (settings.components.redis && !tools.redis_cli.available) {
    problems.push("Backup-ul Redis este activ, dar redis-cli nu este instalat.");
  }
  if (settings.encryption.enabled && !data.encryption_available) {
    problems.push("Criptarea este activată, dar BACKUP_ENCRYPTION_PASSPHRASE lipsește din .env. Backup-urile se fac necriptate.");
  }
  if (!settings.remote.enabled) {
    problems.push("Nu există copie off-site: dacă serverul se pierde, se pierd și backup-urile. Configurează rclone în Setări.");
  }

  return (
    <div className="space-y-3">
      {overview.is_stale ? (
        <Alert tone="error">
          {overview.last_success_at
            ? `Niciun backup reușit din ${formatDate(overview.last_success_at)}. Verifică istoricul și containerul backup-worker.`
            : "Nu există încă niciun backup reușit."}
        </Alert>
      ) : null}
      {problems.map((problem) => (
        <Alert key={problem} tone="warning">
          {problem}
        </Alert>
      ))}
    </div>
  );
}

function HistoryTab({
  data,
  typeFilter,
  statusFilter,
  onTypeFilter,
  onStatusFilter,
  onPage,
  onOpen,
}: {
  data: BackupsResponse;
  typeFilter: BackupRun["type"] | "";
  statusFilter: BackupRunStatus | "";
  onTypeFilter: (value: BackupRun["type"] | "") => void;
  onStatusFilter: (value: BackupRunStatus | "") => void;
  onPage: (page: number) => void;
  onOpen: (id: number) => void;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <CardTitle>Istoric</CardTitle>
          <CardDescription>Toate backup-urile, testele de restaurare și restaurările. Apasă pe un rând pentru detalii și log.</CardDescription>
        </div>
        <div className="flex flex-wrap gap-2">
          <Select value={typeFilter} onChange={(value) => onTypeFilter(value as BackupRun["type"] | "")}>
            <option value="">Toate tipurile</option>
            <option value="backup">Backup</option>
            <option value="restore_test">Test restaurare</option>
            <option value="restore">Restaurare</option>
          </Select>
          <Select value={statusFilter} onChange={(value) => onStatusFilter(value as BackupRunStatus | "")}>
            <option value="">Toate statusurile</option>
            {(Object.keys(STATUS_LABEL) as BackupRunStatus[]).map((status) => (
              <option key={status} value={status}>
                {STATUS_LABEL[status]}
              </option>
            ))}
          </Select>
        </div>
      </CardHeader>
      <CardContent className="p-0">
        {data.runs.length === 0 ? (
          <div className="p-10 text-center text-sm text-muted-foreground">Nu există încă nicio înregistrare.</div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Status</TableHead>
                <TableHead>Backup</TableHead>
                <TableHead>Componente</TableHead>
                <TableHead className="text-right">Mărime</TableHead>
                <TableHead>Off-site</TableHead>
                <TableHead className="text-right">Durată</TableHead>
                <TableHead>Pornit</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.runs.map((run) => (
                <TableRow key={run.id} className="cursor-pointer" onClick={() => onOpen(run.id)}>
                  <TableCell>
                    <StatusBadge status={run.status} />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2 font-medium">
                      {run.is_locked ? <LockIcon className="h-3.5 w-3.5 text-muted-foreground" aria-label="Blocat" /> : null}
                      <span className="font-mono text-xs">{run.name}</span>
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                      {TYPE_LABEL[run.type]} · {TRIGGER_LABEL[run.trigger] ?? run.trigger}
                      {run.requested_by ? ` · ${run.requested_by}` : ""}
                      {run.source_run ? ` · din ${run.source_run.name}` : ""}
                      {run.type === "backup" && run.files_deleted_at && run.status !== "failed" ? " · fișiere șterse (retenție)" : ""}
                    </div>
                    {run.note ? <div className="mt-1 text-xs italic text-muted-foreground">{run.note}</div> : null}
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1">
                      {(run.artifacts.length > 0 ? run.artifacts : run.components.map((component) => ({ component, status: "pending" }))).map(
                        (artifact) => (
                          <ComponentChip key={artifact.component} component={artifact.component} status={artifact.status} />
                        ),
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{run.size_bytes > 0 ? formatBytes(run.size_bytes) : "—"}</TableCell>
                  <TableCell>
                    <RemoteBadge status={run.remote_status} />
                  </TableCell>
                  <TableCell className="text-right tabular-nums text-muted-foreground">{formatDuration(run.duration_seconds)}</TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(run.started_at ?? run.created_at)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
        {data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between border-t px-4 py-3 text-sm">
            <span className="text-muted-foreground">
              Pagina {data.meta.current_page} din {data.meta.last_page} · {data.meta.total} înregistrări
            </span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" disabled={data.meta.current_page <= 1} onClick={() => onPage(data.meta.current_page - 1)}>
                Înapoi
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page >= data.meta.last_page}
                onClick={() => onPage(data.meta.current_page + 1)}
              >
                Înainte
              </Button>
            </div>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

function StartBackupModal({
  settings,
  onClose,
  onStarted,
}: {
  settings: BackupSettings;
  onClose: () => void;
  onStarted: (run: BackupRun) => void;
}) {
  const [selected, setSelected] = useState<BackupComponent[]>(
    COMPONENTS.filter((component) => settings.components[component.key]).map((component) => component.key),
  );
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function start() {
    setBusy(true);
    setError(null);
    try {
      const response = await adminApi.startBackup(selected);
      onStarted(response.run);
    } catch (startError) {
      setError(startError instanceof Error ? startError.message : "Backup-ul nu a putut porni.");
      setBusy(false);
    }
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Backup acum"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Anulează
          </Button>
          <Button onClick={() => void start()} disabled={busy || selected.length === 0}>
            <PlayIcon className="h-4 w-4" />
            {busy ? "Se pornește…" : "Pornește backup-ul"}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm text-muted-foreground">
          Backup-ul rulează în fundal. Poți închide fereastra; progresul apare în istoric. Criptarea, copia off-site și retenția se aplică la fel
          ca la backup-urile programate.
        </p>
        {error ? <Alert tone="error">{error}</Alert> : null}
        {COMPONENTS.map((component) => (
          <label key={component.key} className="flex cursor-pointer items-start gap-3 rounded-lg border p-3">
            <input
              type="checkbox"
              className="mt-1"
              checked={selected.includes(component.key)}
              onChange={(event) =>
                setSelected((current) =>
                  event.target.checked ? [...current, component.key] : current.filter((key) => key !== component.key),
                )
              }
            />
            <span>
              <span className="block text-sm font-medium">{component.label}</span>
              <span className="block text-xs text-muted-foreground">{component.description}</span>
            </span>
          </label>
        ))}
      </div>
    </Modal>
  );
}

function RunDetailsModal({
  runId,
  onClose,
  onChanged,
  onOpenRun,
  onNotice,
}: {
  runId: number;
  onClose: () => void;
  onChanged: () => void;
  onOpenRun: (id: number) => void;
  onNotice: (message: string) => void;
}) {
  const [run, setRun] = useState<BackupRun | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleteRemote, setDeleteRemote] = useState(true);

  const fetchRun = useCallback(async () => {
    try {
      const response = await adminApi.getBackupRun(runId);
      setRun(response.run);
      setError(null);
      return response.run;
    } catch (fetchError) {
      setError(fetchError instanceof Error ? fetchError.message : "Nu s-a putut încărca backup-ul.");
      return null;
    }
  }, [runId]);

  useEffect(() => {
    setRun(null);
    setConfirmDelete(false);
    void fetchRun().then((loaded) => setNote(loaded?.note ?? ""));
  }, [fetchRun]);

  const running = run?.status === "queued" || run?.status === "running";

  useEffect(() => {
    if (!running) {
      return;
    }
    const timer = window.setInterval(() => {
      void fetchRun().then((updated) => {
        if (updated && updated.status !== "queued" && updated.status !== "running") {
          onChanged();
        }
      });
    }, 2000);
    return () => window.clearInterval(timer);
  }, [running, fetchRun, onChanged]);

  async function act(key: string, action: () => Promise<void>) {
    setBusy(key);
    setError(null);
    try {
      await action();
    } catch (actionError) {
      setError(actionError instanceof Error ? actionError.message : "Acțiunea a eșuat.");
    } finally {
      setBusy(null);
    }
  }

  async function download(artifact: BackupArtifact) {
    if (!run || !artifact.file) {
      return;
    }
    const file = artifact.file;
    await act(`download-${file}`, async () => {
      const { url } = await adminApi.getBackupDownloadLink(run.id, file);
      window.location.assign(url);
    });
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      size="xl"
      title={run ? run.name : "Backup"}
      footer={
        run ? (
          <div className="flex w-full flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-3">
              {confirmDelete ? (
                <>
                  {run.remote_status === "uploaded" ? (
                    <label className="flex items-center gap-2 text-sm">
                      <input type="checkbox" checked={deleteRemote} onChange={(event) => setDeleteRemote(event.target.checked)} />
                      Șterge și copia off-site
                    </label>
                  ) : null}
                  <Button
                    variant="destructive"
                    disabled={busy !== null}
                    onClick={() =>
                      void act("delete", async () => {
                        await adminApi.deleteBackupRun(run.id, deleteRemote);
                        onNotice(`${run.name} a fost șters.`);
                        onChanged();
                        onClose();
                      })
                    }
                  >
                    Confirmă ștergerea
                  </Button>
                  <Button variant="ghost" onClick={() => setConfirmDelete(false)}>
                    Renunță
                  </Button>
                </>
              ) : (
                <Button
                  variant="outline"
                  disabled={run.is_locked || running}
                  title={run.is_locked ? "Deblochează backup-ul ca să-l poți șterge" : undefined}
                  onClick={() => setConfirmDelete(true)}
                >
                  <TrashIcon className="h-4 w-4" />
                  Șterge
                </Button>
              )}
            </div>
            <Button variant="outline" onClick={onClose}>
              Închide
            </Button>
          </div>
        ) : undefined
      }
    >
      {error ? <Alert tone="error">{error}</Alert> : null}
      {!run ? (
        <div className="p-6 text-center text-sm text-muted-foreground">Se încarcă…</div>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <Info label="Status">
              <StatusBadge status={run.status} />
            </Info>
            <Info label="Tip">
              {TYPE_LABEL[run.type]} · {TRIGGER_LABEL[run.trigger] ?? run.trigger}
            </Info>
            <Info label="Pornit">{formatDate(run.started_at ?? run.created_at)}</Info>
            <Info label="Durată">{formatDuration(run.duration_seconds)}</Info>
            {run.type === "backup" ? (
              <>
                <Info label="Mărime">{formatBytes(run.size_bytes)}</Info>
                <Info label="Criptat">{run.encrypted ? "Da (AES-256)" : "Nu"}</Info>
                <Info label="Off-site">
                  <RemoteBadge status={run.remote_status} />
                </Info>
                <Info label="Pornit de">{run.requested_by ?? "sistem"}</Info>
              </>
            ) : null}
            {run.source_run ? (
              <Info label="Backup sursă">
                <button type="button" className="font-mono text-xs underline" onClick={() => onOpenRun(run.source_run!.id)}>
                  {run.source_run.name}
                </button>
              </Info>
            ) : null}
          </div>

          {run.error_message ? (
            <Alert tone={run.status === "partial" ? "warning" : "error"}>
              <span className="whitespace-pre-wrap">{run.error_message}</span>
            </Alert>
          ) : null}

          {run.type === "backup" && run.remote_path ? (
            <div className="rounded-lg border bg-muted/40 p-3 text-sm">
              <div className="flex items-center gap-2 font-medium">
                <CloudIcon className="h-4 w-4" />
                Copie off-site
              </div>
              <div className="mt-1 font-mono text-xs">{run.remote_path}</div>
              {run.remote_error ? <div className="mt-1 text-xs text-destructive">{run.remote_error}</div> : null}
            </div>
          ) : null}

          {run.type === "backup" ? (
            <div className="space-y-2">
              <div className="text-sm font-semibold">Componente</div>
              {run.artifacts.length === 0 ? (
                <div className="text-sm text-muted-foreground">{running ? "În lucru…" : "Nicio componentă."}</div>
              ) : (
                run.artifacts.map((artifact) => (
                  <ArtifactRow
                    key={artifact.component}
                    artifact={artifact}
                    busy={busy}
                    running={running}
                    onDownload={() => void download(artifact)}
                    onRestoreTest={() =>
                      void act(`test-${artifact.component}`, async () => {
                        const response = await adminApi.startRestoreTest(run.id, artifact.component as "database" | "analytics");
                        onNotice("Testul de restaurare a pornit.");
                        onChanged();
                        onOpenRun(response.run.id);
                      })
                    }
                  />
                ))
              )}
              {run.has_files ? (
                <Button
                  variant="link"
                  className="h-auto p-0 text-xs"
                  onClick={() => void download({ component: "database", file: "manifest.json", status: "completed" })}
                >
                  Descarcă manifest.json (checksum-uri)
                </Button>
              ) : null}
            </div>
          ) : (
            <RestoreTestResult run={run} />
          )}

          {run.type === "backup" && run.status !== "failed" && run.status !== "queued" && run.status !== "running" ? (
            <div className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_auto] md:items-end">
              <div className="space-y-2">
                <Label htmlFor="backup-note">Notă</Label>
                <Input
                  id="backup-note"
                  value={note}
                  maxLength={500}
                  placeholder="ex: înainte de migrarea catalogului"
                  onChange={(event) => setNote(event.target.value)}
                />
              </div>
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="outline"
                  disabled={busy !== null || note === (run.note ?? "")}
                  onClick={() =>
                    void act("note", async () => {
                      const response = await adminApi.updateBackupRun(run.id, { note: note || null });
                      setRun({ ...response.run, log: run.log });
                      onChanged();
                    })
                  }
                >
                  <SaveIcon className="h-4 w-4" />
                  Salvează nota
                </Button>
                <Button
                  variant="outline"
                  disabled={busy !== null}
                  onClick={() =>
                    void act("lock", async () => {
                      const response = await adminApi.updateBackupRun(run.id, { is_locked: !run.is_locked });
                      setRun({ ...response.run, log: run.log });
                      onChanged();
                    })
                  }
                >
                  {run.is_locked ? <LockOpenIcon className="h-4 w-4" /> : <LockIcon className="h-4 w-4" />}
                  {run.is_locked ? "Deblochează" : "Blochează"}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground md:col-span-2">
                Un backup blocat nu este șters de politica de retenție și nu poate fi șters manual.
              </p>
            </div>
          ) : null}

          {run.type === "backup" && run.has_files && run.artifacts.some((artifact) => artifact.meta?.format === "pg_custom") ? (
            <div className="space-y-2">
              <div className="text-sm font-semibold">Restaurare completă din acest backup</div>
              <CodeBlock>{`docker compose run --rm backup-worker php artisan backups:restore ${run.name}`}</CodeBlock>
              <p className="text-xs text-muted-foreground">Vezi tab-ul Restaurare pentru pașii compleți.</p>
            </div>
          ) : null}

          <div className="space-y-2">
            <div className="flex items-center gap-2 text-sm font-semibold">
              Log
              {running ? <RefreshCwIcon className="h-3.5 w-3.5 animate-spin text-muted-foreground" /> : null}
            </div>
            <pre className="admin-scrollbar max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border bg-muted p-3 font-mono text-xs">
              {run.log || "—"}
            </pre>
          </div>
        </div>
      )}
    </Modal>
  );
}

function ArtifactRow({
  artifact,
  busy,
  running,
  onDownload,
  onRestoreTest,
}: {
  artifact: BackupArtifact;
  busy: string | null;
  running: boolean;
  onDownload: () => void;
  onRestoreTest: () => void;
}) {
  const testable =
    artifact.downloadable &&
    (artifact.component === "database" || artifact.component === "analytics") &&
    (artifact.meta?.format === "pg_custom" || artifact.meta?.format === "sqlite_gzip");

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3 md:flex-row md:items-center md:justify-between">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <ArtifactStatusIcon status={artifact.status} />
          <span className="text-sm font-medium">{artifact.label ?? artifact.component}</span>
          {artifact.verified ? (
            <Badge variant="completed" className="gap-1">
              <ShieldCheckIcon className="h-3 w-3" />
              verificat
            </Badge>
          ) : null}
          {artifact.meta?.encrypted ? <Badge variant="featured">criptat</Badge> : null}
        </div>
        {artifact.file ? (
          <div className="font-mono text-xs text-muted-foreground">
            {artifact.file} · {formatBytes(artifact.size ?? 0)}
          </div>
        ) : null}
        {artifact.meta?.remote_path ? (
          <div className="font-mono text-xs text-muted-foreground">
            {artifact.meta.remote_path} · {artifact.meta.remote_objects ?? 0} fișiere · {formatBytes(artifact.meta.remote_bytes ?? 0)}
          </div>
        ) : null}
        {artifact.meta?.toc_entries ? (
          <div className="text-xs text-muted-foreground">
            {artifact.meta.toc_entries} obiecte în arhivă
            {artifact.meta.server_version ? ` · PostgreSQL ${artifact.meta.server_version}` : ""}
          </div>
        ) : null}
        {artifact.sha256 ? <div className="truncate font-mono text-[11px] text-muted-foreground">sha256 {artifact.sha256}</div> : null}
        {artifact.error ? (
          <div className={`text-xs ${artifact.status === "skipped" ? "text-muted-foreground" : "text-destructive"}`}>{artifact.error}</div>
        ) : null}
      </div>
      <div className="flex shrink-0 flex-wrap gap-2">
        {testable ? (
          <Button variant="outline" size="sm" disabled={busy !== null || running} onClick={onRestoreTest}>
            <FlaskConicalIcon className="h-4 w-4" />
            Test restaurare
          </Button>
        ) : null}
        {artifact.downloadable ? (
          <Button variant="outline" size="sm" disabled={busy !== null} onClick={onDownload}>
            <DownloadIcon className="h-4 w-4" />
            Descarcă
          </Button>
        ) : null}
      </div>
    </div>
  );
}

function RestoreTestResult({ run }: { run: BackupRun }) {
  const result = run.artifacts[0];
  if (!result || run.status !== "completed") {
    return null;
  }

  const counts = Object.entries(result.row_counts ?? {});

  return (
    <div className="space-y-2">
      <div className="text-sm font-semibold">Rezultat</div>
      <Alert tone="success">Backup-ul a fost restaurat cu succes într-o bază temporară ({result.tables ?? 0} tabele), apoi baza a fost ștearsă.</Alert>
      {counts.length > 0 ? (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Tabel</TableHead>
              <TableHead className="text-right">Rânduri în backup</TableHead>
              <TableHead className="text-right">Rânduri acum</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {counts.map(([table, count]) => (
              <TableRow key={table}>
                <TableCell className="font-mono text-xs">{table}</TableCell>
                <TableCell className="text-right tabular-nums">{count.restored.toLocaleString()}</TableCell>
                <TableCell className="text-right tabular-nums text-muted-foreground">{count.live?.toLocaleString() ?? "—"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      ) : null}
    </div>
  );
}

function SettingsTab({ data, onSaved }: { data: BackupsResponse; onSaved: (response: BackupsResponse) => void }) {
  const [form, setForm] = useState<BackupSettings>(data.settings);
  const [emailsText, setEmailsText] = useState(data.settings.notifications.emails.join("\n"));
  const [rcloneConfig, setRcloneConfig] = useState("");
  const [replaceConfig, setReplaceConfig] = useState(!data.settings.remote.rclone_config_set);
  const [clearConfig, setClearConfig] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [remoteResult, setRemoteResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [mailResult, setMailResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [testing, setTesting] = useState<"remote" | "mail" | null>(null);

  const schedule = useMemo(() => parseSchedule(form.schedule), [form.schedule]);

  function patch<K extends keyof BackupSettings>(key: K, value: BackupSettings[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function save() {
    setSaving(true);
    setError(null);
    const payload: BackupSettingsPayload = {
      enabled: form.enabled,
      schedule: form.schedule,
      timezone: form.timezone,
      components: form.components,
      retention: form.retention,
      encryption: form.encryption,
      remote: {
        enabled: form.remote.enabled,
        destination: form.remote.destination,
        prune: form.remote.prune,
        ...(clearConfig ? { clear_rclone_config: true } : rcloneConfig.trim() ? { rclone_config: rcloneConfig } : {}),
      },
      notifications: {
        ...form.notifications,
        emails: emailsText
          .split(/[\s,;]+/)
          .map((email) => email.trim())
          .filter(Boolean),
      },
    };

    try {
      const response = await adminApi.updateBackupSettings(payload);
      setForm(response.settings);
      setEmailsText(response.settings.notifications.emails.join("\n"));
      setRcloneConfig("");
      setClearConfig(false);
      setReplaceConfig(!response.settings.remote.rclone_config_set);
      onSaved(response);
    } catch (saveError) {
      const apiError = saveError as Error & { errors?: Record<string, string[]> };
      const details = apiError.errors ? Object.values(apiError.errors).flat().join(" ") : "";
      setError(details || apiError.message || "Setările nu au putut fi salvate.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      {error ? <Alert tone="error">{error}</Alert> : null}

      <Card>
        <CardHeader>
          <CardTitle>Programare</CardTitle>
          <CardDescription>Când rulează backup-ul automat. Ora este în fusul orar ales.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <ToggleRow
            label="Backup automat activ"
            description="Dacă este oprit, backup-urile rulează doar manual."
            checked={form.enabled}
            onChange={(checked) => patch("enabled", checked)}
          />
          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-2">
              <Label>Frecvență</Label>
              <Select
                value={schedule.preset}
                onChange={(value) => patch("schedule", buildSchedule(value as SchedulePreset, schedule.time, form.schedule))}
              >
                <option value="daily">Zilnic</option>
                <option value="12h">La fiecare 12 ore</option>
                <option value="6h">La fiecare 6 ore</option>
                <option value="weekly">Săptămânal (duminică)</option>
                <option value="custom">Personalizat (cron)</option>
              </Select>
            </div>
            {schedule.preset === "daily" || schedule.preset === "weekly" ? (
              <div className="space-y-2">
                <Label htmlFor="backup-time">Ora</Label>
                <Input
                  id="backup-time"
                  type="time"
                  value={schedule.time}
                  onChange={(event) => patch("schedule", buildSchedule(schedule.preset, event.target.value || "03:00", form.schedule))}
                />
              </div>
            ) : null}
            {schedule.preset === "custom" ? (
              <div className="space-y-2">
                <Label htmlFor="backup-cron">Expresie cron</Label>
                <Input id="backup-cron" value={form.schedule} className="font-mono" onChange={(event) => patch("schedule", event.target.value)} />
              </div>
            ) : null}
            <div className="space-y-2">
              <Label htmlFor="backup-timezone">Fus orar</Label>
              <Input id="backup-timezone" value={form.timezone} onChange={(event) => patch("timezone", event.target.value)} />
            </div>
          </div>
          <p className="text-xs text-muted-foreground">
            Cron: <span className="font-mono">{form.schedule}</span>
            {data.overview.next_run_at ? ` · următoarea rulare salvată: ${formatDate(data.overview.next_run_at)}` : ""}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Ce se salvează</CardTitle>
          <CardDescription>
            Componentele incluse în backup-ul automat. Meilisearch nu are nevoie de backup: indexul se reconstruiește cu
            <span className="font-mono"> php artisan search:reindex-content</span>.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {COMPONENTS.map((component) => (
            <ToggleRow
              key={component.key}
              label={component.label}
              description={
                component.key === "media" && !data.media_source
                  ? `${component.description} Bucket-ul nu este configurat (AWS_BUCKET).`
                  : component.description
              }
              checked={form.components[component.key]}
              onChange={(checked) => patch("components", { ...form.components, [component.key]: checked })}
            />
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Retenție</CardTitle>
          <CardDescription>
            Cele mai noi N backup-uri reușite se păstrează mereu. Restul se șterg după numărul de zile ales. Backup-urile blocate nu se șterg
            niciodată.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <NumberField
            id="keep-last"
            label="Păstrează mereu ultimele"
            suffix="backup-uri"
            value={form.retention.keep_last}
            min={1}
            max={365}
            onChange={(value) => patch("retention", { ...form.retention, keep_last: value })}
          />
          <NumberField
            id="keep-days"
            label="Șterge backup-urile mai vechi de"
            suffix="zile"
            value={form.retention.keep_days}
            min={1}
            max={3650}
            onChange={(value) => patch("retention", { ...form.retention, keep_days: value })}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Copie off-site (rclone)</CardTitle>
          <CardDescription>
            Fiecare backup se copiază după finalizare în destinația aleasă: Google Drive, S3, Cloudflare R2, Backblaze B2, SFTP etc.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <ToggleRow
            label="Copie off-site activă"
            description="Recomandat: fără copie off-site, pierderea serverului înseamnă pierderea backup-urilor."
            checked={form.remote.enabled}
            onChange={(checked) => patch("remote", { ...form.remote, enabled: checked })}
          />
          <div className="space-y-2">
            <Label htmlFor="remote-destination">Destinație</Label>
            <Input
              id="remote-destination"
              className="font-mono"
              placeholder="gdrive:film-md-backups"
              value={form.remote.destination}
              onChange={(event) => patch("remote", { ...form.remote, destination: event.target.value })}
            />
            <p className="text-xs text-muted-foreground">Numele remote-ului din rclone.conf, urmat de „:” și folder.</p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="rclone-config">Configurare rclone</Label>
            {data.settings.remote.rclone_config_set && !replaceConfig ? (
              <div className="flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm">
                <CheckCircle2Icon className="h-4 w-4 text-emerald-600" />
                {clearConfig ? "Va fi ștearsă la salvare." : "Configurată (stocată criptat, nu se afișează)."}
                <Button variant="outline" size="sm" onClick={() => setReplaceConfig(true)}>
                  Înlocuiește
                </Button>
                <Button variant="ghost" size="sm" onClick={() => setClearConfig((current) => !current)}>
                  {clearConfig ? "Păstrează" : "Șterge"}
                </Button>
              </div>
            ) : (
              <>
                <Textarea
                  id="rclone-config"
                  rows={6}
                  className="font-mono text-xs"
                  placeholder={"[gdrive]\ntype = drive\nscope = drive\ntoken = {\"access_token\":\"…\"}"}
                  value={rcloneConfig}
                  onChange={(event) => setRcloneConfig(event.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                  Pe calculatorul tău rulează <span className="font-mono">rclone config</span>, creează remote-ul, apoi lipește aici conținutul
                  fișierului afișat de <span className="font-mono">rclone config file</span>. Poate conține doar remote-ul folosit.
                </p>
              </>
            )}
          </div>
          <ToggleRow
            label="Aplică retenția și off-site"
            description="Când un backup e șters (retenție sau manual), se șterge și copia din destinație."
            checked={form.remote.prune}
            onChange={(checked) => patch("remote", { ...form.remote, prune: checked })}
          />
          <div className="flex flex-wrap items-center gap-3">
            <Button
              variant="outline"
              disabled={testing !== null || !form.remote.destination}
              onClick={async () => {
                setTesting("remote");
                setRemoteResult(null);
                try {
                  setRemoteResult(await adminApi.testBackupRemote(form.remote.destination));
                } catch (testError) {
                  setRemoteResult({ ok: false, message: testError instanceof Error ? testError.message : "Testul a eșuat." });
                } finally {
                  setTesting(null);
                }
              }}
            >
              <CloudIcon className="h-4 w-4" />
              {testing === "remote" ? "Se testează…" : "Testează conexiunea"}
            </Button>
            <span className="text-xs text-muted-foreground">Folosește configurarea salvată; salvează întâi dacă ai schimbat-o.</span>
          </div>
          {remoteResult ? <Alert tone={remoteResult.ok ? "success" : "error"}>{remoteResult.message}</Alert> : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Criptare</CardTitle>
          <CardDescription>
            Fișierele de backup sunt criptate AES-256 cu parola din <span className="font-mono">BACKUP_ENCRYPTION_PASSPHRASE</span>. Păstrează
            parola și în afara serverului: fără ea backup-urile criptate nu pot fi restaurate.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ToggleRow
            label="Criptează backup-urile"
            description={
              data.encryption_available
                ? "Recomandat când folosești o copie off-site."
                : "Indisponibil: setează BACKUP_ENCRYPTION_PASSPHRASE în .env și repornește containerele."
            }
            checked={form.encryption.enabled}
            disabled={!data.encryption_available && !form.encryption.enabled}
            onChange={(checked) => patch("encryption", { enabled: checked })}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Notificări</CardTitle>
          <CardDescription>Email la eșec și alertă dacă nu a reușit niciun backup de prea mult timp.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="backup-emails">Adrese email</Label>
            <Textarea
              id="backup-emails"
              rows={3}
              placeholder={"admin@filmoteca.md"}
              value={emailsText}
              onChange={(event) => setEmailsText(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">Câte una pe rând sau separate prin virgulă. Maxim 10.</p>
          </div>
          <ToggleRow
            label="Email la eșec sau backup parțial"
            checked={form.notifications.on_failure}
            onChange={(checked) => patch("notifications", { ...form.notifications, on_failure: checked })}
          />
          <ToggleRow
            label="Email și la succes"
            checked={form.notifications.on_success}
            onChange={(checked) => patch("notifications", { ...form.notifications, on_success: checked })}
          />
          <NumberField
            id="stale-hours"
            label="Alertă dacă nu există backup reușit de mai mult de"
            suffix="ore"
            value={form.notifications.stale_after_hours}
            min={1}
            max={720}
            onChange={(value) => patch("notifications", { ...form.notifications, stale_after_hours: value })}
          />
          <div className="flex flex-wrap items-center gap-3">
            <Button
              variant="outline"
              disabled={testing !== null}
              onClick={async () => {
                setTesting("mail");
                setMailResult(null);
                try {
                  setMailResult(await adminApi.testBackupNotification());
                } catch (testError) {
                  setMailResult({ ok: false, message: testError instanceof Error ? testError.message : "Testul a eșuat." });
                } finally {
                  setTesting(null);
                }
              }}
            >
              <MailIcon className="h-4 w-4" />
              {testing === "mail" ? "Se trimite…" : "Trimite email de test"}
            </Button>
            <span className="text-xs text-muted-foreground">Trimite către adresele salvate.</span>
          </div>
          {mailResult ? <Alert tone={mailResult.ok ? "success" : "error"}>{mailResult.message}</Alert> : null}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button onClick={() => void save()} disabled={saving}>
          <SaveIcon className="h-4 w-4" />
          {saving ? "Se salvează…" : "Salvează setările"}
        </Button>
      </div>
    </div>
  );
}

function PreMigrationTab({ dumps, onError }: { dumps: BackupsResponse["pre_migration"]; onError: (message: string) => void }) {
  const [busy, setBusy] = useState<string | null>(null);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Dump-uri pre-migrare</CardTitle>
        <CardDescription>
          Create automat la fiecare deploy în producție, înainte de <span className="font-mono">php artisan migrate</span>. Se șterg după
          <span className="font-mono"> DATABASE_BACKUP_RETENTION_DAYS</span> zile.
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        {dumps.length === 0 ? (
          <div className="p-10 text-center text-sm text-muted-foreground">Nu există dump-uri pre-migrare pe acest server.</div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fișier</TableHead>
                <TableHead className="text-right">Mărime</TableHead>
                <TableHead>Creat</TableHead>
                <TableHead className="text-right" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {dumps.map((dump) => (
                <TableRow key={dump.name}>
                  <TableCell className="font-mono text-xs">{dump.name}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatBytes(dump.size)}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{formatDate(dump.created_at)}</TableCell>
                  <TableCell className="text-right">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={busy !== null}
                      onClick={async () => {
                        setBusy(dump.name);
                        try {
                          const { url } = await adminApi.getPreMigrationDownloadLink(dump.name);
                          window.location.assign(url);
                        } catch (downloadError) {
                          onError(downloadError instanceof Error ? downloadError.message : "Descărcarea a eșuat.");
                        } finally {
                          setBusy(null);
                        }
                      }}
                    >
                      <DownloadIcon className="h-4 w-4" />
                      Descarcă
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  );
}

function RestoreTab({ data }: { data: BackupsResponse }) {
  const destination = data.settings.remote.destination || "gdrive:film-md-backups";

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <FlaskConicalIcon className="h-4 w-4" />
            1. Testează periodic restaurarea
          </CardTitle>
          <CardDescription>
            Un backup care nu a fost niciodată restaurat nu este un backup sigur. Din istoric, deschide un backup și apasă „Test restaurare”:
            dump-ul este restaurat într-o bază temporară, se numără rândurile din tabelele importante, apoi baza temporară se șterge. Datele
            live nu sunt atinse. Recomandat o dată pe lună.
          </CardDescription>
        </CardHeader>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <HistoryIcon className="h-4 w-4" />
            2. Restaurare completă pe același server
          </CardTitle>
          <CardDescription>
            Înlocuiește toate datele din baza live. Se face din terminal, nu din admin, pentru că aplicația nu trebuie să ruleze în timpul
            restaurării. Comanda face automat un backup de siguranță (blocat) înainte, verifică checksum-ul și cere confirmarea numelui bazei.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <CodeBlock>{`cd film.md-admin-api
docker compose stop app queue scheduler

# numele backup-ului îl găsești în istoric (ex: film-md-20260926-030000-ab12)
docker compose run --rm backup-worker php artisan backups:restore NUME-BACKUP

docker compose start app queue scheduler
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan search:reindex-content`}</CodeBlock>
          <p className="text-xs text-muted-foreground">
            Pentru baza analytics adaugă <span className="font-mono">--component=analytics</span>.
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <CloudIcon className="h-4 w-4" />
            3. Recuperare după pierderea serverului
          </CardTitle>
          <CardDescription>
            Pe un server nou, cu proiectul pornit (<span className="font-mono">docker compose up -d postgres</span>), aduci backup-ul din copia
            off-site și îl restaurezi direct cu pg_restore.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <CodeBlock>{`rclone copy ${destination.replace(/\/$/, "")}/NUME-BACKUP ./restore

# doar dacă backup-ul e criptat (fișiere .enc):
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \\
  -in ./restore/database-film_md.dump.enc -out ./restore/database-film_md.dump \\
  -pass env:BACKUP_ENCRYPTION_PASSPHRASE

docker compose cp ./restore/database-film_md.dump postgres:/tmp/restore.dump
docker compose exec postgres pg_restore --clean --if-exists --no-owner --no-privileges \\
  -U postgres -d film_md /tmp/restore.dump`}</CodeBlock>
          <p className="text-xs text-muted-foreground">
            Verifică integritatea înainte cu <span className="font-mono">sha256sum</span> față de valorile din manifest.json.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}

function Alert({ tone, children }: { tone: "error" | "warning" | "success"; children: ReactNode }) {
  const styles = {
    error: "border-destructive/30 bg-destructive/10 text-destructive",
    warning: "border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300",
    success: "border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300",
  };
  const Icon = tone === "success" ? CheckCircle2Icon : AlertTriangleIcon;

  return (
    <div className={`flex items-start gap-2 rounded-md border px-4 py-3 text-sm ${styles[tone]}`}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" />
      <div className="min-w-0">{children}</div>
    </div>
  );
}

function MetricCard({
  title,
  value,
  detail,
  icon: Icon,
  tone = "default",
}: {
  title: string;
  value: string;
  detail: string;
  icon: ElementType;
  tone?: "default" | "error";
}) {
  return (
    <div className={`rounded-lg border bg-background p-4 ${tone === "error" ? "border-destructive/40" : ""}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="text-sm text-muted-foreground">{title}</div>
          <div className={`mt-2 text-2xl font-semibold ${tone === "error" ? "text-destructive" : ""}`}>{value}</div>
          <div className="mt-1 truncate text-xs text-muted-foreground" title={detail}>
            {detail}
          </div>
        </div>
        <div className="rounded-md border bg-muted p-2">
          <Icon className="h-4 w-4" />
        </div>
      </div>
    </div>
  );
}

function ToggleRow({
  label,
  description,
  checked,
  disabled,
  onChange,
}: {
  label: string;
  description?: string;
  checked: boolean;
  disabled?: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-lg border p-3">
      <div>
        <div className="text-sm font-medium">{label}</div>
        {description ? <div className="mt-0.5 text-xs text-muted-foreground">{description}</div> : null}
      </div>
      <Switch checked={checked} disabled={disabled} onCheckedChange={onChange} aria-label={label} />
    </div>
  );
}

function NumberField({
  id,
  label,
  suffix,
  value,
  min,
  max,
  onChange,
}: {
  id: string;
  label: string;
  suffix: string;
  value: number;
  min: number;
  max: number;
  onChange: (value: number) => void;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <div className="flex items-center gap-2">
        <Input
          id={id}
          type="number"
          min={min}
          max={max}
          value={value}
          className="w-28"
          onChange={(event) => onChange(Math.min(max, Math.max(min, Number(event.target.value) || min)))}
        />
        <span className="text-sm text-muted-foreground">{suffix}</span>
      </div>
    </div>
  );
}

function Select({ value, onChange, children }: { value: string; onChange: (value: string) => void; children: ReactNode }) {
  return (
    <select
      value={value}
      onChange={(event) => onChange(event.target.value)}
      className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {children}
    </select>
  );
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-1">{children}</div>
    </div>
  );
}

function CodeBlock({ children }: { children: string }) {
  return (
    <pre className="admin-scrollbar overflow-x-auto rounded-lg border bg-muted p-4 font-mono text-xs text-foreground">{children}</pre>
  );
}

function StatusBadge({ status }: { status: BackupRunStatus }) {
  const variant = {
    queued: "draft",
    running: "scheduled",
    completed: "completed",
    partial: "ready",
    failed: "archived",
  } as const;

  return (
    <Badge variant={variant[status]} className="gap-1 whitespace-nowrap">
      {status === "running" ? <RefreshCwIcon className="h-3 w-3 animate-spin" /> : null}
      {STATUS_LABEL[status]}
    </Badge>
  );
}

function RemoteBadge({ status }: { status: BackupRun["remote_status"] }) {
  if (status === "skipped") {
    return <span className="text-sm text-muted-foreground">—</span>;
  }

  const variant = {
    pending: "draft",
    uploading: "scheduled",
    uploaded: "completed",
    failed: "archived",
    deleted: "inactive",
  } as const;

  return <Badge variant={variant[status]}>{REMOTE_LABEL[status]}</Badge>;
}

function ComponentChip({ component, status }: { component: string; status: string }) {
  const label = { database: "DB", analytics: "Analytics", redis: "Redis", media: "Media" }[component] ?? component;
  const classes =
    status === "completed"
      ? "border-emerald-200 bg-emerald-50 text-emerald-700"
      : status === "failed"
        ? "border-rose-200 bg-rose-50 text-rose-700"
        : "border-slate-200 bg-slate-50 text-slate-500";

  return (
    <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium ${classes}`}>
      {status === "completed" ? <CheckCircle2Icon className="h-3 w-3" /> : null}
      {status === "failed" ? <XCircleIcon className="h-3 w-3" /> : null}
      {status === "skipped" ? <MinusCircleIcon className="h-3 w-3" /> : null}
      {label}
    </span>
  );
}

function ArtifactStatusIcon({ status }: { status: BackupArtifact["status"] }) {
  if (status === "completed") {
    return <CheckCircle2Icon className="h-4 w-4 text-emerald-600" />;
  }
  if (status === "failed") {
    return <XCircleIcon className="h-4 w-4 text-destructive" />;
  }
  return <MinusCircleIcon className="h-4 w-4 text-muted-foreground" />;
}

function parseSchedule(cron: string): { preset: SchedulePreset; time: string } {
  const parts = cron.trim().split(/\s+/);
  const time = (minute: string, hour: string) => `${hour.padStart(2, "0")}:${minute.padStart(2, "0")}`;

  if (parts.length === 5 && /^\d+$/.test(parts[0]) && /^\d+$/.test(parts[1]) && parts[2] === "*" && parts[3] === "*") {
    if (parts[4] === "*") {
      return { preset: "daily", time: time(parts[0], parts[1]) };
    }
    if (parts[4] === "0") {
      return { preset: "weekly", time: time(parts[0], parts[1]) };
    }
  }
  if (cron.trim() === "0 */12 * * *") {
    return { preset: "12h", time: "03:00" };
  }
  if (cron.trim() === "0 */6 * * *") {
    return { preset: "6h", time: "03:00" };
  }

  return { preset: "custom", time: "03:00" };
}

function buildSchedule(preset: SchedulePreset, time: string, current: string): string {
  const [hour, minute] = time.split(":").map((part) => String(Number(part) || 0));

  switch (preset) {
    case "daily":
      return `${minute} ${hour} * * *`;
    case "weekly":
      return `${minute} ${hour} * * 0`;
    case "12h":
      return "0 */12 * * *";
    case "6h":
      return "0 */6 * * *";
    default:
      return current;
  }
}

function formatBytes(bytes: number): string {
  if (!bytes) {
    return "0 B";
  }
  const units = ["B", "KB", "MB", "GB", "TB"];
  const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** power).toFixed(power === 0 ? 0 : 1)} ${units[power]}`;
}

function formatDate(value: string | null): string {
  if (!value) {
    return "—";
  }
  return new Date(value).toLocaleString("ro-RO", { dateStyle: "medium", timeStyle: "short" });
}

function formatRelative(value: string): string {
  const diffSeconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
  const formatter = new Intl.RelativeTimeFormat("ro", { numeric: "auto" });
  const abs = Math.abs(diffSeconds);

  if (abs < 60) {
    return formatter.format(diffSeconds, "second");
  }
  if (abs < 3600) {
    return formatter.format(Math.round(diffSeconds / 60), "minute");
  }
  if (abs < 86400) {
    return formatter.format(Math.round(diffSeconds / 3600), "hour");
  }
  return formatter.format(Math.round(diffSeconds / 86400), "day");
}

function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds === undefined) {
    return "—";
  }
  if (seconds < 60) {
    return `${seconds}s`;
  }
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) {
    return `${minutes}m ${seconds % 60}s`;
  }
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
