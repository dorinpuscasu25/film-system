import { useEffect, useState } from "react";
import { FileTextIcon, RefreshCwIcon, SaveIcon, SettingsIcon } from "lucide-react";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { FormField } from "../components/shared/FormField";
import { adminApi } from "../lib/api";
import type { CmsPage } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";

export function CMSSettings() {
  const { can } = useAdmin();
  const canEdit = can("settings.edit_home_curation");
  const [pages, setPages] = useState<CmsPage[]>([]);
  const [termsPageId, setTermsPageId] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setIsLoading(true);
    setError(null);

    try {
      const [pagesResponse, platformSettings] = await Promise.all([
        adminApi.getPages(),
        adminApi.getPlatformSettings(),
      ]);

      setPages(pagesResponse.items);
      setTermsPageId(platformSettings.settings.terms_page_id ? String(platformSettings.settings.terms_page_id) : "");
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca setările.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  async function saveTermsPage() {
    setIsSaving(true);
    setMessage(null);
    setError(null);

    try {
      await adminApi.savePlatformSettings({
        terms_page_id: termsPageId ? Number(termsPageId) : null,
      });
      setMessage("Pagina de termeni și condiții a fost salvată.");
      await load();
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut salva setările.");
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading) {
    return (
      <Card>
        <CardContent className="p-10 text-center text-sm text-muted-foreground">Se încarcă setările...</CardContent>
      </Card>
    );
  }

  const publishedPages = pages.filter((page) => page.status === "published");

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">Setări</h1>
          <p className="page-description">
            Configurezi setările legate de paginile publice și textele legale folosite în storefront.
          </p>
        </div>

        <Button variant="outline" onClick={() => void load()}>
          <RefreshCwIcon className="h-4 w-4" />
          Reîncarcă
        </Button>
      </div>

      {error ? (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      ) : null}

      <Card className="w-full">
        <CardHeader className="gap-2">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <CardTitle>Termeni și condiții la plată</CardTitle>
              <CardDescription>
                Alege pagina CMS publicată către care duce linkul din checkout-ul de suplinire portofel.
              </CardDescription>
            </div>
            <div className="rounded-md border bg-muted p-2">
              <FileTextIcon className="h-4 w-4 text-muted-foreground" />
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          <FormField
            label="Pagina de termeni"
            type="select"
            value={termsPageId}
            disabled={!canEdit}
            onChange={(event) => setTermsPageId(event.target.value)}
            helperText="Sunt afișate doar paginile publicate, ca linkul să fie accesibil pe site."
            options={[
              { label: "Selectează pagina...", value: "" },
              ...publishedPages.map((page) => ({
                label: page.title || page.slug,
                value: page.id,
              })),
            ]}
          />

          {message ? (
            <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
              {message}
            </div>
          ) : null}

          {!canEdit ? (
            <p className="text-xs text-muted-foreground">
              Doar utilizatorii cu permisiunea <code>settings.edit_home_curation</code> pot modifica această setare.
            </p>
          ) : null}

          {canEdit ? (
            <div className="flex justify-end border-t pt-4">
              <Button onClick={() => void saveTermsPage()} disabled={isSaving}>
                <SaveIcon className="h-4 w-4" />
                {isSaving ? "Se salvează..." : "Salvează setările"}
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card className="w-full">
        <CardHeader className="gap-2">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <CardTitle>Setări viitoare</CardTitle>
              <CardDescription>
                Secțiunea este pregătită pentru alte opțiuni legate de pagini, meniuri și texte legale.
              </CardDescription>
            </div>
            <div className="rounded-md border bg-muted p-2">
              <SettingsIcon className="h-4 w-4 text-muted-foreground" />
            </div>
          </div>
        </CardHeader>
      </Card>
    </div>
  );
}
