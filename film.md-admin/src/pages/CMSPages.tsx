import { useEffect, useState } from "react";
import { EditIcon, FileTextIcon, PlusIcon, TrashIcon } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { adminApi, CmsPage } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";
import { Button } from "../components/ui/button";
import { Card } from "../components/ui/card";

function formatDate(value: string | null) {
  if (!value) {
    return "-";
  }

  return new Intl.DateTimeFormat("ro-RO", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(value));
}

export function CMSPages() {
  const { can } = useAdmin();
  const navigate = useNavigate();
  const [pages, setPages] = useState<CmsPage[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadPages = async () => {
    try {
      setIsLoading(true);
      setError(null);
      const response = await adminApi.getPages();
      setPages(response.items);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca paginile.");
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    void loadPages();
  }, []);

  const deletePage = async (page: CmsPage) => {
    if (!confirm(`Ștergi pagina "${page.title}"?`)) {
      return;
    }

    await adminApi.deletePage(page.id);
    setPages((current) => current.filter((item) => item.id !== page.id));
  };

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="page-header">
          <h1 className="page-title">Pagini</h1>
          <p className="page-description">Pagini statice publicabile pe site, cu conținut rich text și SEO separat.</p>
        </div>

        {can("cms.create") ? (
          <Button onClick={() => navigate("/cms/new")}>
            <PlusIcon className="h-4 w-4" />
            Creează pagină
          </Button>
        ) : null}
      </div>

      {error ? (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      ) : null}

      <Card className="overflow-hidden rounded-lg">
        <table className="min-w-full divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Titlu pagină</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Slug</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Ultima actualizare</th>
              <th className="px-6 py-3 text-left text-xs font-medium uppercase text-slate-500">Status</th>
              <th className="px-6 py-3 text-right text-xs font-medium uppercase text-slate-500">Acțiuni</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {isLoading ? (
              <tr>
                <td className="px-6 py-8 text-center text-sm text-slate-500" colSpan={5}>
                  Se încarcă paginile...
                </td>
              </tr>
            ) : pages.length === 0 ? (
              <tr>
                <td className="px-6 py-8 text-center text-sm text-slate-500" colSpan={5}>
                  Nu există pagini încă.
                </td>
              </tr>
            ) : (
              pages.map((page) => (
                <tr key={page.id} className="hover:bg-slate-50">
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <FileTextIcon className="h-5 w-5 text-slate-400" />
                      <span className="text-sm font-medium text-slate-900">{page.title}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-slate-500">/page/{page.slug}</td>
                  <td className="px-6 py-4 text-sm text-slate-500">{formatDate(page.updated_at)}</td>
                  <td className="px-6 py-4">
                    <span
                      className={
                        page.status === "published"
                          ? "inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800"
                          : "inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700"
                      }
                    >
                      {page.status_label}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex justify-end gap-2">
                      {can("cms.edit") ? (
                        <Button variant="ghost" size="icon" onClick={() => navigate(`/cms/${page.id}`)}>
                          <EditIcon className="h-4 w-4" />
                        </Button>
                      ) : null}
                      {can("cms.delete") ? (
                        <Button variant="ghost" size="icon" onClick={() => void deletePage(page)}>
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
