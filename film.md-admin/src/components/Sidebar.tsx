import React from "react";
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  CreditCardIcon,
  FileTextIcon,
  FilmIcon,
  FolderTreeIcon,
  HomeIcon,
  Image as ImageIcon,
  KeyIcon,
  LayoutTemplateIcon,
  PlayCircleIcon,
  SearchIcon,
  ShieldAlertIcon,
  TagsIcon,
  UsersIcon,
} from "lucide-react";
import { cn } from "../lib/utils";
import { useAdmin } from "../hooks/useAdmin";
import { Button } from "./ui/button";

export function Sidebar() {
  const { currentPage, navigate, sidebarCollapsed, toggleSidebar, can } = useAdmin();

  const menuGroups = [
    {
      title: "Explorare",
      items: [
        { id: "dashboard", label: "Panou", icon: HomeIcon, show: true },
        { id: "catalog", label: "Catalog", icon: FilmIcon, show: can("content.view") },
        { id: "media", label: "Bibliotecă media", icon: ImageIcon, show: can("media.view") },
        { id: "taxonomies", label: "Taxonomii", icon: TagsIcon, show: can("taxonomies.view") },
        { id: "collections", label: "Colecții", icon: FolderTreeIcon, show: can("taxonomies.view") },
      ],
    },
    {
      title: "Operațiuni",
      items: [
        { id: "billing", label: "Facturare", icon: CreditCardIcon, show: can("commerce.view_billing") },
        {
          id: "home-curation",
          label: "Homepage",
          icon: LayoutTemplateIcon,
          show: can("settings.edit_home_curation"),
        },
        {
          id: "discovery",
          label: "Căutare și descoperire",
          icon: SearchIcon,
          show: can("settings.edit_search_config"),
        },
        { id: "cms", label: "Pagini CMS", icon: FileTextIcon, show: can("cms.view") },
        { id: "playback", label: "Operațiuni playback", icon: PlayCircleIcon, show: can("playback.view_sessions") },
        { id: "users", label: "Utilizatori și acces", icon: UsersIcon, show: can("users.view") },
        {
          id: "roles",
          label: "Roluri și permisiuni",
          icon: KeyIcon,
          show: can("settings.manage_roles") || can("users.view"),
        },
        { id: "moderation", label: "Moderare", icon: ShieldAlertIcon, show: can("moderation.view_queue") },
      ],
    },
  ];

  return (
    <aside
      className={cn(
        "fixed inset-y-0 left-0 z-40 flex flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-all duration-200 lg:translate-x-0",
        sidebarCollapsed
          ? "-translate-x-full lg:w-16 lg:translate-x-0"
          : "w-64 translate-x-0",
      )}
    >
      <div className="border-b border-sidebar-border px-4 py-4">
        <div className={cn("flex items-center gap-3", sidebarCollapsed && "justify-center lg:justify-center")}>
          <div className="flex h-8 w-8 items-center justify-center rounded-md border border-sidebar-border bg-sidebar-accent text-sidebar-foreground">
            <PlayCircleIcon className="h-4 w-4" />
          </div>
          {!sidebarCollapsed ? (
            <div>
              <div className="text-sm font-semibold">film.md</div>
              <div className="text-xs text-white/60">Workspace administrare</div>
            </div>
          ) : null}
        </div>
      </div>

      <div className="px-3 py-4">
        {!sidebarCollapsed ? (
          <div className="rounded-lg border border-sidebar-border bg-sidebar-accent px-3 py-3">
            <div className="text-[11px] uppercase tracking-wide text-white/50">Echipă</div>
            <div className="mt-1 text-sm font-medium">Echipă personală</div>
          </div>
        ) : null}
      </div>

      <nav className="admin-scrollbar flex-1 space-y-6 overflow-y-auto px-3 pb-4">
        {menuGroups.map((group) => {
          const visibleItems = group.items.filter((item) => item.show);
          if (visibleItems.length === 0) {
            return null;
          }

          return (
            <div key={group.title} className="space-y-1">
              {!sidebarCollapsed ? (
                <div className="px-2 py-1 text-[11px] uppercase tracking-wide text-white/40">
                  {group.title}
                </div>
              ) : null}
              {visibleItems.map((item) => {
                const Icon = item.icon;
                const isActive = currentPage === item.id || (item.id === "catalog" && currentPage === "editor");

                return (
                  <button
                    key={item.id}
                    onClick={() => navigate(item.id)}
                    className={cn(
                      "flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors",
                      isActive
                        ? "bg-sidebar-accent text-sidebar-accent-foreground"
                        : "text-white/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
                      sidebarCollapsed && "justify-center px-2",
                    )}
                    title={sidebarCollapsed ? item.label : undefined}
                  >
                    <Icon className="h-4 w-4 shrink-0" />
                    {!sidebarCollapsed ? <span>{item.label}</span> : null}
                  </button>
                );
              })}
            </div>
          );
        })}
      </nav>

      <div className="border-t border-sidebar-border p-3">
        <Button
          variant="ghost"
          className="w-full justify-start border border-sidebar-border text-white/70 hover:bg-sidebar-accent hover:text-white"
          onClick={toggleSidebar}
        >
          {sidebarCollapsed ? <ChevronRightIcon className="h-4 w-4" /> : <ChevronLeftIcon className="h-4 w-4" />}
          {!sidebarCollapsed ? <span>Restrânge</span> : null}
        </Button>
      </div>
    </aside>
  );
}
