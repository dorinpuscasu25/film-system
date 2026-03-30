import React, { useState } from "react";
import { FilmIcon, GithubIcon } from "lucide-react";
import { useAdmin } from "../hooks/useAdmin";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";

export function LoginPage() {
  const { login, authError, isAuthLoading } = useAdmin();
  const [email, setEmail] = useState("admin@film.md");
  const [password, setPassword] = useState("password");

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await login(email, password);
  }

  return (
    <div className="grid min-h-screen bg-background lg:grid-cols-2">
      <div className="hidden border-r bg-muted/40 lg:flex lg:flex-col">
        <div className="flex items-center justify-between px-10 py-8">
          <div className="flex items-center gap-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-md border bg-background">
              <FilmIcon className="h-4 w-4" />
            </div>
            <span className="text-sm font-semibold">film.md</span>
          </div>
          <Button variant="ghost" size="sm">
            Login
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
              <span className="text-sm font-semibold">film.md</span>
            </div>
            <Button variant="ghost" size="sm">
              Login
            </Button>
          </div>

          <div className="space-y-2 text-center">
            <h1 className="text-3xl font-semibold tracking-tight">Admin login</h1>
            <p className="text-sm text-muted-foreground">
              Intră cu contul care are permisiunea <code>admin.access</code>.
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <Input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="name@example.com"
              required
            />
            <Input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              placeholder="Password"
              required
            />

            {authError ? (
              <div className="rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                {authError}
              </div>
            ) : null}

            <Button type="submit" className="w-full" disabled={isAuthLoading}>
              {isAuthLoading ? "Se autentifică..." : "Sign in with Email"}
            </Button>

            <div className="relative">
              <div className="absolute inset-0 flex items-center">
                <span className="w-full border-t" />
              </div>
              <div className="relative flex justify-center text-xs uppercase">
                <span className="bg-background px-2 text-muted-foreground">Seed account</span>
              </div>
            </div>

            <div className="rounded-md border bg-muted/40 p-4 text-sm text-muted-foreground">
              <div>Email: <span className="font-medium text-foreground">admin@film.md</span></div>
              <div className="mt-1">Password: <span className="font-medium text-foreground">password</span></div>
            </div>

            <Button type="button" variant="outline" className="w-full">
              <GithubIcon className="mr-2 h-4 w-4" />
              GitHub
            </Button>
          </form>

          <p className="text-center text-xs text-muted-foreground">
            By continuing, you agree to the Terms of Service and Privacy Policy.
          </p>
        </div>
      </div>
    </div>
  );
}
