import { useEffect, useMemo, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { CheckIcon, ChevronsUpDownIcon, PlusIcon, SearchIcon, XIcon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { Modal } from "../components/shared/Modal";
import { Button } from "../components/ui/button";
import { Card, CardContent } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Switch } from "../components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";
import type { AdminContent } from "../types";

interface Creator {
  id: number;
  name: string;
  email: string | null;
  company_name: string | null;
  platform_fee_percent: number;
  is_active: boolean;
  user: { id: number; name: string; email: string } | null;
  content_count: number;
  contents: Array<{ id: number; title: string }>;
}

export function ContentCreators() {
  const { t } = useTranslation();
  const [items, setItems] = useState<Creator[]>([]);
  const [contentOptions, setContentOptions] = useState<AdminContent[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Creator | null>(null);
  const [statementsFor, setStatementsFor] = useState<Creator | null>(null);
  const [statements, setStatements] = useState<Awaited<ReturnType<typeof adminApi.getCreatorStatements>>["items"]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [form, setForm] = useState({
    user_id: "" as string | number,
    name: "",
    email: "",
    company_name: "",
    platform_fee_percent: 70,
    is_active: true,
    content_ids: [] as number[],
  });

  async function load() {
    setLoading(true);
    try {
      const [creatorsResponse, contentResponse] = await Promise.all([
        adminApi.getContentCreators(),
        adminApi.getContentIndex(),
      ]);
      setItems(creatorsResponse.items);
      setContentOptions(contentResponse.items);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function startCreate() {
    setEditing(null);
    setForm({
      user_id: "",
      name: "",
      email: "",
      company_name: "",
      platform_fee_percent: 70,
      is_active: true,
      content_ids: [],
    });
    setShowForm(true);
  }

  function startEdit(creator: Creator) {
    setEditing(creator);
    setForm({
      user_id: creator.user?.id ?? "",
      name: creator.name,
      email: creator.email ?? "",
      company_name: creator.company_name ?? "",
      platform_fee_percent: creator.platform_fee_percent,
      is_active: creator.is_active,
      content_ids: creator.contents.map((content) => content.id),
    });
    setShowForm(true);
  }

  async function submit() {
    setIsSubmitting(true);
    const payload: Record<string, unknown> = {
      user_id: form.user_id ? Number(form.user_id) : null,
      name: form.name,
      email: form.email || null,
      company_name: form.company_name || null,
      platform_fee_percent: Number(form.platform_fee_percent),
      is_active: form.is_active,
      content_ids: form.content_ids,
    };

    try {
      if (editing) await adminApi.updateContentCreator(editing.id, payload);
      else await adminApi.createContentCreator(payload);
      setShowForm(false);
      await load();
    } finally {
      setIsSubmitting(false);
    }
  }

  async function remove(id: number) {
    if (!confirm("Confirmă ștergerea?")) return;
    await adminApi.deleteContentCreator(id);
    await load();
  }

  async function viewStatements(creator: Creator) {
    setStatementsFor(creator);
    const response = await adminApi.getCreatorStatements(creator.id);
    setStatements(response.items);
  }

  return (
    <div className="p-6">
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-2xl font-semibold">{t("creators.title")}</h1>
        <Button onClick={startCreate}>
          <PlusIcon className="h-4 w-4" />
          {t("creators.create")}
        </Button>
      </div>

      {loading ? (
        <div>{t("common.loading")}</div>
      ) : (
        <Card>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("common.name")}</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>{t("creators.form.user")}</TableHead>
                  <TableHead className="text-right">Comision %</TableHead>
                  <TableHead className="text-right">Filme</TableHead>
                  <TableHead>{t("common.status")}</TableHead>
                  <TableHead className="text-right">{t("common.actions")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((creator) => (
                  <TableRow key={creator.id}>
                    <TableCell className="font-medium">{creator.name}</TableCell>
                    <TableCell className="text-muted-foreground">{creator.email}</TableCell>
                    <TableCell className="text-muted-foreground">{creator.user?.email ?? "—"}</TableCell>
                    <TableCell className="text-right">{creator.platform_fee_percent}%</TableCell>
                    <TableCell className="text-right">{creator.content_count}</TableCell>
                    <TableCell>
                      <Badge variant={creator.is_active ? "active" : "inactive"}>
                        {creator.is_active ? t("common.active") : t("common.inactive")}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => viewStatements(creator)}>
                          {t("creators.statements")}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => startEdit(creator)}>
                          {t("common.edit")}
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-destructive hover:text-destructive"
                          onClick={() => remove(creator.id)}
                        >
                          {t("common.delete")}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <Modal
        isOpen={showForm}
        onClose={() => setShowForm(false)}
        title={editing ? t("common.edit") : t("creators.create")}
        size="xl"
        footer={
          <>
            <Button variant="outline" onClick={() => setShowForm(false)}>
              {t("common.cancel")}
            </Button>
            <Button onClick={() => void submit()} disabled={isSubmitting || !form.name.trim()}>
              {isSubmitting ? "Se salvează..." : t("common.save")}
            </Button>
          </>
        }
      >
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
          <div className="grid gap-4 md:grid-cols-2">
            <Field label={t("creators.form.name")}>
              <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            </Field>
            <Field label={t("creators.form.email")}>
              <Input
                type="email"
                value={form.email}
                onChange={(event) => setForm({ ...form, email: event.target.value })}
              />
            </Field>
            <Field label={t("creators.form.user")}>
              <Input
                type="number"
                placeholder="user_id"
                value={form.user_id}
                onChange={(event) => setForm({ ...form, user_id: event.target.value })}
              />
            </Field>
            <Field label={t("creators.form.company_name")}>
              <Input
                value={form.company_name}
                onChange={(event) => setForm({ ...form, company_name: event.target.value })}
              />
            </Field>
            <Field label={t("creators.form.platform_fee_percent")}>
              <Input
                type="number"
                min={0}
                max={100}
                value={form.platform_fee_percent}
                onChange={(event) => setForm({ ...form, platform_fee_percent: Number(event.target.value) })}
              />
            </Field>
            <label className="flex items-center justify-between gap-4 rounded-lg border p-4 md:col-span-2">
              <div className="space-y-1">
                <span className="text-sm font-medium">{t("common.active")}</span>
                <p className="text-xs text-muted-foreground">Creatorul va putea primi titluri și rapoarte.</p>
              </div>
              <Switch checked={form.is_active} onCheckedChange={(checked) => setForm({ ...form, is_active: checked })} />
            </label>
          </div>

          <Field label={t("creators.form.content_ids")}>
            <ContentMultiSelect
              items={contentOptions}
              selectedIds={form.content_ids}
              onChange={(content_ids) => setForm({ ...form, content_ids })}
            />
          </Field>
        </div>
      </Modal>

      <Modal
        isOpen={Boolean(statementsFor)}
        onClose={() => setStatementsFor(null)}
        title={statementsFor ? `${t("creators.statements")} — ${statementsFor.name}` : t("creators.statements")}
        size="lg"
      >
        {statements.length === 0 ? (
          <div className="text-sm text-muted-foreground">Nu există statemente încă.</div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Lună</TableHead>
                <TableHead className="text-right">Venit USD</TableHead>
                <TableHead className="text-right">Costuri USD</TableHead>
                <TableHead className="text-right">Plată USD</TableHead>
                <TableHead className="text-right">Profit USD</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {statements.map((statement) => (
                <TableRow key={statement.id}>
                  <TableCell>{statement.month}</TableCell>
                  <TableCell className="text-right">{statement.revenue_usd.toFixed(2)}</TableCell>
                  <TableCell className="text-right">{statement.costs_usd.toFixed(2)}</TableCell>
                  <TableCell className="text-right">{statement.payout_usd.toFixed(2)}</TableCell>
                  <TableCell className="text-right">{statement.profit_usd.toFixed(2)}</TableCell>
                  <TableCell>{statement.is_locked ? "lock" : "open"}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Modal>
    </div>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="space-y-2">
      <Label>{label}</Label>
      {children}
    </div>
  );
}

function ContentMultiSelect({
  items,
  selectedIds,
  onChange,
}: {
  items: AdminContent[];
  selectedIds: number[];
  onChange: (ids: number[]) => void;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState("");
  const selectedItems = useMemo(
    () => selectedIds.map((id) => items.find((item) => item.id === id)).filter((item): item is AdminContent => Boolean(item)),
    [items, selectedIds],
  );
  const filteredItems = useMemo(() => {
    const needle = query.trim().toLowerCase();

    return items
      .filter((item) => {
        if (!needle) return true;

        return [
          item.localized_title,
          item.original_title,
          item.slug,
          item.movie_id ?? "",
          item.release_year ? String(item.release_year) : "",
        ]
          .join(" ")
          .toLowerCase()
          .includes(needle);
      })
      .slice(0, 80);
  }, [items, query]);

  function toggleContent(contentId: number) {
    if (selectedIds.includes(contentId)) {
      onChange(selectedIds.filter((id) => id !== contentId));
      return;
    }

    onChange([...selectedIds, contentId]);
  }

  return (
    <div className="relative space-y-3">
      <Button
        type="button"
        variant="outline"
        className="h-auto min-h-10 w-full justify-between whitespace-normal px-3 py-2 text-left"
        onClick={() => setIsOpen((value) => !value)}
      >
        <span className="text-sm text-muted-foreground">
          {selectedIds.length > 0 ? `${selectedIds.length} filme selectate` : "Alege filme din catalog"}
        </span>
        <ChevronsUpDownIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
      </Button>

      {isOpen ? (
        <div className="absolute right-0 top-11 z-30 w-full overflow-hidden rounded-xl border bg-popover shadow-xl">
          <div className="flex items-center gap-2 border-b px-3 py-2">
            <SearchIcon className="h-4 w-4 text-muted-foreground" />
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Caută după titlu, slug sau an..."
              className="h-8 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
              autoFocus
            />
          </div>

          <div className="admin-scrollbar max-h-80 overflow-y-auto p-1">
            {filteredItems.map((item) => {
              const checked = selectedIds.includes(item.id);

              return (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => toggleContent(item.id)}
                  className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm hover:bg-muted"
                >
                  <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? "border-primary bg-primary text-primary-foreground" : "border-input"}`}>
                    {checked ? <CheckIcon className="h-3 w-3" /> : null}
                  </span>
                  <div className="h-12 w-9 shrink-0 overflow-hidden rounded-md border bg-muted">
                    {item.poster_url ? (
                      <img src={item.poster_url} alt={item.localized_title} className="h-full w-full object-cover" />
                    ) : null}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{item.localized_title}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {item.type} • {item.status} • {item.release_year ?? "fără an"} • /{item.slug}
                    </p>
                  </div>
                </button>
              );
            })}

            {filteredItems.length === 0 ? (
              <p className="px-3 py-8 text-center text-sm text-muted-foreground">Nu am găsit filme pentru căutarea asta.</p>
            ) : null}
          </div>
        </div>
      ) : null}

      {selectedItems.length > 0 ? (
        <div className="flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-lg border bg-muted/30 p-3">
          {selectedItems.map((item) => (
            <span key={item.id} className="inline-flex max-w-full items-center gap-2 rounded-full border bg-background px-2.5 py-1 text-xs">
              <span className="truncate">{item.localized_title}</span>
              <button
                type="button"
                className="text-muted-foreground hover:text-foreground"
                onClick={() => onChange(selectedIds.filter((id) => id !== item.id))}
                aria-label={`Elimină ${item.localized_title}`}
              >
                <XIcon className="h-3.5 w-3.5" />
              </button>
            </span>
          ))}
        </div>
      ) : (
        <p className="rounded-lg border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
          Niciun film alocat încă.
        </p>
      )}

      <p className="text-xs text-muted-foreground">
        Poți căuta în tot catalogul și selecta mai multe titluri pentru acest creator.
      </p>
    </div>
  );
}
