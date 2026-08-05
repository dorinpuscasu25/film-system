import { useEffect, useMemo, useState } from "react";
import { BadgeCheckIcon, EditIcon, MailIcon, PlusIcon, WalletIcon } from "lucide-react";
import { DataTable } from "../components/shared/DataTable";
import { Badge } from "../components/shared/Badge";
import { Modal } from "../components/shared/Modal";
import { FormField } from "../components/shared/FormField";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import { AdminInvitation, AdminRole, AdminUser, AdminUserContentOption } from "../types";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";

interface EditFormState {
  id: number | null;
  name: string;
  email: string;
  status: "active" | "suspended";
  preferred_locale: "en" | "ro" | "ru";
  role_ids: number[];
  assigned_content_ids: number[];
}

interface InviteFormState {
  name: string;
  email: string;
  role_ids: number[];
  assigned_content_ids: number[];
  expires_in_hours: number;
}

interface WalletFormState {
  userId: number;
  userName: string;
  userEmail: string;
  currentBalance: number;
  currency: string;
  operation: "add" | "set";
  amount: string;
  reason: string;
}

function formatDate(value: string | null) {
  if (!value) return "N/A";
  return new Date(value).toLocaleString();
}

function userStatusLabel(status: AdminUser["status"]) {
  if (status === "pending_verification") return "Așteaptă confirmarea";
  return status === "active" ? "Activ" : "Suspendat";
}

function formatMoney(amount: number, currency = "MDL") {
  return new Intl.NumberFormat("ro-MD", {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
  }).format(amount);
}

export function Users() {
  const { can } = useAdmin();
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [invitations, setInvitations] = useState<AdminInvitation[]>([]);
  const [roles, setRoles] = useState<AdminRole[]>([]);
  const [contentOptions, setContentOptions] = useState<AdminUserContentOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [inviteResultUrl, setInviteResultUrl] = useState<string | null>(null);
  const [isInviteModalOpen, setIsInviteModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [walletState, setWalletState] = useState<WalletFormState | null>(null);
  const [inviteState, setInviteState] = useState<InviteFormState>({
    name: "",
    email: "",
    role_ids: [],
    assigned_content_ids: [],
    expires_in_hours: 72,
  });
  const [editState, setEditState] = useState<EditFormState>({
    id: null,
    name: "",
    email: "",
    status: "active",
    preferred_locale: "ro",
    role_ids: [],
    assigned_content_ids: [],
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [verifyingUserId, setVerifyingUserId] = useState<number | null>(null);

  async function loadData() {
    setIsLoading(true);
    setError(null);

    try {
      const [usersResponse, rolesResponse] = await Promise.all([
        adminApi.getUsers(),
        adminApi.getRoles(),
      ]);
      setUsers(usersResponse.users);
      setInvitations(usersResponse.invitations);
      setContentOptions(usersResponse.content_options);
      setRoles(rolesResponse.roles);
      setInviteState((current) => ({
        ...current,
        role_ids:
          current.role_ids.length > 0
            ? current.role_ids
            : rolesResponse.roles.slice(0, 1).map((role) => role.id),
      }));
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca utilizatorii.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  const roleOptions = useMemo(
    () => roles.map((role) => ({ label: role.name, value: role.id })),
    [roles],
  );

  const userColumns = [
    {
      key: "name",
      header: "Utilizator",
      render: (user: AdminUser) => (
        <div className="flex items-center gap-3">
          <div className="flex h-9 w-9 items-center justify-center rounded-md border bg-muted text-sm font-medium">
            {user.name.charAt(0).toUpperCase()}
          </div>
          <div>
            <div className="font-medium">{user.name}</div>
            <div className="text-xs text-muted-foreground">{user.email}</div>
            <div className={`mt-1 text-xs ${user.email_verified_at ? "text-emerald-700" : "text-amber-700"}`}>
              {user.email_verified_at ? "Email confirmat" : "Email neconfirmat"}
            </div>
          </div>
        </div>
      ),
    },
    {
      key: "roles",
      header: "Roluri",
      render: (user: AdminUser) => (
        <div className="flex flex-wrap gap-2">
          {user.roles.map((role) => (
            <Badge key={role.id} variant="draft">
              {role.name}
            </Badge>
          ))}
        </div>
      ),
    },
    {
      key: "scope",
      header: "Filme atribuite",
      render: (user: AdminUser) => (
        <div className="flex max-w-md flex-wrap gap-2">
          {user.assigned_contents.length > 0 ? (
            user.assigned_contents.slice(0, 4).map((content) => (
              <Badge key={`${user.id}-${content.id}`} variant="ready">
                {content.title ?? content.slug ?? `#${content.id}`}
              </Badge>
            ))
          ) : (
            <span className="text-xs text-muted-foreground">
              {user.content_scope_assigned ? "Niciun film atribuit" : "Toate filmele"}
            </span>
          )}
          {user.assigned_contents.length > 4 ? (
            <Badge variant="draft">+{user.assigned_contents.length - 4}</Badge>
          ) : null}
        </div>
      ),
    },
    {
      key: "status",
      header: "Stare",
      render: (user: AdminUser) => (
        <Badge variant={user.status === "active" ? "published" : user.status === "pending_verification" ? "ready" : "archived"}>
          {userStatusLabel(user.status)}
        </Badge>
      ),
    },
    {
      key: "wallet",
      header: "Sold cont",
      render: (user: AdminUser) => (
        <div>
          <div className="font-semibold tabular-nums">
            {formatMoney(user.wallet?.balance_amount ?? 0, user.wallet?.currency ?? "MDL")}
          </div>
          {!user.wallet ? <div className="text-xs text-muted-foreground">Portofel neinițializat</div> : null}
        </div>
      ),
    },
    {
      key: "last_seen_at",
      header: "Ultima activitate",
      render: (user: AdminUser) => formatDate(user.last_seen_at),
    },
    {
      key: "actions",
      header: "",
      render: (user: AdminUser) =>
        can("users.edit") ? (
          <div className="flex items-center justify-end gap-1">
            {!user.email_verified_at ? (
              <Button
                variant="outline"
                size="sm"
                disabled={verifyingUserId === user.id}
                onClick={(event) => {
                  event.stopPropagation();
                  void handleVerifyEmail(user);
                }}
                title="Confirmă manual adresa de email"
              >
                <BadgeCheckIcon className="h-4 w-4" />
                {verifyingUserId === user.id ? "Se confirmă..." : "Confirmă emailul"}
              </Button>
            ) : null}
            <Button
              variant="outline"
              size="sm"
              onClick={(event) => {
                event.stopPropagation();
                setSuccessMessage(null);
                setInviteResultUrl(null);
                setWalletState({
                  userId: user.id,
                  userName: user.name,
                  userEmail: user.email,
                  currentBalance: user.wallet?.balance_amount ?? 0,
                  currency: user.wallet?.currency ?? "MDL",
                  operation: "add",
                  amount: "",
                  reason: "",
                });
              }}
              title="Modifică soldul"
            >
              <WalletIcon className="h-4 w-4" />
              Sold
            </Button>
            {user.status !== "pending_verification" ? (
              <Button
                variant="ghost"
                size="icon"
                onClick={(event) => {
                  event.stopPropagation();
                  setSuccessMessage(null);
                  setInviteResultUrl(null);
                  setEditState({
                    id: user.id,
                    name: user.name,
                    email: user.email,
                    status: user.status,
                    preferred_locale: user.preferred_locale,
                    role_ids: user.roles.map((role) => role.id),
                    assigned_content_ids: user.assigned_content_ids,
                  });
                  setIsEditModalOpen(true);
                }}
                title="Editează utilizatorul"
              >
                <EditIcon className="h-4 w-4" />
              </Button>
            ) : null}
          </div>
        ) : null,
    },
  ];

  async function handleInviteSubmit() {
    setIsSubmitting(true);
    setError(null);
    setSuccessMessage(null);
    setInviteResultUrl(null);

    try {
      const response = await adminApi.inviteUser(inviteState);
      setSuccessMessage("Invitația a fost trimisă.");
      setInviteResultUrl(response.accept_url);
      setIsInviteModalOpen(false);
      setInviteState({
        name: "",
        email: "",
        role_ids: roles.slice(0, 1).map((role) => role.id),
        assigned_content_ids: [],
        expires_in_hours: 72,
      });
      await loadData();
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : "Nu am putut trimite invitația.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleUserSave() {
    if (editState.id === null) return;

    setIsSubmitting(true);
    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.updateUser(editState.id, {
        name: editState.name,
        email: editState.email,
        status: editState.status,
        role_ids: editState.role_ids,
        assigned_content_ids: editState.assigned_content_ids,
        preferred_locale: editState.preferred_locale,
      });
      setSuccessMessage("Utilizatorul a fost actualizat.");
      setIsEditModalOpen(false);
      await loadData();
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : "Nu am putut actualiza utilizatorul.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleVerifyEmail(user: AdminUser) {
    const confirmed = window.confirm(
      `Confirmi manual adresa ${user.email}? Utilizatorul nu va mai avea nevoie de codul primit pe email.`,
    );
    if (!confirmed) return;

    setVerifyingUserId(user.id);
    setError(null);
    setSuccessMessage(null);
    setInviteResultUrl(null);

    try {
      const response = await adminApi.verifyUserEmail(user.id);
      setUsers((current) => current.map((item) => (item.id === response.user.id ? response.user : item)));
      setSuccessMessage(
        response.already_verified
          ? `Emailul utilizatorului ${user.name} era deja confirmat.`
          : `Emailul utilizatorului ${user.name} a fost confirmat, iar contul poate fi folosit.`,
      );
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : "Nu am putut confirma emailul utilizatorului.");
    } finally {
      setVerifyingUserId(null);
    }
  }

  async function handleWalletSave() {
    if (!walletState) return;

    const amount = Number(walletState.amount);
    if (!Number.isFinite(amount) || amount < 0 || (walletState.operation === "add" && amount <= 0)) {
      setError("Introdu o sumă validă.");
      return;
    }

    setIsSubmitting(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const response = await adminApi.adjustUserWallet(walletState.userId, {
        operation: walletState.operation,
        amount,
        reason: walletState.reason.trim() || undefined,
      });
      setSuccessMessage(
        `Soldul utilizatorului ${walletState.userName} este acum ${formatMoney(response.wallet.balance_amount, response.wallet.currency)}.`,
      );
      setWalletState(null);
      await loadData();
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : "Nu am putut modifica soldul utilizatorului.");
    } finally {
      setIsSubmitting(false);
    }
  }

  function toggleSelection(values: number[], value: number) {
    return values.includes(value) ? values.filter((item) => item !== value) : [...values, value];
  }

  const walletAmount = Number(walletState?.amount ?? "");
  const walletAmountIsValid = walletState !== null
    && walletState.amount.trim() !== ""
    && Number.isFinite(walletAmount)
    && walletAmount >= 0
    && (walletState.operation === "add" || Math.round(walletAmount * 100) !== Math.round(walletState.currentBalance * 100))
    && (walletState.operation === "set" || walletAmount > 0);
  const walletPreview = walletState && walletAmountIsValid
    ? walletState.operation === "add"
      ? walletState.currentBalance + walletAmount
      : walletAmount
    : walletState?.currentBalance ?? 0;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="page-header">
          <h1 className="page-title">Utilizatori și acces</h1>
          <p className="page-description">
            Gestionează utilizatori activi, invitații și rolurile pe care le primesc.
          </p>
        </div>

        {can("users.invite") ? (
          <Button onClick={() => setIsInviteModalOpen(true)}>
            <PlusIcon className="mr-2 h-4 w-4" />
            Invită utilizator
          </Button>
        ) : null}
      </div>

      {error ? (
        <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}

      {successMessage ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <p>{successMessage}</p>
          {inviteResultUrl ? (
            <a href={inviteResultUrl} target="_blank" rel="noreferrer" className="mt-1 inline-block font-medium underline">
              Deschide linkul de invitație
            </a>
          ) : null}
        </div>
      ) : null}

      {isLoading ? (
        <Card>
          <CardContent className="p-10 text-center text-muted-foreground">Se încarcă utilizatorii...</CardContent>
        </Card>
      ) : (
        <DataTable
          data={users}
          columns={userColumns}
          keyExtractor={(user) => String(user.id)}
          searchPlaceholder="Caută după nume, email sau rol..."
        />
      )}

      <Card>
        <CardHeader>
          <CardTitle>Invitații</CardTitle>
          <CardDescription>Invite-urile generate și starea lor curentă.</CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <div className="divide-y">
            {invitations.length === 0 ? (
              <div className="px-6 py-8 text-sm text-muted-foreground">Nu există invitații încă.</div>
            ) : (
              invitations.map((invitation) => (
                <div key={invitation.id} className="flex flex-col gap-3 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                  <div>
                    <div className="flex items-center gap-2 text-sm font-medium">
                      <MailIcon className="h-4 w-4 text-muted-foreground" />
                      {invitation.email}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Roluri: {invitation.role_names.join(", ") || "Fără roluri"}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                    <Badge variant={invitation.status === "accepted" ? "published" : "ready"}>
                      {invitation.status === "accepted" ? "Acceptată" : "În așteptare"}
                    </Badge>
                    <span>Creată: {formatDate(invitation.created_at)}</span>
                    <span>Expiră: {formatDate(invitation.expires_at)}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </CardContent>
      </Card>

      <Modal
        isOpen={walletState !== null}
        onClose={() => {
          if (!isSubmitting) setWalletState(null);
        }}
        title="Modifică soldul utilizatorului"
        footer={
          <>
            <Button className="w-full sm:w-auto" variant="outline" onClick={() => setWalletState(null)} disabled={isSubmitting}>
              Anulează
            </Button>
            <Button
              className="w-full sm:w-auto"
              onClick={() => void handleWalletSave()}
              disabled={isSubmitting || !walletAmountIsValid || walletPreview > 1000000}
            >
              {isSubmitting ? "Se salvează..." : walletState?.operation === "add" ? "Adaugă bani" : "Setează soldul"}
            </Button>
          </>
        }
      >
        {walletState ? (
          <div className="space-y-5">
            <div className="rounded-lg border bg-muted/30 p-4">
              <p className="break-words font-medium">{walletState.userName}</p>
              <p className="break-all text-sm text-muted-foreground">{walletState.userEmail}</p>
              <div className="mt-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <span className="text-sm text-muted-foreground">Sold curent</span>
                <span className="text-2xl font-bold tabular-nums">
                  {formatMoney(walletState.currentBalance, walletState.currency)}
                </span>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <button
                type="button"
                aria-pressed={walletState.operation === "add"}
                className={`rounded-lg border p-4 text-left transition ${
                  walletState.operation === "add" ? "border-primary bg-primary/5" : "hover:bg-muted/40"
                }`}
                onClick={() => setWalletState((current) => current ? { ...current, operation: "add", amount: "" } : current)}
              >
                <span className="block font-medium">Adaugă la sold</span>
                <span className="mt-1 block text-xs text-muted-foreground">Suma introdusă se adaugă peste soldul actual.</span>
              </button>
              <button
                type="button"
                aria-pressed={walletState.operation === "set"}
                className={`rounded-lg border p-4 text-left transition ${
                  walletState.operation === "set" ? "border-primary bg-primary/5" : "hover:bg-muted/40"
                }`}
                onClick={() => setWalletState((current) => current ? { ...current, operation: "set", amount: String(current.currentBalance) } : current)}
              >
                <span className="block font-medium">Setează soldul final</span>
                <span className="mt-1 block text-xs text-muted-foreground">Înlocuiește soldul actual cu suma introdusă.</span>
              </button>
            </div>

            <FormField
              label={walletState.operation === "add" ? "Suma de adăugat (MDL)" : "Soldul final (MDL)"}
              type="number"
              min={walletState.operation === "add" ? "0.01" : "0"}
              max="1000000"
              step="0.01"
              inputMode="decimal"
              value={walletState.amount}
              onChange={(event) => setWalletState((current) => current ? { ...current, amount: event.target.value } : current)}
              helperText="Sunt acceptate maximum două zecimale."
            />

            <FormField
              label="Motiv / notă internă"
              type="textarea"
              rows={3}
              maxLength={500}
              value={walletState.reason}
              onChange={(event) => setWalletState((current) => current ? { ...current, reason: event.target.value } : current)}
              placeholder="Ex.: bonus promoțional, corectarea unei plăți..."
            />

            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <span>Sold după modificare</span>
                <strong className="text-lg tabular-nums">{formatMoney(walletPreview, walletState.currency)}</strong>
              </div>
              <p className="mt-2 text-xs text-amber-800">Modificarea va fi salvată în istoricul tranzacțiilor și în jurnalul de audit.</p>
            </div>
          </div>
        ) : null}
      </Modal>

      <Modal
        isOpen={isInviteModalOpen}
        onClose={() => setIsInviteModalOpen(false)}
        title="Invită un utilizator nou"
        footer={
          <>
            <Button variant="outline" onClick={() => setIsInviteModalOpen(false)}>
              Anulează
            </Button>
            <Button onClick={() => void handleInviteSubmit()} disabled={isSubmitting || inviteState.role_ids.length === 0}>
              {isSubmitting ? "Se trimite..." : "Trimite invitația"}
            </Button>
          </>
        }
      >
        <div className="form-grid">
          <FormField
            label="Nume"
            value={inviteState.name}
            onChange={(event) => setInviteState((current) => ({ ...current, name: event.target.value }))}
          />
          <FormField
            label="Email"
            type="email"
            value={inviteState.email}
            onChange={(event) => setInviteState((current) => ({ ...current, email: event.target.value }))}
          />
          <FormField
            label="Invitația expiră în (ore)"
            type="number"
            value={inviteState.expires_in_hours}
            onChange={(event) =>
              setInviteState((current) => ({
                ...current,
                expires_in_hours: Number(event.target.value),
              }))
            }
          />

          <div className="space-y-2">
            <p className="text-sm font-medium">Roluri</p>
            <div className="space-y-2 rounded-md border p-4">
              {roleOptions.map((role) => (
                <label key={role.value} className="flex items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={inviteState.role_ids.includes(role.value)}
                    onChange={() =>
                      setInviteState((current) => ({
                        ...current,
                        role_ids: toggleSelection(current.role_ids, role.value),
                      }))
                    }
                  />
                  <span>{role.label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="space-y-2">
            <p className="text-sm font-medium">Filme atribuite</p>
            <div className="admin-scrollbar max-h-64 space-y-2 overflow-y-auto rounded-md border p-4">
              {contentOptions.map((content) => (
                <label key={content.id} className="flex items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={inviteState.assigned_content_ids.includes(content.id)}
                    onChange={() =>
                      setInviteState((current) => ({
                        ...current,
                        assigned_content_ids: toggleSelection(current.assigned_content_ids, content.id),
                      }))
                    }
                  />
                  <span>{content.title}</span>
                  <span className="text-xs text-muted-foreground">/{content.slug}</span>
                </label>
              ))}
              {contentOptions.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nu există filme disponibile pentru asignare.</p>
              ) : null}
            </div>
            <p className="text-xs text-muted-foreground">
              Dacă rolul are permisiunea `content.scope_assigned`, utilizatorul va vedea doar filmele bifate aici.
            </p>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={isEditModalOpen}
        onClose={() => setIsEditModalOpen(false)}
        title="Editează utilizatorul"
        footer={
          <>
            <Button variant="outline" onClick={() => setIsEditModalOpen(false)}>
              Anulează
            </Button>
            <Button onClick={() => void handleUserSave()} disabled={isSubmitting || editState.role_ids.length === 0}>
              {isSubmitting ? "Se salvează..." : "Salvează utilizatorul"}
            </Button>
          </>
        }
      >
        <div className="form-grid">
          <FormField
            label="Nume"
            value={editState.name}
            onChange={(event) => setEditState((current) => ({ ...current, name: event.target.value }))}
          />
          <FormField
            label="Email"
            type="email"
            value={editState.email}
            onChange={(event) => setEditState((current) => ({ ...current, email: event.target.value }))}
          />
          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              label="Stare"
              type="select"
              value={editState.status}
              onChange={(event) =>
                setEditState((current) => ({
                  ...current,
                  status: event.target.value as "active" | "suspended",
                }))
              }
              options={[
                { label: "Activ", value: "active" },
                { label: "Suspendat", value: "suspended" },
              ]}
            />
            <FormField
              label="Limbă"
              type="select"
              value={editState.preferred_locale}
              onChange={(event) =>
                setEditState((current) => ({
                  ...current,
                  preferred_locale: event.target.value as "en" | "ro" | "ru",
                }))
              }
              options={[
                { label: "Română", value: "ro" },
                { label: "Engleză", value: "en" },
                { label: "Русский", value: "ru" },
              ]}
            />
          </div>

          <div className="space-y-2">
            <p className="text-sm font-medium">Roluri</p>
            <div className="space-y-2 rounded-md border p-4">
              {roleOptions.map((role) => (
                <label key={role.value} className="flex items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={editState.role_ids.includes(role.value)}
                    onChange={() =>
                      setEditState((current) => ({
                        ...current,
                        role_ids: toggleSelection(current.role_ids, role.value),
                      }))
                    }
                  />
                  <span>{role.label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="space-y-2">
            <p className="text-sm font-medium">Filme atribuite</p>
            <div className="admin-scrollbar max-h-64 space-y-2 overflow-y-auto rounded-md border p-4">
              {contentOptions.map((content) => (
                <label key={content.id} className="flex items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={editState.assigned_content_ids.includes(content.id)}
                    onChange={() =>
                      setEditState((current) => ({
                        ...current,
                        assigned_content_ids: toggleSelection(current.assigned_content_ids, content.id),
                      }))
                    }
                  />
                  <span>{content.title}</span>
                  <span className="text-xs text-muted-foreground">/{content.slug}</span>
                </label>
              ))}
              {contentOptions.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nu există filme disponibile pentru asignare.</p>
              ) : null}
            </div>
            <p className="text-xs text-muted-foreground">
              Pentru rolul Producer, utilizatorul va vedea numai filmele bifate.
            </p>
          </div>
        </div>
      </Modal>
    </div>
  );
}
