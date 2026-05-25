import { useEffect, useMemo, useState } from "react";
import { ArrowLeftIcon, DatabaseIcon, SaveIcon, SearchIcon } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { CKEditor } from "@ckeditor/ckeditor5-react";
import {
  Alignment,
  AutoImage,
  BlockQuote,
  Bold,
  ClassicEditor,
  Essentials,
  Font,
  GeneralHtmlSupport,
  Heading,
  Image,
  ImageCaption,
  ImageInsert,
  ImageResize,
  ImageStyle,
  ImageToolbar,
  ImageUpload,
  Italic,
  Link,
  List,
  MediaEmbed,
  Paragraph,
  PasteFromOffice,
  SourceEditing,
  Table,
  TableToolbar,
  Underline,
} from "ckeditor5";
import "ckeditor5/ckeditor5.css";
import { adminApi, CmsPagePayload, LocaleMap } from "../lib/api";
import { Button } from "../components/ui/button";
import { Card, CardContent } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Textarea } from "../components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../components/ui/tabs";
import { cn } from "../lib/utils";

const LOCALES = ["ro", "ru", "en"];

type EditorForm = CmsPagePayload;

type UploadLoader = {
  file: Promise<File>;
};

class CmsUploadAdapter {
  constructor(private readonly loader: UploadLoader) {}

  async upload() {
    const file = await this.loader.file;
    const response = await adminApi.uploadCmsImage(file);
    return { default: response.url };
  }

  abort() {}
}

function CmsUploadPlugin(editor: { plugins: { get: (name: string) => { createUploadAdapter?: (loader: UploadLoader) => CmsUploadAdapter } } }) {
  editor.plugins.get("FileRepository").createUploadAdapter = (loader: UploadLoader) => new CmsUploadAdapter(loader);
}

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

const initialForm: EditorForm = {
  title: emptyLocaleMap(),
  slug: emptyLocaleMap(),
  status: "unpublished",
  excerpt: emptyLocaleMap(),
  content: emptyLocaleMap(),
  meta_title: emptyLocaleMap(),
  meta_description: emptyLocaleMap(),
  meta_keywords: emptyLocaleMap(),
  canonical_url: "",
};

export function CMSPageEditor({ pageId }: { pageId?: string }) {
  const navigate = useNavigate();
  const isNew = !pageId || pageId === "new";
  const [activeLocale, setActiveLocale] = useState("ro");
  const [form, setForm] = useState<EditorForm>(initialForm);
  const [isLoading, setIsLoading] = useState(!isNew);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const editorConfig = useMemo(
    () => ({
      licenseKey: "GPL",
      extraPlugins: [CmsUploadPlugin],
      plugins: [
        Essentials,
        Paragraph,
        Heading,
        Bold,
        Italic,
        Underline,
        Font,
        Alignment,
        List,
        Link,
        BlockQuote,
        Table,
        TableToolbar,
        MediaEmbed,
        Image,
        ImageToolbar,
        ImageCaption,
        ImageStyle,
        ImageResize,
        ImageUpload,
        ImageInsert,
        AutoImage,
        SourceEditing,
        GeneralHtmlSupport,
        PasteFromOffice,
      ],
      toolbar: {
        items: [
          "undo",
          "redo",
          "|",
          "heading",
          "|",
          "fontSize",
          "fontColor",
          "fontBackgroundColor",
          "|",
          "bold",
          "italic",
          "underline",
          "|",
          "link",
          "insertImage",
          "mediaEmbed",
          "insertTable",
          "blockQuote",
          "|",
          "alignment",
          "bulletedList",
          "numberedList",
          "|",
          "sourceEditing",
        ],
        shouldNotGroupWhenFull: true,
      },
      image: {
        toolbar: ["imageTextAlternative", "toggleImageCaption", "imageStyle:inline", "imageStyle:block", "imageStyle:side", "resizeImage"],
      },
      table: {
        contentToolbar: ["tableColumn", "tableRow", "mergeTableCells"],
      },
    }),
    [],
  );

  useEffect(() => {
    if (isNew || !pageId) {
      return;
    }

    const loadPage = async () => {
      try {
        setIsLoading(true);
        const response = await adminApi.getPage(Number(pageId));
        setForm({
          title: normalizeLocaleMap(response.page.title_translations),
          slug: normalizeLocaleMap(response.page.slug_translations),
          status: response.page.status,
          excerpt: normalizeLocaleMap(response.page.excerpt_translations),
          content: normalizeLocaleMap(response.page.content_translations),
          meta_title: normalizeLocaleMap(response.page.meta_title_translations),
          meta_description: normalizeLocaleMap(response.page.meta_description_translations),
          meta_keywords: normalizeLocaleMap(response.page.meta_keywords_translations),
          canonical_url: response.page.canonical_url ?? "",
        });
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca pagina.");
      } finally {
        setIsLoading(false);
      }
    };

    void loadPage();
  }, [isNew, pageId]);

  const updateLocalized = (field: keyof Pick<EditorForm, "title" | "slug" | "excerpt" | "content" | "meta_title" | "meta_description" | "meta_keywords">, value: string) => {
    setForm((current) => {
      const next = {
        ...current,
        [field]: { ...current[field], [activeLocale]: value },
      };

      if (field === "title" && isNew && !current.slug[activeLocale]) {
        next.slug = { ...next.slug, [activeLocale]: slugify(value) };
      }

      return next;
    });
  };

  const savePage = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);

    if (!LOCALES.some((locale) => form.title[locale]?.trim())) {
      setError("Completează titlul în cel puțin o limbă.");
      return;
    }

    try {
      setIsSaving(true);
      if (isNew) {
        const response = await adminApi.createPage(form);
        setSuccess("Pagina a fost creată.");
        navigate(`/cms/${response.page.id}`, { replace: true });
      } else if (pageId) {
        await adminApi.updatePage(Number(pageId), form);
        setSuccess("Pagina a fost salvată.");
      }
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut salva pagina.");
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return <div className="rounded-lg border bg-white p-6 text-sm text-slate-500">Se încarcă pagina...</div>;
  }

  return (
    <form className="w-full space-y-6" onSubmit={savePage}>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="page-header">
          <button type="button" onClick={() => navigate("/cms")} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900">
            <ArrowLeftIcon className="h-4 w-4" />
            Înapoi la pagini
          </button>
          <h1 className="page-title">{isNew ? "Creează pagină" : "Editează pagina"}</h1>
        </div>

        <Button type="submit" disabled={isSaving}>
          <SaveIcon className="h-4 w-4" />
          {isSaving ? "Se salvează..." : "Salvează pagina"}
        </Button>
      </div>

      {error ? <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div> : null}
      {success ? <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{success}</div> : null}

      <Tabs defaultValue="data">
        <TabsList>
          <TabsTrigger value="data">
            <DatabaseIcon className="mr-2 h-4 w-4" />
            DATA
          </TabsTrigger>
          <TabsTrigger value="seo">
            <SearchIcon className="mr-2 h-4 w-4" />
            SEO
          </TabsTrigger>
        </TabsList>

        <Card className="mt-4 rounded-lg">
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

            <TabsContent value="data" className="mt-0 space-y-5">
              <div className="grid gap-4 md:grid-cols-[1fr_220px]">
                <div className="space-y-2">
                  <Label htmlFor="page-title">Titlu</Label>
                  <Input id="page-title" value={form.title[activeLocale] ?? ""} onChange={(event) => updateLocalized("title", event.target.value)} placeholder="Titlul paginii" />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="page-status">Status</Label>
                  <select
                    id="page-status"
                    value={form.status}
                    onChange={(event) => setForm((current) => ({ ...current, status: event.target.value as EditorForm["status"] }))}
                    className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    <option value="published">Published</option>
                    <option value="unpublished">Unpublished</option>
                  </select>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="page-slug">Slug</Label>
                <Input id="page-slug" value={form.slug[activeLocale] ?? ""} onChange={(event) => updateLocalized("slug", slugify(event.target.value))} placeholder="slug-pagina" />
                <p className="text-xs text-slate-500">URL public: /page/{form.slug[activeLocale] || "slug-pagina"}</p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="page-excerpt">Descriere scurtă</Label>
                <Textarea id="page-excerpt" value={form.excerpt[activeLocale] ?? ""} onChange={(event) => updateLocalized("excerpt", event.target.value)} />
              </div>

              <div className="space-y-2">
                <Label>Conținut</Label>
                <div className="cms-editor min-h-[520px] rounded-lg border bg-white">
                  <CKEditor
                    key={activeLocale}
                    editor={ClassicEditor}
                    config={editorConfig}
                    data={form.content[activeLocale] ?? ""}
                    onChange={(_, editor) => updateLocalized("content", editor.getData())}
                  />
                </div>
              </div>
            </TabsContent>

            <TabsContent value="seo" className="mt-0 space-y-5">
              <div className="space-y-2">
                <Label htmlFor="meta-title">Meta title</Label>
                <Input id="meta-title" value={form.meta_title[activeLocale] ?? ""} onChange={(event) => updateLocalized("meta_title", event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="meta-description">Meta description</Label>
                <Textarea id="meta-description" value={form.meta_description[activeLocale] ?? ""} onChange={(event) => updateLocalized("meta_description", event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="meta-keywords">Meta keywords</Label>
                <Textarea id="meta-keywords" value={form.meta_keywords[activeLocale] ?? ""} onChange={(event) => updateLocalized("meta_keywords", event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="canonical-url">Canonical URL</Label>
                <Input id="canonical-url" value={form.canonical_url ?? ""} onChange={(event) => setForm((current) => ({ ...current, canonical_url: event.target.value }))} placeholder="https://filmoteca.md/page/..." />
              </div>
            </TabsContent>
          </CardContent>
        </Card>
      </Tabs>
    </form>
  );
}
