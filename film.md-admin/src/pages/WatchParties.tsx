import { useEffect, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { MessageCircleIcon, PlayIcon, PlusIcon, SquareIcon, Trash2Icon, UsersIcon, VideoIcon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { Modal } from "../components/shared/Modal";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Switch } from "../components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";

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

const emptyForm = {
  content_id: "" as string | number,
  title: "",
  scheduled_start_at: "",
  is_public: true,
  chat_enabled: true,
  max_participants: "" as string | number,
};

export function WatchParties() {
  const { t } = useTranslation();
  const [items, setItems] = useState<Party[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(emptyForm);

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
    if (!form.content_id || !form.title || !form.scheduled_start_at) {
      return;
    }

    await adminApi.createWatchParty({
      content_id: Number(form.content_id),
      title: form.title,
      scheduled_start_at: new Date(form.scheduled_start_at).toISOString(),
      is_public: form.is_public,
      chat_enabled: form.chat_enabled,
      max_participants: form.max_participants ? Number(form.max_participants) : undefined,
    });
    setShowForm(false);
    setForm(emptyForm);
    await load();
  }

  async function deleteParty(id: number) {
    if (!confirm("Confirmă ștergerea?")) {
      return;
    }

    await adminApi.deleteWatchParty(id);
    await load();
  }

  const liveCount = items.filter((party) => party.status === "live").length;
  const scheduledCount = items.filter((party) => party.status === "scheduled").length;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">{t("watch_parties.title")}</h1>
          <p className="page-description">
            Programează sesiuni de vizionare sincronizată, controlează startul și gestionează camerele active.
          </p>
        </div>

        <Button onClick={() => setShowForm(true)}>
          <PlusIcon className="h-4 w-4" />
          {t("watch_parties.create")}
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <SummaryCard title="Total camere" value={items.length} description="Watch parties create" icon={<VideoIcon className="h-4 w-4" />} />
        <SummaryCard title="Live" value={liveCount} description="Camere pornite acum" icon={<PlayIcon className="h-4 w-4" />} />
        <SummaryCard title="Programate" value={scheduledCount} description="Evenimente care urmează" icon={<UsersIcon className="h-4 w-4" />} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Camere watch party</CardTitle>
          <CardDescription>Pornește, încheie sau șterge camerele create pentru filme.</CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {loading ? (
            <div className="p-10 text-center text-sm text-muted-foreground">{t("common.loading")}…</div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Titlu</TableHead>
                  <TableHead>Film</TableHead>
                  <TableHead>Cod cameră</TableHead>
                  <TableHead>Start programat</TableHead>
                  <TableHead>Setări</TableHead>
                  <TableHead>{t("common.status")}</TableHead>
                  <TableHead className="text-right">{t("common.actions")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                      Nu există watch parties programate.
                    </TableCell>
                  </TableRow>
                ) : (
                  items.map((party) => (
                    <TableRow key={party.id}>
                      <TableCell>
                        <div className="font-medium">{party.title}</div>
                        <div className="mt-1 text-xs text-muted-foreground">Content ID: {party.content_id}</div>
                      </TableCell>
                      <TableCell>{party.content_title ?? "—"}</TableCell>
                      <TableCell>
                        <span className="rounded-md border bg-muted px-2 py-1 font-mono text-xs">{party.room_code}</span>
                      </TableCell>
                      <TableCell>{formatDate(party.scheduled_start_at)}</TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-2">
                          <Badge variant={party.is_public ? "active" : "inactive"}>{party.is_public ? "Public" : "Privat"}</Badge>
                          <Badge variant={party.chat_enabled ? "featured" : "inactive"} className="gap-1">
                            <MessageCircleIcon className="h-3 w-3" />
                            Chat
                          </Badge>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant={statusVariant(party.status)}>
                          {t(`watch_parties.statuses.${party.status}` as never, { defaultValue: party.status }) as string}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex justify-end gap-2">
                          {party.status === "scheduled" ? (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={async () => {
                                await adminApi.startWatchParty(party.id);
                                await load();
                              }}
                            >
                              <PlayIcon className="h-4 w-4" />
                              {t("watch_parties.actions.start")}
                            </Button>
                          ) : null}
                          {party.status === "live" ? (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={async () => {
                                await adminApi.endWatchParty(party.id);
                                await load();
                              }}
                            >
                              <SquareIcon className="h-4 w-4" />
                              {t("watch_parties.actions.end")}
                            </Button>
                          ) : null}
                          <Button variant="outline" size="icon" onClick={() => void deleteParty(party.id)} title={t("common.delete")}>
                            <Trash2Icon className="h-4 w-4 text-destructive" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Modal
        isOpen={showForm}
        onClose={() => setShowForm(false)}
        title={t("watch_parties.create")}
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setShowForm(false)}>
              {t("common.cancel")}
            </Button>
            <Button onClick={() => void submit()}>{t("common.save")}</Button>
          </>
        }
      >
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={t("watch_parties.form.content")} span={2}>
            <Input
              type="number"
              placeholder="content_id"
              value={form.content_id}
              onChange={(event) => setForm({ ...form, content_id: event.target.value })}
            />
          </Field>
          <Field label={t("watch_parties.form.title")} span={2}>
            <Input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
          </Field>
          <Field label={t("watch_parties.form.scheduled_start_at")}>
            <Input
              type="datetime-local"
              value={form.scheduled_start_at}
              onChange={(event) => setForm({ ...form, scheduled_start_at: event.target.value })}
            />
          </Field>
          <Field label={t("watch_parties.form.max_participants")}>
            <Input
              type="number"
              value={form.max_participants}
              onChange={(event) => setForm({ ...form, max_participants: event.target.value })}
            />
          </Field>
          <ToggleField
            title={t("watch_parties.form.is_public")}
            description="Camera apare public pentru utilizatorii eligibili."
            checked={form.is_public}
            onChange={(checked) => setForm({ ...form, is_public: checked })}
          />
          <ToggleField
            title={t("watch_parties.form.chat_enabled")}
            description="Participanții pot discuta în timpul vizionării."
            checked={form.chat_enabled}
            onChange={(checked) => setForm({ ...form, chat_enabled: checked })}
          />
        </div>
      </Modal>
    </div>
  );
}

function Field({ label, span = 1, children }: { label: string; span?: 1 | 2; children: ReactNode }) {
  return (
    <label className={`flex flex-col gap-2 text-sm font-medium ${span === 2 ? "md:col-span-2" : ""}`}>
      <span className="text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}

function ToggleField({
  title,
  description,
  checked,
  onChange,
}: {
  title: string;
  description: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <div className="flex items-center justify-between rounded-lg border bg-muted/40 px-4 py-3">
      <div>
        <div className="text-sm font-medium">{title}</div>
        <div className="text-xs text-muted-foreground">{description}</div>
      </div>
      <Switch checked={checked} onCheckedChange={onChange} />
    </div>
  );
}

function SummaryCard({ title, value, description, icon }: { title: string; value: number; description: string; icon: ReactNode }) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div className="space-y-1">
          <CardDescription>{title}</CardDescription>
          <CardTitle>{value.toLocaleString()}</CardTitle>
        </div>
        <div className="rounded-md border bg-muted p-2">{icon}</div>
      </CardHeader>
      <CardContent className="pt-0">
        <p className="text-sm text-muted-foreground">{description}</p>
      </CardContent>
    </Card>
  );
}

function formatDate(value: string | null) {
  return value ? value.slice(0, 16).replace("T", " ") : "—";
}

function statusVariant(status: string) {
  if (status === "scheduled") {
    return "scheduled" as const;
  }
  if (status === "live") {
    return "active" as const;
  }
  if (status === "ended") {
    return "completed" as const;
  }
  return "inactive" as const;
}
