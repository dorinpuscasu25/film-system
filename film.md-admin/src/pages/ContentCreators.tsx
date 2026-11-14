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
import { adminApi, type ApiRequestError } from "../lib/api";
import type { AdminContent, AdminUser, RightsHolder } from "../types";

type HolderForm = {
  user_id: string | number;
  name: string;
  email: string;
  company_name: string;
  person_type: "PF" | "PJ";
  idno: string;
  legal_address: string;
  iban: string;
  bank_name: string;
  bank_swift: string;
  is_vat_payer: boolean;
  vat_code: string;
  contact_person: string;
  phone: string;
  platform_fee_percent: number;
  is_active: boolean;
  content_ids: number[];
};

const EMPTY_FORM: HolderForm = {
  user_id: "",
  name: "",
  email: "",
  company_name: "",
  person_type: "PJ",
  idno: "",
  legal_address: "",
  iban: "",
  bank_name: "",
  bank_swift: "",
  is_vat_payer: false,
  vat_code: "",
  contact_person: "",
  phone: "",
  platform_fee_percent: 70,
  is_active: true,
  content_ids: [],
};

/**
 * Titulari: the rights holder of each title (one per title), with the legal and payment
 * details that royalty reports use. VAT status, IDNO and IBAN saved here become the holder's
 * fiscal profile in Raportare, versioned from the day of the change.
 */
export function ContentCreators() {
  const { t } = useTranslation();
  const [items, setItems] = useState<RightsHolder[]>([]);
  const [contentOptions, setContentOptions] = useState<AdminContent[]>([]);
  const [userOptions, setUserOptions] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<RightsHolder | null>(null);
  const [statementsFor, setStatementsFor] = useState<RightsHolder | null>(null);
  const [statements, setStatements] = useState<Awaited<ReturnType<typeof adminApi.getCreatorStatements>>["items"]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [form, setForm] = useState<HolderForm>(EMPTY_FORM);

  async function load() {
    setLoading(true);
    try {
      const [creatorsResponse, contentResponse, usersResponse] = await Promise.all([
        adminApi.getContentCreators(),
        adminApi.getContentIndex(),
        adminApi.getUsers(),
      ]);
      setItems(creatorsResponse.items);
      setContentOptions(contentResponse.items);
      setUserOptions(usersResponse.users);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function openForm(next: HolderForm, holder: RightsHolder | null) {
    setEditing(holder);
    setForm(next);
    setErrors({});
    setFormError(null);
    setShowForm(true);
  }

  function startCreate() {
    openForm(EMPTY_FORM, null);
  }

  function startEdit(holder: RightsHolder) {
    openForm(
      {
        user_id: holder.user?.id ?? "",
        name: holder.name,
        email: holder.email ?? "",
        company_name: holder.company_name ?? "",
        person_type: holder.person_type ?? "PJ",
        idno: holder.idno ?? "",
        legal_address: holder.legal_address ?? "",
        iban: holder.iban ?? "",
        bank_name: holder.bank_name ?? "",
        bank_swift: holder.bank_swift ?? "",
        is_vat_payer: holder.is_vat_payer,
        vat_code: holder.vat_code ?? "",
        contact_person: holder.contact_person ?? "",
        phone: holder.phone ?? "",
        platform_fee_percent: holder.platform_fee_percent,
        is_active: holder.is_active,
        content_ids: holder.contents.map((content) => content.id),
      },
      holder,
    );
  }

  async function submit() {
    setIsSubmitting(true);
    setErrors({});
    setFormError(null);
    const payload: Record<string, unknown> = {
      user_id: form.user_id ? Number(form.user_id) : null,
      name: form.name,
      email: form.email || null,
      company_name: form.company_name || null,
      person_type: form.person_type,
      idno: form.idno || null,
      legal_address: form.legal_address || null,
      iban: form.iban || null,
      bank_name: form.bank_name || null,
      bank_swift: form.bank_swift || null,
      is_vat_payer: form.is_vat_payer,
      vat_code: form.is_vat_payer ? form.vat_code || null : null,
      contact_person: form.contact_person || null,
      phone: form.phone || null,
      platform_fee_percent: Number(form.platform_fee_percent),
      is_active: form.is_active,
      content_ids: form.content_ids,
    };

    try {
      if (editing) await adminApi.updateContentCreator(editing.id, payload);
      else await adminApi.createContentCreator(payload);
      setShowForm(false);
      await load();
    } catch (error) {
      const apiError = error as ApiRequestError;
      setErrors(apiError.errors ?? {});
      setFormError(apiError.message || "Titularul nu a putut fi salvat.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function remove(id: number) {
    if (!confirm("Confirmă ștergerea? Filmele lui vor rămâne fără titular.")) return;
    await adminApi.deleteContentCreator(id);
    await load();
  }

  async function viewStatements(holder: RightsHolder) {
    setStatementsFor(holder);
    const response = await adminApi.getCreatorStatements(holder.id);
    setStatements(response.items);
  }

  const availableUserOptions = useMemo(() => {
    const currentCreatorUserId = editing?.user?.id ?? null;
    const assignedUserIds = new Set(
      items
        .map((creator) => creator.user?.id)
        .filter((userId): userId is number => Boolean(userId) && userId !== currentCreatorUserId),
    );

    return userOptions.filter((user) => !assignedUserIds.has(user.id));
  }, [editing?.user?.id, items, userOptions]);

  /** Who holds each title now, so picking one held elsewhere says it will move. */
  const holderByContent = useMemo(() => {
    const map = new Map<number, { id: number; name: string }>();
    items.forEach((holder) => holder.contents.forEach((content) => map.set(content.id, { id: holder.id, name: holder.name })));
    return map;
  }, [items]);

  const set = <K extends keyof HolderForm>(key: K, value: HolderForm[K]) => setForm((current) => ({ ...current, [key]: value }));
  const fieldError = (key: string) => errors[key]?.[0];

  return (
    <div className="p-6">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t("creators.title")}</h1>
          <p className="text-sm text-muted-foreground">Fiecare film are un singur titular. Datele fiscale de aici se folosesc în rapoartele de drepturi.</p>
        </div>
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
                  <TableHead>Titular</TableHead>
                  <TableHead>{t("creators.form.idno")}</TableHead>
                  <TableHead>TVA</TableHead>
                  <TableHead>IBAN</TableHead>
                  <TableHead className="text-right">Filme</TableHead>
                  <TableHead>{t("common.status")}</TableHead>
                  <TableHead className="text-right">{t("common.actions")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((holder) => (
                  <TableRow key={holder.id}>
                    <TableCell>
                      <p className="font-medium">{holder.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {[holder.company_name, holder.person_type, holder.email].filter(Boolean).join(" • ") || "—"}
                      </p>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{holder.idno ?? "—"}</TableCell>
                    <TableCell>
                      <Badge variant={holder.is_vat_payer ? "active" : "inactive"}>
                        {holder.is_vat_payer ? "Plătitor TVA" : "Neplătitor"}
                      </Badge>
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">
                      {holder.iban ? `•••• ${holder.iban.slice(-4)}` : "—"}
                    </TableCell>
                    <TableCell className="text-right">{holder.content_count}</TableCell>
                    <TableCell>
                      <Badge variant={holder.is_active ? "active" : "inactive"}>
                        {holder.is_active ? t("common.active") : t("common.inactive")}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => viewStatements(holder)}>
                          {t("creators.statements")}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => startEdit(holder)}>
                          {t("common.edit")}
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-destructive hover:text-destructive"
                          onClick={() => remove(holder.id)}
                        >
                          {t("common.delete")}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
                {items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                      Niciun titular încă.
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <Modal
        isOpen={showForm}
        onClose={() => setShowForm(false)}
        title={editing ? `${t("common.edit")} — ${editing.name}` : t("creators.create")}
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
          <div className="space-y-6">
            {formError ? (
              <p className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
            ) : null}

            <Section title="Identificare">
              <Field label={t("creators.form.name")} error={fieldError("name")}>
                <Input value={form.name} onChange={(event) => set("name", event.target.value)} placeholder="Kantora Film" />
              </Field>
              <Field label={t("creators.form.company_name")} error={fieldError("company_name")}>
                <Input value={form.company_name} onChange={(event) => set("company_name", event.target.value)} placeholder="Kantora Film SRL" />
              </Field>
              <Field label={t("creators.form.person_type")} error={fieldError("person_type")}>
                <select
                  value={form.person_type}
                  onChange={(event) => set("person_type", event.target.value as HolderForm["person_type"])}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="PJ">{t("creators.form.person_pj")}</option>
                  <option value="PF">{t("creators.form.person_pf")}</option>
                </select>
              </Field>
              <Field
                label={form.person_type === "PF" ? "IDNP" : "IDNO"}
                error={fieldError("idno")}
                hint={form.person_type === "PF" ? "Codul personal (13 cifre)." : "Codul fiscal al companiei (13 cifre)."}
              >
                <Input value={form.idno} onChange={(event) => set("idno", event.target.value)} inputMode="numeric" className="font-mono" />
              </Field>
              <Field label={t("creators.form.legal_address")} error={fieldError("legal_address")} className="md:col-span-2">
                <Input value={form.legal_address} onChange={(event) => set("legal_address", event.target.value)} placeholder="str. Ștefan cel Mare 1, MD-2001, Chișinău" />
              </Field>
            </Section>

            <Section title="TVA">
              <label className="flex items-center justify-between gap-4 rounded-lg border p-4 md:col-span-2">
                <div className="space-y-1">
                  <span className="text-sm font-medium">{t("creators.form.is_vat_payer")}</span>
                  <p className="text-xs text-muted-foreground">
                    {form.is_vat_payer
                      ? "Titularul primește și componenta de TVA din cota sa."
                      : "Neplătitor: cota se calculează fără TVA."}
                  </p>
                </div>
                <Switch checked={form.is_vat_payer} onCheckedChange={(checked) => set("is_vat_payer", checked)} />
              </label>
              {form.is_vat_payer ? (
                <Field label={t("creators.form.vat_code")} error={fieldError("vat_code")}>
                  <Input value={form.vat_code} onChange={(event) => set("vat_code", event.target.value)} className="font-mono" />
                </Field>
              ) : null}
            </Section>

            <Section title="Date bancare">
              <Field label={t("creators.form.iban")} error={fieldError("iban")} className="md:col-span-2">
                <Input value={form.iban} onChange={(event) => set("iban", event.target.value)} placeholder="MD24 AG00 0225 1000 1310 4168" className="font-mono" />
              </Field>
              <Field label={t("creators.form.bank_name")} error={fieldError("bank_name")}>
                <Input value={form.bank_name} onChange={(event) => set("bank_name", event.target.value)} placeholder="Moldova Agroindbank" />
              </Field>
              <Field label={t("creators.form.bank_swift")} error={fieldError("bank_swift")}>
                <Input value={form.bank_swift} onChange={(event) => set("bank_swift", event.target.value)} placeholder="AGRNMD2X" className="font-mono uppercase" />
              </Field>
            </Section>

            <Section title="Contact și acces">
              <Field label={t("creators.form.contact_person")} error={fieldError("contact_person")}>
                <Input value={form.contact_person} onChange={(event) => set("contact_person", event.target.value)} />
              </Field>
              <Field label={t("creators.form.phone")} error={fieldError("phone")}>
                <Input type="tel" value={form.phone} onChange={(event) => set("phone", event.target.value)} placeholder="+373 ..." />
              </Field>
              <Field label={t("creators.form.email")} error={fieldError("email")}>
                <Input type="email" value={form.email} onChange={(event) => set("email", event.target.value)} />
              </Field>
              <Field label={t("creators.form.user")} error={fieldError("user_id")}>
                <UserSelect
                  users={availableUserOptions}
                  selectedId={form.user_id ? Number(form.user_id) : null}
                  onChange={(user) =>
                    setForm((current) => ({
                      ...current,
                      user_id: user?.id ?? "",
                      name: user && !current.name.trim() ? user.name : current.name,
                      email: user && !current.email.trim() ? user.email : current.email,
                    }))
                  }
                />
              </Field>
              <Field label={t("creators.form.platform_fee_percent")} error={fieldError("platform_fee_percent")}>
                <Input
                  type="number"
                  min={0}
                  max={100}
                  value={form.platform_fee_percent}
                  onChange={(event) => set("platform_fee_percent", Number(event.target.value))}
                />
              </Field>
              <label className="flex items-center justify-between gap-4 rounded-lg border p-4">
                <div className="space-y-1">
                  <span className="text-sm font-medium">{t("common.active")}</span>
                  <p className="text-xs text-muted-foreground">Titularul poate primi filme și rapoarte.</p>
                </div>
                <Switch checked={form.is_active} onCheckedChange={(checked) => set("is_active", checked)} />
              </label>
            </Section>
          </div>

          <Field label={t("creators.form.content_ids")}>
            <ContentMultiSelect
              items={contentOptions}
              selectedIds={form.content_ids}
              onChange={(content_ids) => set("content_ids", content_ids)}
              holderByContent={holderByContent}
              currentHolderId={editing?.id ?? null}
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

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-3">
      <h3 className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">{title}</h3>
      <div className="grid gap-4 md:grid-cols-2">{children}</div>
    </section>
  );
}

function Field({
  label,
  children,
  error,
  hint,
  className = "",
}: {
  label: string;
  children: ReactNode;
  error?: string;
  hint?: string;
  className?: string;
}) {
  return (
    <div className={`space-y-2 ${className}`}>
      <Label>{label}</Label>
      {children}
      {error ? <p className="text-xs text-destructive">{error}</p> : hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

function UserSelect({
  users,
  selectedId,
  onChange,
}: {
  users: AdminUser[];
  selectedId: number | null;
  onChange: (user: AdminUser | null) => void;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState("");
  const selectedUser = users.find((user) => user.id === selectedId) ?? null;
  const filteredUsers = useMemo(() => {
    const needle = query.trim().toLowerCase();

    return users.filter((user) => {
      if (!needle) return true;

      return [user.name, user.email, user.status, ...user.roles.map((role) => role.name)]
        .join(" ")
        .toLowerCase()
        .includes(needle);
    });
  }, [query, users]);

  return (
    <div className="relative">
      <Button
        type="button"
        variant="outline"
        className="h-10 w-full justify-between px-3 text-left"
        onClick={() => setIsOpen((value) => !value)}
      >
        <span className={selectedUser ? "truncate text-sm" : "truncate text-sm text-muted-foreground"}>
          {selectedUser ? `${selectedUser.name} • ${selectedUser.email}` : "Alege utilizator existent"}
        </span>
        <ChevronsUpDownIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
      </Button>

      {isOpen ? (
        <div className="absolute left-0 top-11 z-40 w-full min-w-[340px] overflow-hidden rounded-xl border bg-popover shadow-xl">
          <div className="flex items-center gap-2 border-b px-3 py-2">
            <SearchIcon className="h-4 w-4 text-muted-foreground" />
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Caută după nume, email sau rol..."
              className="h-8 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
              autoFocus
            />
          </div>

          {selectedId ? (
            <button
              type="button"
              onClick={() => {
                onChange(null);
                setIsOpen(false);
                setQuery("");
              }}
              className="flex w-full items-center px-3 py-2 text-left text-sm text-muted-foreground hover:bg-muted"
            >
              Fără cont login asociat
            </button>
          ) : null}

          <div className="admin-scrollbar max-h-72 overflow-y-auto p-1">
            {filteredUsers.map((user) => {
              const checked = user.id === selectedId;

              return (
                <button
                  key={user.id}
                  type="button"
                  onClick={() => {
                    onChange(user);
                    setIsOpen(false);
                    setQuery("");
                  }}
                  className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm hover:bg-muted"
                >
                  <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? "border-primary bg-primary text-primary-foreground" : "border-input"}`}>
                    {checked ? <CheckIcon className="h-3 w-3" /> : null}
                  </span>
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border bg-muted text-xs font-medium">
                    {user.name.charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{user.name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {user.email} • {user.status} • {user.roles.map((role) => role.name).join(", ") || "fără rol"}
                    </p>
                  </div>
                </button>
              );
            })}

            {filteredUsers.length === 0 ? (
              <p className="px-3 py-8 text-center text-sm text-muted-foreground">
                Nu am găsit utilizatori disponibili.
              </p>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  );
}

function ContentMultiSelect({
  items,
  selectedIds,
  onChange,
  holderByContent,
  currentHolderId,
}: {
  items: AdminContent[];
  selectedIds: number[];
  onChange: (ids: number[]) => void;
  holderByContent: Map<number, { id: number; name: string }>;
  currentHolderId: number | null;
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
                    {(() => {
                      const holder = holderByContent.get(item.id);
                      if (!holder || holder.id === currentHolderId) return null;
                      return (
                        <p className="truncate text-xs text-amber-600">
                          Titular acum: {holder.name}{checked ? " — va fi mutat aici" : ""}
                        </p>
                      );
                    })()}
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
        Un film are un singur titular: dacă alegi un film al altui titular, el trece la acesta. Titularul se poate schimba și din pagina filmului.
      </p>
    </div>
  );
}
