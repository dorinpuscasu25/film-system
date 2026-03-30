import React, { Fragment, useEffect, useMemo, useState } from "react";
import { CheckIcon, PlusIcon, ShieldIcon, XIcon } from "lucide-react";
import { Badge } from "../components/shared/Badge";
import { FormField } from "../components/shared/FormField";
import { Modal } from "../components/shared/Modal";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import { AdminPermission, AdminRole } from "../types";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";

interface RoleFormState {
  name: string;
  description: string;
  admin_panel_access: boolean;
  permission_ids: number[];
}

function toggleId(values: number[], value: number) {
  return values.includes(value) ? values.filter((item) => item !== value) : [...values, value];
}

export function RolesPermissions() {
  const { can, currentUser } = useAdmin();
  const [roles, setRoles] = useState<AdminRole[]>([]);
  const [permissions, setPermissions] = useState<AdminPermission[]>([]);
  const [selectedRoleId, setSelectedRoleId] = useState<number | null>(null);
  const [draftPermissionIds, setDraftPermissionIds] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [roleForm, setRoleForm] = useState<RoleFormState>({
    name: "",
    description: "",
    admin_panel_access: false,
    permission_ids: [],
  });

  async function loadData() {
    setIsLoading(true);
    setError(null);

    try {
      const response = await adminApi.getRoles();
      setRoles(response.roles);
      setPermissions(response.permissions);
      setSelectedRoleId((current) => current ?? response.roles[0]?.id ?? null);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca rolurile.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  const selectedRole = useMemo(
    () => roles.find((role) => role.id === selectedRoleId) ?? null,
    [roles, selectedRoleId],
  );

  useEffect(() => {
    if (selectedRole) {
      setDraftPermissionIds(selectedRole.permission_ids);
    }
  }, [selectedRole]);

  const groupedPermissions = useMemo(() => {
    return permissions.reduce<Record<string, AdminPermission[]>>((groups, permission) => {
      if (!groups[permission.group]) {
        groups[permission.group] = [];
      }
      groups[permission.group].push(permission);
      return groups;
    }, {});
  }, [permissions]);

  async function handleSaveRole() {
    if (!selectedRole) return;

    setIsSaving(true);
    setError(null);
    setMessage(null);

    try {
      await adminApi.updateRole(selectedRole.id, {
        name: selectedRole.name,
        description: selectedRole.description ?? "",
        admin_panel_access: selectedRole.admin_panel_access,
        permission_ids: draftPermissionIds,
      });
      setMessage("Rolul a fost actualizat.");
      await loadData();
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut salva rolul.");
    } finally {
      setIsSaving(false);
    }
  }

  async function handleCreateRole() {
    setIsSaving(true);
    setError(null);
    setMessage(null);

    try {
      const response = await adminApi.createRole(roleForm);
      setIsCreateModalOpen(false);
      setRoleForm({
        name: "",
        description: "",
        admin_panel_access: false,
        permission_ids: [],
      });
      await loadData();
      setSelectedRoleId(response.role.id);
      setMessage("Rolul nou a fost creat.");
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut crea rolul.");
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="page-header">
          <h1 className="page-title">Roluri și permisiuni</h1>
          <p className="page-description">
            Rolurile de bază pornesc de la <code>Admin</code> și <code>Viewer</code>.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <div className="rounded-md border bg-background px-3 py-2 text-sm text-muted-foreground">
            Autentificat ca{" "}
            <span className="font-medium text-foreground">
              {currentUser?.roles.map((role) => role.name).join(", ")}
            </span>
          </div>
          {can("settings.manage_roles") ? (
            <Button onClick={() => setIsCreateModalOpen(true)}>
              <PlusIcon className="mr-2 h-4 w-4" />
              Adaugă rol
            </Button>
          ) : null}
        </div>
      </div>

      {error ? (
        <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}
      {message ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          {message}
        </div>
      ) : null}

      {isLoading ? (
        <Card>
          <CardContent className="p-10 text-center text-muted-foreground">Se încarcă rolurile...</CardContent>
        </Card>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
          <div className="space-y-3">
            {roles.map((role) => (
              <button
                key={role.id}
                onClick={() => setSelectedRoleId(role.id)}
                className={`w-full rounded-lg border p-4 text-left ${
                  selectedRoleId === role.id ? "bg-muted" : "bg-background hover:bg-muted/50"
                }`}
              >
                <div className="mb-2 flex items-center justify-between">
                  <span className="font-medium">{role.name}</span>
                  {role.admin_panel_access ? <ShieldIcon className="h-4 w-4 text-muted-foreground" /> : null}
                </div>
                <p className="text-sm text-muted-foreground">{role.description || "Fără descriere"}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {role.is_system ? <Badge variant="ready">sistem</Badge> : null}
                  {role.is_default ? <Badge variant="published">implicit</Badge> : null}
                </div>
              </button>
            ))}
          </div>

          <Card>
            <CardHeader className="flex flex-col gap-3 border-b pb-4 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <CardTitle>{selectedRole?.name ?? "Selectează un rol"}</CardTitle>
                <CardDescription>
                  {selectedRole?.description ?? "Alege un rol din stânga pentru a-i vedea permisiunile."}
                </CardDescription>
              </div>
              {can("settings.manage_roles") && selectedRole ? (
                <Button onClick={() => void handleSaveRole()} disabled={isSaving}>
                  {isSaving ? "Se salvează..." : "Salvează modificările"}
                </Button>
              ) : null}
            </CardHeader>

            {selectedRole ? (
              <CardContent className="p-0">
                <div className="admin-scrollbar overflow-x-auto">
                  <table className="min-w-full">
                    <tbody>
                      {Object.entries(groupedPermissions).map(([group, groupPermissions]) => (
                        <Fragment key={group}>
                          <tr className="border-b bg-muted/30">
                            <td colSpan={2} className="px-6 py-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                              {group}
                            </td>
                          </tr>
                          {groupPermissions.map((permission) => {
                            const enabled = draftPermissionIds.includes(permission.id);

                            return (
                              <tr key={permission.id} className="border-b">
                                <td className="px-6 py-4 align-top">
                                  <div className="font-medium">{permission.name}</div>
                                  <div className="mt-1 text-xs text-muted-foreground">{permission.code}</div>
                                  {permission.description ? (
                                    <p className="mt-2 text-sm text-muted-foreground">{permission.description}</p>
                                  ) : null}
                                </td>
                                <td className="px-6 py-4 text-right align-top">
                                  <button
                                    type="button"
                                    disabled={!can("settings.manage_roles")}
                                    onClick={() => setDraftPermissionIds((current) => toggleId(current, permission.id))}
                                    className={`inline-flex h-8 w-8 items-center justify-center rounded-md border ${
                                      enabled ? "bg-primary text-primary-foreground" : "bg-background text-muted-foreground"
                                    }`}
                                  >
                                    {enabled ? <CheckIcon className="h-4 w-4" /> : <XIcon className="h-4 w-4" />}
                                  </button>
                                </td>
                              </tr>
                            );
                          })}
                        </Fragment>
                      ))}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            ) : (
              <CardContent className="py-16 text-center text-muted-foreground">
                Selectează un rol pentru a vedea permisiunile lui.
              </CardContent>
            )}
          </Card>
        </div>
      )}

      <Modal
        isOpen={isCreateModalOpen}
        onClose={() => setIsCreateModalOpen(false)}
        title="Creează rol"
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setIsCreateModalOpen(false)}>
              Anulează
            </Button>
            <Button onClick={() => void handleCreateRole()} disabled={isSaving || roleForm.permission_ids.length === 0}>
              {isSaving ? "Se creează..." : "Creează rolul"}
            </Button>
          </>
        }
      >
        <div className="form-grid">
          <FormField
            label="Nume rol"
            value={roleForm.name}
            onChange={(event) => setRoleForm((current) => ({ ...current, name: event.target.value }))}
          />
          <FormField
            label="Descriere"
            type="textarea"
            value={roleForm.description}
            onChange={(event) =>
              setRoleForm((current) => ({ ...current, description: event.target.value }))
            }
          />
          <FormField
            label="Acces în panoul admin"
            type="toggle"
            helperText="Permite rolului să intre în dashboard-ul administrativ."
            checked={roleForm.admin_panel_access}
            onChange={(event) =>
              setRoleForm((current) => ({
                ...current,
                admin_panel_access: Boolean(event.target.checked),
              }))
            }
          />

          <div className="space-y-2">
            <p className="text-sm font-medium">Permisiuni</p>
            <div className="admin-scrollbar max-h-80 space-y-4 overflow-y-auto rounded-md border p-4">
              {Object.entries(groupedPermissions).map(([group, groupPermissions]) => (
                <div key={group}>
                  <h3 className="mb-2 text-xs uppercase tracking-wide text-muted-foreground">{group}</h3>
                  <div className="grid gap-2 md:grid-cols-2">
                    {groupPermissions.map((permission) => (
                      <label key={permission.id} className="flex items-start gap-3 rounded-md border p-3 text-sm">
                        <input
                          type="checkbox"
                          className="mt-1 h-4 w-4 rounded border-input"
                          checked={roleForm.permission_ids.includes(permission.id)}
                          onChange={() =>
                            setRoleForm((current) => ({
                              ...current,
                              permission_ids: toggleId(current.permission_ids, permission.id),
                            }))
                          }
                        />
                        <span>
                          <span className="block font-medium">{permission.name}</span>
                          <span className="block text-xs text-muted-foreground">{permission.code}</span>
                        </span>
                      </label>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </Modal>
    </div>
  );
}
