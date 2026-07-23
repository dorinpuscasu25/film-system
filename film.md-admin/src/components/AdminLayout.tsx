import React, { useState } from "react";
import {
  BellIcon,
  LogOutIcon,
  MenuIcon,
  RefreshCwIcon,
  SearchIcon,
  SettingsIcon,
  UserCircle2Icon,
} from "lucide-react";
import { Sidebar } from "./Sidebar";
import { useAdmin } from "../hooks/useAdmin";
import { adminApi } from "../lib/api";
import { Button } from "./ui/button";
import { Input } from "./ui/input";

export function AdminLayout({ children }: { children: React.ReactNode }) {
  const { breadcrumbs, currentUser, navigate, toggleSidebar, logout, sidebarCollapsed } = useAdmin();
  const [isClearingCache, setIsClearingCache] = useState(false);
  const roleLabel = currentUser?.roles.map((role) => role.name).join(", ") || "Admin";
  const canClearCache =
    currentUser?.permission_codes.includes("settings.edit_home_curation") ?? false;

  const handleClearCache = async () => {
    if (!window.confirm("Sigur vrei să cureți cache-ul aplicației și cache-ul CDN?")) {
      return;
    }

    setIsClearingCache(true);

    try {
      const result = await adminApi.clearAllCaches();

      if (result.cloudflare_cache === "failed") {
        window.alert("Cache-ul aplicației a fost curățat, dar cache-ul Cloudflare nu a putut fi purjat.");
        return;
      }

      const cloudflareMessage =
        result.cloudflare_cache === "purged"
          ? " Cache-ul Cloudflare a fost purjat."
          : " Cloudflare nu este încă configurat pentru purjare automată.";

      window.alert(`Cache-ul aplicației a fost curățat.${cloudflareMessage}`);
    } catch (error) {
      const message = error instanceof Error ? error.message : "A apărut o eroare necunoscută.";
      window.alert(`Cache-ul nu a putut fi curățat: ${message}`);
    } finally {
      setIsClearingCache(false);
    }
  };

  return (
    <div className="app-shell">
      <Sidebar />

      <div className={`flex min-h-screen flex-col ${sidebarCollapsed ? "lg:pl-16" : "lg:pl-64"}`}>
        <header className="sticky top-0 z-30 border-b bg-background/95 backdrop-blur">
          <div className="flex h-14 items-center gap-3 px-4 lg:px-6">
            <Button variant="ghost" size="icon" className="lg:hidden" onClick={toggleSidebar}>
              <MenuIcon className="h-4 w-4" />
            </Button>

            <div className="min-w-0">
              <div className="text-sm font-medium">
                {breadcrumbs[breadcrumbs.length - 1] ?? "Panou"}
              </div>
              <div className="hidden text-xs text-muted-foreground md:block">
                {breadcrumbs.join(" / ")}
              </div>
            </div>

            <div className="ml-auto flex items-center gap-2">
              <div className="relative hidden md:block">
                <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="h-9 w-[240px] pl-9"
                  placeholder="Caută utilizatori, roluri, conținut..."
                />
              </div>

              <Button variant="ghost" size="icon">
                <BellIcon className="h-4 w-4" />
              </Button>
              {canClearCache && (
                <Button
                  variant="outline"
                  size="sm"
                  className="hidden sm:inline-flex"
                  disabled={isClearingCache}
                  onClick={() => void handleClearCache()}
                >
                  <RefreshCwIcon
                    className={`mr-2 h-4 w-4 ${isClearingCache ? "animate-spin" : ""}`}
                  />
                  {isClearingCache ? "Se curăță..." : "Curăță cache"}
                </Button>
              )}
              <Button
                variant="ghost"
                size="sm"
                className="hidden sm:inline-flex"
                onClick={() => navigate("account", null, ["Setări cont"])}
              >
                <SettingsIcon className="mr-2 h-4 w-4" />
                Setări
              </Button>

              <div className="hidden items-center gap-3 rounded-md border px-3 py-1.5 md:flex">
                <UserCircle2Icon className="h-5 w-5 text-muted-foreground" />
                <div className="min-w-0">
                  <div className="truncate text-sm font-medium">
                    {currentUser?.name ?? "Utilizator admin"}
                  </div>
                  <div className="truncate text-xs text-muted-foreground">{roleLabel}</div>
                </div>
              </div>

              <Button variant="outline" size="sm" onClick={() => void logout()}>
                <LogOutIcon className="mr-2 h-4 w-4" />
                Ieșire
              </Button>
            </div>
          </div>
        </header>

        <main className="flex-1 bg-muted/30 px-4 py-6 lg:px-6">
          <div className="w-full">{children}</div>
        </main>
      </div>
    </div>
  );
}
