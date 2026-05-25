import { useEffect, useState } from "react";
import { EditIcon, EyeIcon, MenuIcon, PlusIcon, TrashIcon } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { adminApi, Menu } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";

export function Menus() {
  const { can } = useAdmin();
  const navigate = useNavigate();
  const [menus, setMenus] = useState<Menu[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const loadMenus = async () => {
      try {
        setIsLoading(true);
        const response = await adminApi.getMenus();
        setMenus(response.items);
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca meniurile.");
      } finally {
        setIsLoading(false);
      }
    };

    void loadMenus();
  }, []);

  const deleteMenu = async (menu: Menu) => {
    if (!confirm(`Ștergi meniul "${menu.name}"?`)) {
      return;
    }

    await adminApi.deleteMenu(menu.id);
    setMenus((current) => current.filter((item) => item.id !== menu.id));
  };

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="page-header">
          <h1 className="page-title">Meniuri</h1>
          <p className="page-description">Configurează meniurile pentru Header și Footer, apoi ordonează itemii prin drag and drop.</p>
        </div>
        {can("cms.create") ? (
          <Button onClick={() => navigate("/menus/new")}>
            <PlusIcon className="h-4 w-4" />
            Creează meniu
          </Button>
        ) : null}
      </div>

      {error ? <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div> : null}

      <Card className="overflow-hidden rounded-lg">
        <table className="min-w-full divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Nume</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Slug</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Locație</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Itemi</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Status</th>
              <th className="px-6 py-3 text-right text-xs font-medium uppercase text-slate-500">Acțiuni</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {isLoading ? (
              <tr>
                <td className="px-6 py-8 text-center text-sm text-slate-500" colSpan={6}>
                  Se încarcă meniurile...
                </td>
              </tr>
            ) : menus.length === 0 ? (
              <tr>
                <td className="px-6 py-8 text-center text-sm text-slate-500" colSpan={6}>
                  Nu există meniuri încă.
                </td>
              </tr>
            ) : (
              menus.map((menu) => (
                <tr key={menu.id} className="hover:bg-slate-50">
                  <td className="px-6 py-4">
                    <button onClick={() => navigate(`/menus/${menu.id}`)} className="flex items-center gap-3 text-left text-sm font-medium text-slate-900 hover:text-indigo-600">
                      <MenuIcon className="h-5 w-5 text-slate-400" />
                      {menu.name}
                    </button>
                  </td>
                  <td className="px-6 py-4 text-sm text-slate-500">{menu.slug}</td>
                  <td className="px-6 py-4 text-sm text-slate-700">{menu.location_label}</td>
                  <td className="px-6 py-4 text-sm text-slate-500">{menu.items_count}</td>
                  <td className="px-6 py-4">
                    <span className={menu.active ? "inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800" : "inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700"}>
                      {menu.active ? "Activ" : "Inactiv"}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex justify-end gap-2">
                      <Button variant="ghost" size="icon" onClick={() => navigate(`/menus/${menu.id}`)}>
                        <EyeIcon className="h-4 w-4" />
                      </Button>
                      {can("cms.edit") ? (
                        <Button variant="ghost" size="icon" onClick={() => navigate(`/menus/${menu.id}/edit`)}>
                          <EditIcon className="h-4 w-4" />
                        </Button>
                      ) : null}
                      {can("cms.delete") ? (
                        <Button variant="ghost" size="icon" onClick={() => void deleteMenu(menu)}>
                          <TrashIcon className="h-4 w-4 text-red-500" />
                        </Button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>
    </div>
  );
}
