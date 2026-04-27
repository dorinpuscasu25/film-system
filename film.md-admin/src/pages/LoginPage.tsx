import React, { useState } from "react";
import { FilmIcon } from "lucide-react";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";

export function LoginPage() {
  const { login, authError, isAuthLoading } = useAdmin();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [isResetMode, setIsResetMode] = useState(false);
  const [resetMessage, setResetMessage] = useState<string | null>(null);
  const [resetError, setResetError] = useState<string | null>(null);
  const [isResetLoading, setIsResetLoading] = useState(false);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await login(email, password);
  }

  async function handlePasswordReset(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setResetError(null);
    setResetMessage(null);
    setIsResetLoading(true);

    try {
      const response = await adminApi.forgotPassword(email);
      setResetMessage(response.message);
    } catch (error) {
      setResetError(error instanceof Error ? error.message : "Nu am putut trimite emailul de resetare.");
    } finally {
      setIsResetLoading(false);
    }
  }

  return (
    <div className="grid min-h-screen bg-background lg:grid-cols-2">
      <div className="hidden border-r bg-muted/40 lg:flex lg:flex-col">
        <div className="flex items-center justify-between px-10 py-8">
          <div className="flex items-center gap-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-md border bg-background">
              <FilmIcon className="h-4 w-4" />
            </div>
            <span className="text-sm font-semibold">filmoteca.md</span>
          </div>
          <Button variant="ghost" size="sm">
            Autentificare
          </Button>
        </div>
        <div className="flex-1 bg-muted/60" />
      </div>

      <div className="flex items-center justify-center px-6 py-10">
        <div className="w-full max-w-sm space-y-6">
          <div className="flex items-center justify-between lg:hidden">
          <div className="flex items-center gap-3">
              <div className="flex h-8 w-8 items-center justify-center rounded-md border">
                <FilmIcon className="h-4 w-4" />
              </div>
              <span className="text-sm font-semibold">filmoteca.md</span>
            </div>
            <Button variant="ghost" size="sm">
              Autentificare
            </Button>
          </div>

          <div className="space-y-2 text-center">
            <h1 className="text-3xl font-semibold tracking-tight">
              {isResetMode ? "Resetează parola" : "Autentificare admin"}
            </h1>
          </div>

          <form onSubmit={isResetMode ? handlePasswordReset : handleSubmit} className="space-y-4">
            <Input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="name@example.com"
              required
            />
            {!isResetMode ? (
              <Input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                placeholder="Parolă"
                required
              />
            ) : null}

            {!isResetMode && authError ? (
              <div className="rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                {authError}
              </div>
            ) : null}

            {isResetMode && resetError ? (
              <div className="rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                {resetError}
              </div>
            ) : null}

            {isResetMode && resetMessage ? (
              <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                {resetMessage}
              </div>
            ) : null}

            <Button type="submit" className="w-full" disabled={isResetMode ? isResetLoading : isAuthLoading}>
              {isResetMode
                ? (isResetLoading ? "Se trimite..." : "Trimite link de resetare")
                : (isAuthLoading ? "Se autentifică..." : "Intră cu email")}
            </Button>

            <button
              type="button"
              className="w-full text-sm text-muted-foreground transition-colors hover:text-foreground"
              onClick={() => {
                setIsResetMode((current) => !current);
                setResetError(null);
                setResetMessage(null);
              }}
            >
              {isResetMode ? "Înapoi la autentificare" : "Ai uitat parola?"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
