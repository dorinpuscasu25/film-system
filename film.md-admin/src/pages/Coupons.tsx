import { useEffect, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { PencilIcon, PlusIcon, TicketIcon, Trash2Icon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { Modal } from "../components/shared/Modal";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Switch } from "../components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";

interface Coupon {
  id: number;
  code: string;
  name: string;
  description: string | null;
  discount_type: "percent" | "fixed" | "free_access";
  discount_value: number;
  currency: string;
  max_redemptions: number | null;
  redemptions_count: number;
  per_user_limit: number;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  is_currently_valid: boolean;
}

const defaultForm = {
  code: "",
  name: "",
  description: "",
  discount_type: "percent" as "percent" | "fixed" | "free_access",
  discount_value: 10,
  currency: "MDL",
  max_redemptions: "" as string | number,
  per_user_limit: 1,
  starts_at: "",
  ends_at: "",
  is_active: true,
};

export function Coupons() {
  const { t } = useTranslation();
  const [items, setItems] = useState<Coupon[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Coupon | null>(null);
  const [form, setForm] = useState(defaultForm);

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getCoupons();
      setItems(res.items);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function startCreate() {
    setEditing(null);
    setForm(defaultForm);
    setShowForm(true);
  }

  function startEdit(coupon: Coupon) {
    setEditing(coupon);
    setForm({
      code: coupon.code,
      name: coupon.name,
      description: coupon.description ?? "",
      discount_type: coupon.discount_type,
      discount_value: coupon.discount_value,
      currency: coupon.currency,
      max_redemptions: coupon.max_redemptions ?? "",
      per_user_limit: coupon.per_user_limit,
      starts_at: coupon.starts_at?.slice(0, 16) ?? "",
      ends_at: coupon.ends_at?.slice(0, 16) ?? "",
      is_active: coupon.is_active,
    });
    setShowForm(true);
  }

  async function submit() {
    const payload: Record<string, unknown> = {
      code: form.code.toUpperCase(),
      name: form.name,
      description: form.description || null,
      discount_type: form.discount_type,
      discount_value: Number(form.discount_value),
      currency: form.currency,
      max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
      per_user_limit: Number(form.per_user_limit),
      starts_at: form.starts_at || null,
      ends_at: form.ends_at || null,
      is_active: form.is_active,
    };

    if (editing) {
      await adminApi.updateCoupon(editing.id, payload);
    } else {
      await adminApi.createCoupon(payload);
    }

    setShowForm(false);
    await load();
  }

  async function remove(id: number) {
    if (!confirm("Confirmă ștergerea?")) {
      return;
    }
    await adminApi.deleteCoupon(id);
    await load();
  }

  const activeCoupons = items.filter((coupon) => coupon.is_currently_valid).length;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">{t("coupons.title")}</h1>
          <p className="page-description">
            Gestionează codurile promoționale, limitele de folosire și perioada de valabilitate.
          </p>
        </div>

        <Button onClick={startCreate}>
          <PlusIcon className="h-4 w-4" />
          {t("coupons.create")}
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <SummaryCard title="Total cupoane" value={items.length} description="Toate codurile create" />
        <SummaryCard title="Active acum" value={activeCoupons} description="Coduri valide în perioada curentă" />
      </div>

      <Card>
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <CardTitle>Listă cupoane</CardTitle>
            <CardDescription>Editează rapid statusul, limitele și valoarea reducerii.</CardDescription>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {loading ? (
            <div className="p-10 text-center text-sm text-muted-foreground">{t("common.loading")}…</div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("coupons.form.code")}</TableHead>
                  <TableHead>{t("coupons.form.name")}</TableHead>
                  <TableHead>{t("coupons.form.discount_type")}</TableHead>
                  <TableHead className="text-right">{t("coupons.form.discount_value")}</TableHead>
                  <TableHead className="text-right">Folosiri</TableHead>
                  <TableHead>{t("common.status")}</TableHead>
                  <TableHead className="text-right">{t("common.actions")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                      Nu există cupoane create.
                    </TableCell>
                  </TableRow>
                ) : (
                  items.map((coupon) => (
                    <TableRow key={coupon.id}>
                      <TableCell>
                        <span className="font-mono text-sm font-medium">{coupon.code}</span>
                      </TableCell>
                      <TableCell>
                        <div className="font-medium">{coupon.name}</div>
                        {coupon.description ? (
                          <div className="mt-1 max-w-xs truncate text-xs text-muted-foreground">{coupon.description}</div>
                        ) : null}
                      </TableCell>
                      <TableCell>{t(`coupons.form.discount_types.${coupon.discount_type}`)}</TableCell>
                      <TableCell className="text-right">{formatDiscount(coupon)}</TableCell>
                      <TableCell className="text-right">
                        {coupon.redemptions_count}
                        {coupon.max_redemptions ? ` / ${coupon.max_redemptions}` : ""}
                      </TableCell>
                      <TableCell>
                        <Badge variant={coupon.is_currently_valid ? "active" : "inactive"}>
                          {coupon.is_currently_valid ? t("common.active") : t("common.inactive")}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex justify-end gap-2">
                          <Button variant="outline" size="icon" onClick={() => startEdit(coupon)} title={t("common.edit")}>
                            <PencilIcon className="h-4 w-4" />
                          </Button>
                          <Button variant="outline" size="icon" onClick={() => void remove(coupon.id)} title={t("common.delete")}>
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
        title={editing ? t("common.edit") : t("coupons.create")}
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
          <Field label={t("coupons.form.code")}>
            <Input
              value={form.code}
              onChange={(event) => setForm({ ...form, code: event.target.value.toUpperCase() })}
            />
          </Field>
          <Field label={t("coupons.form.name")}>
            <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label={t("coupons.form.discount_type")}>
            <select
              className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              value={form.discount_type}
              onChange={(event) => setForm({ ...form, discount_type: event.target.value as typeof form.discount_type })}
            >
              <option value="percent">{t("coupons.form.discount_types.percent")}</option>
              <option value="fixed">{t("coupons.form.discount_types.fixed")}</option>
              <option value="free_access">{t("coupons.form.discount_types.free_access")}</option>
            </select>
          </Field>
          <Field label={t("coupons.form.discount_value")}>
            <Input
              type="number"
              value={form.discount_value}
              onChange={(event) => setForm({ ...form, discount_value: Number(event.target.value) })}
            />
          </Field>
          <Field label={t("coupons.form.max_redemptions")}>
            <Input
              type="number"
              value={form.max_redemptions}
              onChange={(event) => setForm({ ...form, max_redemptions: event.target.value })}
            />
          </Field>
          <Field label={t("coupons.form.per_user_limit")}>
            <Input
              type="number"
              value={form.per_user_limit}
              onChange={(event) => setForm({ ...form, per_user_limit: Number(event.target.value) })}
            />
          </Field>
          <Field label={t("coupons.form.starts_at")}>
            <Input
              type="datetime-local"
              value={form.starts_at}
              onChange={(event) => setForm({ ...form, starts_at: event.target.value })}
            />
          </Field>
          <Field label={t("coupons.form.ends_at")}>
            <Input
              type="datetime-local"
              value={form.ends_at}
              onChange={(event) => setForm({ ...form, ends_at: event.target.value })}
            />
          </Field>
          <Field label={t("coupons.form.description")} span={2}>
            <textarea
              className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              value={form.description}
              onChange={(event) => setForm({ ...form, description: event.target.value })}
            />
          </Field>
          <div className="flex items-center justify-between rounded-lg border bg-muted/40 px-4 py-3 md:col-span-2">
            <div>
              <div className="text-sm font-medium">{t("common.active")}</div>
              <div className="text-xs text-muted-foreground">Couponul poate fi folosit dacă este în perioada validă.</div>
            </div>
            <Switch checked={form.is_active} onCheckedChange={(checked) => setForm({ ...form, is_active: checked })} />
          </div>
        </div>
      </Modal>
    </div>
  );
}

function SummaryCard({ title, value, description }: { title: string; value: number; description: string }) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div className="space-y-1">
          <CardDescription>{title}</CardDescription>
          <CardTitle>{value.toLocaleString()}</CardTitle>
        </div>
        <div className="rounded-md border bg-muted p-2">
          <TicketIcon className="h-4 w-4" />
        </div>
      </CardHeader>
      <CardContent className="pt-0">
        <p className="text-sm text-muted-foreground">{description}</p>
      </CardContent>
    </Card>
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

function formatDiscount(coupon: Coupon) {
  if (coupon.discount_type === "percent") {
    return `${coupon.discount_value}%`;
  }

  if (coupon.discount_type === "fixed") {
    return `${coupon.discount_value} ${coupon.currency}`;
  }

  return "Acces gratuit";
}
