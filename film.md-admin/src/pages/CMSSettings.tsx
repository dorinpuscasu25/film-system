import { useEffect, useState } from "react";
import { FileTextIcon, MailIcon, RefreshCwIcon, SaveIcon } from "lucide-react";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { FormField } from "../components/shared/FormField";
import { adminApi } from "../lib/api";
import type { CmsPage } from "../lib/api";
import { useAdmin } from "../hooks/useAdmin";

type Locale = "ro" | "ru" | "en";

interface ContactSettings {
  operator_name: string;
  email: string;
  phone: string;
  address: Record<Locale, string>;
  working_hours: Record<Locale, string>;
  description: Record<Locale, string>;
}

const locales: { code: Locale; label: string }[] = [
  { code: "ro", label: "Română" },
  { code: "ru", label: "Русский" },
  { code: "en", label: "English" },
];

function emptyContact(): ContactSettings {
  return {
    operator_name: "",
    email: "",
    phone: "",
    address: { ro: "", ru: "", en: "" },
    working_hours: { ro: "", ru: "", en: "" },
    description: { ro: "", ru: "", en: "" },
  };
}

function normalizeContact(value: unknown): ContactSettings {
  if (!value || typeof value !== "object" || Array.isArray(value)) return emptyContact();
  const source = value as Record<string, unknown>;
  const localized = (field: unknown): Record<Locale, string> => {
    const record = field && typeof field === "object" && !Array.isArray(field)
      ? field as Record<string, unknown>
      : {};

    return {
      ro: typeof record.ro === "string" ? record.ro : "",
      ru: typeof record.ru === "string" ? record.ru : "",
      en: typeof record.en === "string" ? record.en : "",
    };
  };

  return {
    operator_name: typeof source.operator_name === "string" ? source.operator_name : "",
    email: typeof source.email === "string" ? source.email : "",
    phone: typeof source.phone === "string" ? source.phone : "",
    address: localized(source.address),
    working_hours: localized(source.working_hours),
    description: localized(source.description),
  };
}

export function CMSSettings() {
  const { can } = useAdmin();
  const canEdit = can("settings.edit_home_curation");
  const [pages, setPages] = useState<CmsPage[]>([]);
  const [termsPageId, setTermsPageId] = useState("");
  const [contact, setContact] = useState<ContactSettings>(emptyContact);
  const [isLoading, setIsLoading] = useState(true);
  const [savingSection, setSavingSection] = useState<"terms" | "contact" | null>(null);
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
      setContact(normalizeContact(platformSettings.settings.contact));
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca setările.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  function updateLocalized(field: "address" | "working_hours" | "description", locale: Locale, value: string) {
    setContact((current) => ({
      ...current,
      [field]: { ...current[field], [locale]: value },
    }));
  }

  async function saveTermsPage() {
    setSavingSection("terms");
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
      setSavingSection(null);
    }
  }

  async function saveContact() {
    setSavingSection("contact");
    setMessage(null);
    setError(null);

    try {
      await adminApi.savePlatformSettings({ contact });
      setMessage("Datele paginii Contacte au fost salvate și sunt vizibile pe site.");
      await load();
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut salva datele de contact.");
    } finally {
      setSavingSection(null);
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
            Configurezi paginile publice, datele de contact și textele legale folosite în storefront.
          </p>
        </div>

        <Button variant="outline" onClick={() => void load()} disabled={savingSection !== null}>
          <RefreshCwIcon className="h-4 w-4" />
          Reîncarcă
        </Button>
      </div>

      {error ? (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      ) : null}
      {message ? (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{message}</div>
      ) : null}

      <Card className="w-full">
        <CardHeader className="gap-2">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <CardTitle>Pagina Contacte</CardTitle>
              <CardDescription>
                Completează informațiile afișate public. Câmpurile localizate folosesc româna ca rezervă dacă o traducere lipsește.
              </CardDescription>
            </div>
            <div className="rounded-md border bg-muted p-2">
              <MailIcon className="h-4 w-4 text-muted-foreground" />
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="grid gap-4 lg:grid-cols-2">
            <FormField
              label="Denumirea operatorului"
              value={contact.operator_name}
              disabled={!canEdit}
              onChange={(event) => setContact((current) => ({ ...current, operator_name: event.target.value }))}
              placeholder="Ex.: FILMOTECA.md"
            />
            <FormField
              label="Email pentru suport"
              type="email"
              value={contact.email}
              disabled={!canEdit}
              onChange={(event) => setContact((current) => ({ ...current, email: event.target.value }))}
              placeholder="suport@exemplu.md"
            />
            <FormField
              label="Telefon"
              type="text"
              value={contact.phone}
              disabled={!canEdit}
              onChange={(event) => setContact((current) => ({ ...current, phone: event.target.value }))}
              placeholder="Ex.: +373 00 000 000"
              helperText="Poate rămâne necompletat dacă suportul se oferă doar prin email."
            />
          </div>

          <div className="grid gap-4 xl:grid-cols-3">
            {locales.map((locale) => (
              <div key={locale.code} className="space-y-4 rounded-lg border bg-muted/20 p-4">
                <p className="text-sm font-semibold">{locale.label}</p>
                <FormField
                  label="Adresă"
                  type="textarea"
                  rows={3}
                  value={contact.address[locale.code]}
                  disabled={!canEdit}
                  onChange={(event) => updateLocalized("address", locale.code, event.target.value)}
                  placeholder="Adresa afișată pe pagina Contacte"
                />
                <FormField
                  label="Program de lucru"
                  type="textarea"
                  rows={2}
                  value={contact.working_hours[locale.code]}
                  disabled={!canEdit}
                  onChange={(event) => updateLocalized("working_hours", locale.code, event.target.value)}
                  placeholder="Ex.: Luni–Vineri, 09:00–18:00"
                />
                <FormField
                  label="Text informativ"
                  type="textarea"
                  rows={5}
                  value={contact.description[locale.code]}
                  disabled={!canEdit}
                  onChange={(event) => updateLocalized("description", locale.code, event.target.value)}
                  placeholder="Cum pot utilizatorii să ia legătura cu echipa"
                />
              </div>
            ))}
          </div>

          {!canEdit ? (
            <p className="text-xs text-muted-foreground">
              Doar utilizatorii cu permisiunea <code>settings.edit_home_curation</code> pot modifica aceste date.
            </p>
          ) : null}

          {canEdit ? (
            <div className="flex border-t pt-4 sm:justify-end">
              <Button className="w-full sm:w-auto" onClick={() => void saveContact()} disabled={savingSection !== null}>
                <SaveIcon className="h-4 w-4" />
                {savingSection === "contact" ? "Se salvează..." : "Salvează contactele"}
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>

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

          {canEdit ? (
            <div className="flex border-t pt-4 sm:justify-end">
              <Button className="w-full sm:w-auto" onClick={() => void saveTermsPage()} disabled={savingSection !== null}>
                <SaveIcon className="h-4 w-4" />
                {savingSection === "terms" ? "Se salvează..." : "Salvează setările"}
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}
