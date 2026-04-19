import React, { useEffect, useMemo, useState } from "react";
import { EditIcon, MailIcon, PlusIcon } from "lucide-react";
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
  expires_in_hours: number;
}

function formatDate(value: string | null) {
  if (!value) return "N/A";
  return new Date(value).toLocaleString();
}

function userStatusLabel(status: "active" | "suspended") {
  return status === "active" ? "Activ" : "Suspendat";
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
  const [inviteState, setInviteState] = useState<InviteFormState>({
    name: "",
    email: "",
    role_ids: [],
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
            <span className="text-xs text-muted-foreground">Toate filmele</span>
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
        <Badge variant={user.status === "active" ? "published" : "archived"}>{userStatusLabel(user.status)}</Badge>
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

  function toggleSelection(values: number[], value: number) {
    return values.includes(value) ? values.filter((item) => item !== value) : [...values, value];
  }

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
        </div>
      </Modal>
    </div>
  );
}
