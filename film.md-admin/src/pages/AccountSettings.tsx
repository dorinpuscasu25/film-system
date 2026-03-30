import React, { useEffect, useState } from "react";
import { adminApi } from "../lib/api";
import { FormField } from "../components/shared/FormField";
import { useAdmin } from "../hooks/useAdmin";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";

export function AccountSettings() {
  const { currentUser, refreshCurrentUser } = useAdmin();
  const [profileState, setProfileState] = useState({
    name: "",
    email: "",
    preferred_locale: "ro" as "en" | "ro" | "ru",
    avatar_url: "",
  });
  const [passwordState, setPasswordState] = useState({
    current_password: "",
    password: "",
    password_confirmation: "",
  });
  const [profileMessage, setProfileMessage] = useState<string | null>(null);
  const [passwordMessage, setPasswordMessage] = useState<string | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [isSavingProfile, setIsSavingProfile] = useState(false);
  const [isSavingPassword, setIsSavingPassword] = useState(false);

  useEffect(() => {
    if (!currentUser) return;

    setProfileState({
      name: currentUser.name,
      email: currentUser.email,
      preferred_locale: currentUser.preferred_locale,
      avatar_url: currentUser.avatar_url ?? "",
    });
  }, [currentUser]);

  if (!currentUser) {
    return null;
  }

  async function handleProfileSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsSavingProfile(true);
    setProfileError(null);
    setProfileMessage(null);

    try {
      await adminApi.updateProfile({
        ...profileState,
        avatar_url: profileState.avatar_url || undefined,
      });
      await refreshCurrentUser();
      setProfileMessage("Profilul a fost actualizat.");
    } catch (error) {
      setProfileError(error instanceof Error ? error.message : "Nu am putut salva profilul.");
    } finally {
      setIsSavingProfile(false);
    }
  }

  async function handlePasswordSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsSavingPassword(true);
    setPasswordError(null);
    setPasswordMessage(null);

    try {
      await adminApi.updatePassword(passwordState);
      setPasswordMessage("Parola a fost schimbată.");
      setPasswordState({
        current_password: "",
        password: "",
        password_confirmation: "",
      });
    } catch (error) {
      setPasswordError(error instanceof Error ? error.message : "Nu am putut schimba parola.");
    } finally {
      setIsSavingPassword(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="page-header">
        <h1 className="page-title">Setări cont</h1>
        <p className="page-description">
          Editează profilul și securitatea contului care folosește dashboard-ul.
        </p>
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Profil</CardTitle>
            <CardDescription>Datele publice și setările personale ale contului.</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleProfileSubmit} className="form-grid">
              <FormField
                label="Nume complet"
                value={profileState.name}
                onChange={(event) =>
                  setProfileState((current) => ({ ...current, name: event.target.value }))
                }
              />
              <FormField
                label="Email"
                type="email"
                value={profileState.email}
                onChange={(event) =>
                  setProfileState((current) => ({ ...current, email: event.target.value }))
                }
              />
              <FormField
                label="Limbă"
                type="select"
                value={profileState.preferred_locale}
                onChange={(event) =>
                  setProfileState((current) => ({
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
              <FormField
                label="URL avatar"
                value={profileState.avatar_url}
                onChange={(event) =>
                  setProfileState((current) => ({
                    ...current,
                    avatar_url: event.target.value,
                  }))
                }
                helperText="Poți lăsa gol dacă vrei avatar implicit."
              />

              {profileError ? (
                <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                  {profileError}
                </div>
              ) : null}
              {profileMessage ? (
                <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                  {profileMessage}
                </div>
              ) : null}

              <Button type="submit" disabled={isSavingProfile} className="w-fit">
                {isSavingProfile ? "Se salvează..." : "Salvează profilul"}
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Parolă</CardTitle>
            <CardDescription>Actualizează datele de securitate ale contului.</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handlePasswordSubmit} className="form-grid">
              <FormField
                label="Parola curentă"
                type="password"
                value={passwordState.current_password}
                onChange={(event) =>
                  setPasswordState((current) => ({
                    ...current,
                    current_password: event.target.value,
                  }))
                }
              />
              <FormField
                label="Parola nouă"
                type="password"
                value={passwordState.password}
                onChange={(event) =>
                  setPasswordState((current) => ({ ...current, password: event.target.value }))
                }
              />
              <FormField
                label="Confirmă parola"
                type="password"
                value={passwordState.password_confirmation}
                onChange={(event) =>
                  setPasswordState((current) => ({
                    ...current,
                    password_confirmation: event.target.value,
                  }))
                }
              />

              {passwordError ? (
                <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                  {passwordError}
                </div>
              ) : null}
              {passwordMessage ? (
                <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                  {passwordMessage}
                </div>
              ) : null}

              <Button type="submit" disabled={isSavingPassword} variant="outline" className="w-fit">
                {isSavingPassword ? "Se actualizează..." : "Schimbă parola"}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
