import { useEffect, useState } from "react";
import { ArrowLeftIcon, SaveIcon } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { adminApi, LocaleMap, MenuPayload } from "../lib/api";
import { Button } from "../components/ui/button";
import { Card, CardContent } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Textarea } from "../components/ui/textarea";
import { Switch } from "../components/ui/switch";
import { cn } from "../lib/utils";

const LOCALES = ["ro", "ru", "en"];

function emptyLocaleMap(): LocaleMap {
  return { ro: "", ru: "", en: "" };
}

function normalizeLocaleMap(value?: LocaleMap): LocaleMap {
  return LOCALES.reduce<LocaleMap>((carry, locale) => {
    carry[locale] = value?.[locale] ?? "";
    return carry;
  }, {});
}

function slugify(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

const initialForm: MenuPayload = {
  name: emptyLocaleMap(),
  slug: "",
  location: "header",
  description: emptyLocaleMap(),
  active: true,
};

export function MenuEditor({ menuId }: { menuId?: string }) {
  const navigate = useNavigate();
  const isNew = !menuId || menuId === "new";
  const [activeLocale, setActiveLocale] = useState("ro");
  const [form, setForm] = useState<MenuPayload>(initialForm);
  const [isLoading, setIsLoading] = useState(!isNew);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isNew || !menuId) {
      return;
    }

    const loadMenu = async () => {
      try {
        setIsLoading(true);
        const response = await adminApi.getMenu(Number(menuId));
        setForm({
          name: normalizeLocaleMap(response.menu.name_translations),
          slug: response.menu.slug,
          location: response.menu.location,
          description: normalizeLocaleMap(response.menu.description_translations),
          active: response.menu.active,
        });
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca meniul.");
      } finally {
        setIsLoading(false);
      }
    };

    void loadMenu();
  }, [isNew, menuId]);

  const updateLocalized = (field: "name" | "description", value: string) => {
    setForm((current) => {
      const next = { ...current, [field]: { ...current[field], [activeLocale]: value } };
      if (field === "name" && isNew && !current.slug && activeLocale === "ro") {
        next.slug = slugify(value);
      }
      return next;
    });
  };

  const saveMenu = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);

    if (!LOCALES.some((locale) => form.name[locale]?.trim())) {
      setError("Completează numele meniului în cel puțin o limbă.");
      return;
    }

    try {
      setIsSaving(true);
      if (isNew) {
        const response = await adminApi.createMenu(form);
        navigate(`/menus/${response.menu.id}`, { replace: true });
      } else if (menuId) {
        await adminApi.updateMenu(Number(menuId), form);
        navigate(`/menus/${menuId}`);
      }
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut salva meniul.");
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return <div className="rounded-lg border bg-white p-6 text-sm text-slate-500">Se încarcă meniul...</div>;
  }

  return (
    <form className="w-full space-y-6" onSubmit={saveMenu}>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="page-header">
          <button type="button" onClick={() => navigate("/menus")} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900">
            <ArrowLeftIcon className="h-4 w-4" />
            Înapoi la meniuri
          </button>
          <h1 className="page-title">{isNew ? "Creează meniu" : "Editează meniu"}</h1>
        </div>
        <Button type="submit" disabled={isSaving}>
          <SaveIcon className="h-4 w-4" />
          {isSaving ? "Se salvează..." : "Salvează meniul"}
        </Button>
      </div>

      {error ? <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div> : null}

      <Card className="rounded-lg">
        <CardContent className="space-y-6 p-6">
          <div>
            <Label>Selectează limba</Label>
            <div className="mt-2 flex flex-wrap gap-2">
              {LOCALES.map((locale) => (
                <button
                  key={locale}
                  type="button"
                  onClick={() => setActiveLocale(locale)}
                  className={cn(
                    "rounded-full border px-3 py-1 text-sm font-medium uppercase transition",
                    activeLocale === locale ? "border-slate-900 bg-slate-900 text-white" : "border-slate-200 bg-white text-slate-600 hover:border-slate-400",
                  )}
                >
                  {locale}
                </button>
              ))}
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="menu-name">Nume</Label>
              <Input id="menu-name" value={form.name[activeLocale] ?? ""} onChange={(event) => updateLocalized("name", event.target.value)} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="menu-location">Locație</Label>
              <select
                id="menu-location"
                value={form.location}
                onChange={(event) => setForm((current) => ({ ...current, location: event.target.value as MenuPayload["location"] }))}
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="header">Header</option>
                <option value="footer">Footer</option>
              </select>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="menu-slug">Slug</Label>
            <Input id="menu-slug" value={form.slug ?? ""} onChange={(event) => setForm((current) => ({ ...current, slug: slugify(event.target.value) }))} />
          </div>

          <div className="space-y-2">
            <Label htmlFor="menu-description">Descriere</Label>
            <Textarea id="menu-description" value={form.description[activeLocale] ?? ""} onChange={(event) => updateLocalized("description", event.target.value)} />
          </div>

          <div className="flex items-center gap-3 rounded-lg border p-4">
            <Switch checked={form.active} onCheckedChange={(checked) => setForm((current) => ({ ...current, active: checked }))} />
            <div>
              <div className="text-sm font-medium">Meniu activ</div>
              <div className="text-xs text-slate-500">Meniurile inactive nu sunt livrate pe frontend.</div>
            </div>
          </div>
        </CardContent>
      </Card>
    </form>
  );
}
