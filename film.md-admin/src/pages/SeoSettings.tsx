import { useEffect, useState } from "react";
import { SaveIcon } from "lucide-react";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../components/ui/tabs";
import { FormField } from "../components/shared/FormField";
import { ImageUploadField } from "../components/shared/ImageUploadField";
import { adminApi, ApiRequestError } from "../lib/api";
import type { LocalizedText, TaxonomyLocale } from "../types";

type SeoSettingsForm = {
  site_name: string;
  default_title: LocalizedText;
  default_description: LocalizedText;
  default_image_url: string;
};

const LOCALES: Array<{ value: TaxonomyLocale; label: string }> = [
  { value: "ro", label: "RO" },
  { value: "ru", label: "RU" },
  { value: "en", label: "EN" },
];
const EMPTY_TEXT: LocalizedText = { ro: "", ru: "", en: "" };

function textValue(value: unknown): LocalizedText {
  const input = typeof value === "object" && value !== null ? value as Partial<LocalizedText> : {};

  return {
    ro: String(input.ro ?? ""),
    ru: String(input.ru ?? ""),
    en: String(input.en ?? ""),
  };
}

function settingsValue(value: unknown): SeoSettingsForm {
  const input = typeof value === "object" && value !== null ? value as Record<string, unknown> : {};

  return {
    site_name: String(input.site_name ?? "filmoteca.md"),
    default_title: textValue(input.default_title ?? EMPTY_TEXT),
    default_description: textValue(input.default_description ?? EMPTY_TEXT),
    default_image_url: String(input.default_image_url ?? ""),
  };
}

function trimText(value: LocalizedText): LocalizedText {
  return {
    ro: value.ro.trim(),
    ru: value.ru.trim(),
    en: value.en.trim(),
  };
}

export function SeoSettings() {
  const [form, setForm] = useState<SeoSettingsForm>(() => settingsValue(null));
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [activeLocale, setActiveLocale] = useState<TaxonomyLocale>("ro");

  useEffect(() => {
    let active = true;

    async function loadSettings() {
      setIsLoading(true);
      setError(null);

      try {
        const response = await adminApi.getPlatformSettings();
        if (active) {
          setForm(settingsValue(response.settings.seo));
        }
      } catch (loadError) {
        if (active) {
          setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca setările SEO.");
        }
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void loadSettings();

    return () => {
      active = false;
    };
  }, []);

  async function handleSave() {
    setIsSaving(true);
    setError(null);
    setSuccessMessage(null);

    try {
      await adminApi.savePlatformSettings({
        seo: {
          site_name: form.site_name.trim(),
          default_title: trimText(form.default_title),
          default_description: trimText(form.default_description),
          default_image_url: form.default_image_url.trim(),
        },
      });
      setSuccessMessage("Setările SEO au fost salvate.");
    } catch (saveError) {
      const apiError = saveError as ApiRequestError;
      setError(apiError.message ?? "Nu am putut salva setările SEO.");
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading) {
    return (
      <Card>
        <CardContent className="p-10 text-center text-sm text-muted-foreground">Se încarcă setările SEO...</CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6 pb-16">
      <div className="page-header">
        <h1 className="page-title">SEO</h1>
        <p className="page-description">Setări generale folosite ca fallback pentru pagini și share preview.</p>
      </div>

      {error ? (
        <div className="rounded-md border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      ) : null}

      {successMessage ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          {successMessage}
        </div>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>SEO general</CardTitle>
          <CardDescription>Titlul și descrierea sunt folosite când un film nu are SEO propriu.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="grid gap-4 md:grid-cols-2">
            <FormField
              label="Nume site"
              value={form.site_name}
              onChange={(event) => setForm((current) => ({ ...current, site_name: event.target.value }))}
            />
          </div>

          <ImageUploadField
            label="Imagine implicită pentru share"
            value={form.default_image_url}
            onChange={(value) => setForm((current) => ({ ...current, default_image_url: value }))}
            previewLabel="Share preview"
            uploadDirectory="seo"
            recommendation={{
              resolution: "1200 x 630 px, raport 1.91:1",
              formats: "WebP, JPG/JPEG sau PNG",
              note: "Folosită ca imagine fallback pentru preview-uri sociale.",
            }}
          />

          <Tabs value={activeLocale} onValueChange={(value) => setActiveLocale(value as TaxonomyLocale)} className="space-y-6">
            <TabsList className="grid h-auto w-full grid-cols-3">
              {LOCALES.map((locale) => (
                <TabsTrigger key={locale.value} value={locale.value}>
                  {locale.label}
                </TabsTrigger>
              ))}
            </TabsList>

            {LOCALES.map((locale) => (
              <TabsContent key={locale.value} value={locale.value} className="space-y-4">
                <FormField
                  label={`Titlu implicit (${locale.label})`}
                  value={form.default_title[locale.value]}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      default_title: { ...current.default_title, [locale.value]: event.target.value },
                    }))
                  }
                />
                <FormField
                  label={`Descriere implicită (${locale.label})`}
                  type="textarea"
                  rows={4}
                  value={form.default_description[locale.value]}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      default_description: { ...current.default_description, [locale.value]: event.target.value },
                    }))
                  }
                />
              </TabsContent>
            ))}
          </Tabs>

          <div className="flex justify-end">
            <Button onClick={() => void handleSave()} disabled={isSaving}>
              <SaveIcon className="h-4 w-4" />
              {isSaving ? "Se salvează..." : "Salvează SEO"}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
