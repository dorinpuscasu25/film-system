import { useEffect, useMemo, useState } from "react";
import { ArrowLeftIcon, EditIcon, GripVerticalIcon, PlusIcon, TrashIcon } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { adminApi, LocaleMap, Menu, MenuItem, MenuItemPayload, MenuOptions } from "../lib/api";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "../components/ui/card";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Modal } from "../components/shared/Modal";
import { Switch } from "../components/ui/switch";
import { cn } from "../lib/utils";

const LOCALES = ["ro", "ru", "en"];
const MAX_MENU_DEPTH = 3;

type MenuItemNode = MenuItem & { children: MenuItemNode[] };

function emptyLocaleMap(): LocaleMap {
  return { ro: "", ru: "", en: "" };
}

function normalizeLocaleMap(value?: LocaleMap): LocaleMap {
  return LOCALES.reduce<LocaleMap>((carry, locale) => {
    carry[locale] = value?.[locale] ?? "";
    return carry;
  }, {});
}

function buildTree(items: MenuItem[]): MenuItemNode[] {
  const nodes = new Map<number, MenuItemNode>();
  const roots: MenuItemNode[] = [];

  items.forEach((item) => nodes.set(item.id, { ...item, children: [] }));
  items.forEach((item) => {
    const node = nodes.get(item.id);
    if (!node) {
      return;
    }
    if (item.parent_id && nodes.has(item.parent_id)) {
      nodes.get(item.parent_id)?.children.push(node);
    } else {
      roots.push(node);
    }
  });

  const sortNodes = (nodesToSort: MenuItemNode[]) => {
    nodesToSort.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    nodesToSort.forEach((node) => sortNodes(node.children));
  };

  sortNodes(roots);
  return roots;
}

function isDescendant(items: MenuItem[], draggedId: number, targetId: number) {
  let parentId = items.find((item) => item.id === targetId)?.parent_id ?? null;
  while (parentId) {
    if (parentId === draggedId) {
      return true;
    }
    parentId = items.find((item) => item.id === parentId)?.parent_id ?? null;
  }
  return false;
}

function normalizeOrders(items: MenuItem[]) {
  const byParent = new Map<number | null, MenuItem[]>();
  items.forEach((item) => {
    const key = item.parent_id ?? null;
    byParent.set(key, [...(byParent.get(key) ?? []), item]);
  });

  byParent.forEach((siblings) => {
    siblings
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
      .forEach((item, index) => {
        item.sort_order = index;
      });
  });

  const updateDepth = (parentId: number | null, depth: number) => {
    (byParent.get(parentId) ?? []).forEach((item) => {
      item.depth = depth;
      updateDepth(item.id, depth + 1);
    });
  };

  updateDepth(null, 0);
  return items;
}

function ItemModal({
  isOpen,
  onClose,
  onSave,
  options,
  item,
}: {
  isOpen: boolean;
  onClose: () => void;
  onSave: (payload: MenuItemPayload, itemId?: number) => Promise<void>;
  options: MenuOptions | null;
  item: MenuItem | null;
}) {
  const [activeLocale, setActiveLocale] = useState("ro");
  const [form, setForm] = useState<MenuItemPayload>({
    title: emptyLocaleMap(),
    type: "page",
    cms_page_id: null,
    content_id: null,
    url: "",
    target: "_self",
    active: true,
    nestable: false,
  });
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    setError(null);
    setForm(
      item
        ? {
            title: normalizeLocaleMap(item.title),
            type: item.type,
            cms_page_id: item.cms_page_id,
            content_id: item.content_id,
            url: item.url ?? "",
            target: item.target,
            active: item.active,
            nestable: item.nestable,
            parent_id: item.parent_id,
            sort_order: item.sort_order,
          }
        : {
            title: emptyLocaleMap(),
            type: "page",
            cms_page_id: options?.pages[0]?.id ?? null,
            content_id: null,
            url: "",
            target: "_self",
            active: true,
            nestable: false,
          },
    );
  }, [isOpen, item, options]);

  const submit = async () => {
    if (form.type === "page" && !form.cms_page_id) {
      setError("Nu există pagini disponibile pentru acest item.");
      return;
    }
    if (form.type === "content" && !form.content_id) {
      setError("Nu există filme disponibile pentru acest item.");
      return;
    }
    if (form.type === "custom" && !form.url?.trim()) {
      setError("Introdu URL-ul custom.");
      return;
    }
    if (form.type === "custom" && !LOCALES.some((locale) => form.title[locale]?.trim())) {
      setError("Pentru URL custom, numele itemului este obligatoriu.");
      return;
    }

    try {
      setIsSaving(true);
      await onSave(form, item?.id);
      onClose();
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Nu am putut salva itemul.");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={item ? "Editează item meniu" : "Adaugă item meniu"}
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose}>
            Anulează
          </Button>
          <Button type="button" onClick={() => void submit()} disabled={isSaving}>
            {isSaving ? "Se salvează..." : item ? "Salvează item" : "Adaugă item"}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {error ? <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div> : null}

        <div>
          <Label>Limba pentru nume custom</Label>
          <div className="mt-2 flex flex-wrap gap-2">
            {LOCALES.map((locale) => (
              <button
                key={locale}
                type="button"
                onClick={() => setActiveLocale(locale)}
                className={cn("rounded-full border px-3 py-1 text-sm font-medium uppercase", activeLocale === locale ? "border-slate-900 bg-slate-900 text-white" : "border-slate-200 bg-white text-slate-600")}
              >
                {locale}
              </button>
            ))}
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="item-type">Tip</Label>
            <select
              id="item-type"
              value={form.type}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  type: event.target.value as MenuItemPayload["type"],
                  cms_page_id: event.target.value === "page" ? options?.pages[0]?.id ?? null : null,
                  content_id: event.target.value === "content" ? options?.contents[0]?.id ?? null : null,
                  url: event.target.value === "custom" ? current.url : "",
                }))
              }
              className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="page">Pagina</option>
              <option value="content">Filme</option>
              <option value="custom">Custom URL</option>
            </select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="item-target">Deschidere</Label>
            <select
              id="item-target"
              value={form.target}
              onChange={(event) => setForm((current) => ({ ...current, target: event.target.value as MenuItemPayload["target"] }))}
              className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="_self">Same tab</option>
              <option value="_blank">New tab</option>
            </select>
          </div>
        </div>

        {form.type === "page" ? (
          <div className="space-y-2">
            <Label htmlFor="item-page">Pagina</Label>
            {(options?.pages ?? []).length > 0 ? (
              <select
                id="item-page"
                value={form.cms_page_id ?? ""}
                onChange={(event) => setForm((current) => ({ ...current, cms_page_id: Number(event.target.value) }))}
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                {(options?.pages ?? []).map((page) => (
                  <option key={page.id} value={page.id}>
                    {page.title} ({page.status})
                  </option>
                ))}
              </select>
            ) : (
              <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Creează o pagină înainte să adaugi un item de tip Pagina.</div>
            )}
          </div>
        ) : null}

        {form.type === "content" ? (
          <div className="space-y-2">
            <Label htmlFor="item-content">Film</Label>
            {(options?.contents ?? []).length > 0 ? (
              <select
                id="item-content"
                value={form.content_id ?? ""}
                onChange={(event) => setForm((current) => ({ ...current, content_id: Number(event.target.value) }))}
                className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                {(options?.contents ?? []).map((content) => (
                  <option key={content.id} value={content.id}>
                    {content.title} ({content.status})
                  </option>
                ))}
              </select>
            ) : (
              <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Adaugă un film înainte să creezi un item de tip Filme.</div>
            )}
          </div>
        ) : null}

        {form.type === "custom" ? (
          <div className="space-y-2">
            <Label htmlFor="item-url">URL</Label>
            <Input id="item-url" value={form.url ?? ""} onChange={(event) => setForm((current) => ({ ...current, url: event.target.value }))} placeholder="https://... sau /search" />
          </div>
        ) : null}

        <div className="space-y-2">
          <Label htmlFor="item-title">Nume custom</Label>
          <Input
            id="item-title"
            value={form.title[activeLocale] ?? ""}
            onChange={(event) => setForm((current) => ({ ...current, title: { ...current.title, [activeLocale]: event.target.value } }))}
            placeholder="Lasă gol pentru numele paginii sau filmului"
          />
        </div>

        <div className="grid gap-3 md:grid-cols-2">
          <label className="flex items-center gap-3 rounded-lg border p-4">
            <Switch checked={form.active} onCheckedChange={(checked) => setForm((current) => ({ ...current, active: checked }))} />
            <span className="text-sm font-medium">Activ</span>
          </label>
          <label className="flex items-center gap-3 rounded-lg border p-4">
            <Switch checked={form.nestable} onCheckedChange={(checked) => setForm((current) => ({ ...current, nestable: checked }))} />
            <span className="text-sm font-medium">Poate avea copii</span>
          </label>
        </div>
      </div>
    </Modal>
  );
}

export function MenuDetails({ menuId }: { menuId: string }) {
  const navigate = useNavigate();
  const [menu, setMenu] = useState<Menu | null>(null);
  const [items, setItems] = useState<MenuItem[]>([]);
  const [options, setOptions] = useState<MenuOptions | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<MenuItem | null>(null);
  const [draggedId, setDraggedId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const tree = useMemo(() => buildTree(items), [items]);

  const loadMenu = async () => {
    try {
      setIsLoading(true);
      const response = await adminApi.getMenu(Number(menuId));
      setMenu(response.menu);
      setItems(response.items);
      setOptions(response.options);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Nu am putut încărca meniul.");
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    void loadMenu();
  }, [menuId]);

  const persistOrder = async (nextItems: MenuItem[]) => {
    setItems(nextItems);
    await adminApi.reorderMenuItems(
      Number(menuId),
      nextItems.map((item) => ({
        id: item.id,
        parent_id: item.parent_id,
        sort_order: item.sort_order,
        depth: item.depth,
      })),
    );
  };

  const moveItem = async (targetId: number, mode: "before" | "after" | "child") => {
    if (!draggedId || draggedId === targetId || isDescendant(items, draggedId, targetId)) {
      return;
    }

    const nextItems = items.map((item) => ({ ...item }));
    const dragged = nextItems.find((item) => item.id === draggedId);
    const target = nextItems.find((item) => item.id === targetId);
    if (!dragged || !target) {
      return;
    }

    if (mode === "child") {
      if (!target.nestable) {
        setError("Itemul țintă nu permite copii.");
        return;
      }
      if (target.depth + 1 > MAX_MENU_DEPTH) {
        setError(`Meniul permite maximum ${MAX_MENU_DEPTH + 1} niveluri.`);
        return;
      }
      setError(null);
      dragged.parent_id = target.id;
      dragged.sort_order = Math.max(0, ...nextItems.filter((item) => item.parent_id === dragged.parent_id).map((item) => item.sort_order)) + 1;
    } else {
      setError(null);
      dragged.parent_id = target.parent_id;
      dragged.sort_order = mode === "before" ? target.sort_order - 1 : target.sort_order + 1;
    }

    await persistOrder(normalizeOrders(nextItems));
    setDraggedId(null);
  };

  const saveItem = async (payload: MenuItemPayload, itemId?: number) => {
    if (itemId) {
      await adminApi.updateMenuItem(Number(menuId), itemId, payload);
    } else {
      await adminApi.createMenuItem(Number(menuId), payload);
    }
    await loadMenu();
  };

  const deleteItem = async (item: MenuItem) => {
    if (!confirm(`Ștergi itemul "${item.label}"?`)) {
      return;
    }
    await adminApi.deleteMenuItem(Number(menuId), item.id);
    await loadMenu();
  };

  const renderItem = (item: MenuItemNode) => (
    <div key={item.id} className="space-y-2">
      <div
        draggable
        onDragStart={() => setDraggedId(item.id)}
        onDragOver={(event) => event.preventDefault()}
        onDrop={(event) => {
          event.preventDefault();
          const rect = event.currentTarget.getBoundingClientRect();
          const y = (event.clientY - rect.top) / rect.height;
          const x = event.clientX - rect.left;
          const mode = item.nestable && item.depth < MAX_MENU_DEPTH && x > 90 && y > 0.25 && y < 0.75 ? "child" : y < 0.5 ? "before" : "after";
          void moveItem(item.id, mode);
        }}
        className={cn("flex items-center gap-3 rounded-lg border bg-white p-3 transition hover:border-slate-300", draggedId === item.id && "opacity-50")}
        style={{ marginLeft: item.depth * 24 }}
      >
        <GripVerticalIcon className="h-4 w-4 cursor-grab text-slate-400" />
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold text-slate-900">{item.label}</div>
          <div className="truncate text-xs text-slate-500">
            {item.type === "page" ? "Pagina" : item.type === "content" ? "Filme" : "Custom URL"}: {item.resolved_url}
          </div>
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          <span className={item.active ? "rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700" : "rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-500"}>
            {item.active ? "Activ" : "Inactiv"}
          </span>
          {!item.public_visible ? (
            <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">Ascuns public</span>
          ) : null}
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          onClick={() => {
            setEditingItem(item);
            setIsModalOpen(true);
          }}
        >
          <EditIcon className="h-4 w-4" />
        </Button>
        <Button type="button" variant="ghost" size="icon" onClick={() => void deleteItem(item)}>
          <TrashIcon className="h-4 w-4 text-red-500" />
        </Button>
      </div>
      {item.children.map(renderItem)}
    </div>
  );

  const renderPreviewItem = (item: MenuItemNode) => (
    <li key={item.id}>
      <a
        href={item.resolved_url}
        target={item.target === "_blank" ? "_blank" : undefined}
        rel="noreferrer"
        className={cn(
          "inline-flex items-center rounded-md px-3 py-2 text-sm font-medium",
          item.public_visible ? "text-slate-700 hover:bg-slate-100 hover:text-slate-950" : "text-slate-400 line-through",
        )}
      >
        {item.label}
      </a>
      {item.children.length > 0 ? (
        <ul className="ml-4 border-l border-slate-200 pl-3">{item.children.map(renderPreviewItem)}</ul>
      ) : null}
    </li>
  );

  if (isLoading) {
    return <div className="rounded-lg border bg-white p-6 text-sm text-slate-500">Se încarcă meniul...</div>;
  }

  return (
    <div className="w-full space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="page-header">
          <button type="button" onClick={() => navigate("/menus")} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900">
            <ArrowLeftIcon className="h-4 w-4" />
            Înapoi la meniuri
          </button>
          <h1 className="page-title">Detalii meniu</h1>
        </div>
        <Button onClick={() => navigate(`/menus/${menuId}/edit`)}>
          <EditIcon className="h-4 w-4" />
          Editează meniu
        </Button>
      </div>

      {error ? <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div> : null}

      {menu ? (
        <Card className="rounded-lg">
          <CardHeader>
            <CardTitle className="text-xl">Informații meniu</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-5 md:grid-cols-4">
            <div>
              <div className="text-xs font-medium uppercase text-slate-500">Nume</div>
              <div className="mt-1 text-sm font-semibold text-slate-900">{menu.name}</div>
            </div>
            <div>
              <div className="text-xs font-medium uppercase text-slate-500">Slug</div>
              <div className="mt-1 text-sm text-slate-700">{menu.slug}</div>
            </div>
            <div>
              <div className="text-xs font-medium uppercase text-slate-500">Locație</div>
              <div className="mt-1 text-sm text-slate-700">{menu.location_label}</div>
            </div>
            <div>
              <div className="text-xs font-medium uppercase text-slate-500">Status</div>
              <div className="mt-1">
                <span className={menu.active ? "rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800" : "rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700"}>
                  {menu.active ? "Activ" : "Inactiv"}
                </span>
              </div>
            </div>
          </CardContent>
        </Card>
      ) : null}

      {tree.length > 0 ? (
        <Card className="rounded-lg">
          <CardHeader>
            <CardTitle className="text-xl">Preview structură</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="rounded-lg border bg-slate-50 p-4">
              <ul className="flex flex-col gap-1 md:flex-row md:flex-wrap md:items-start">{tree.map(renderPreviewItem)}</ul>
            </div>
            <p className="mt-3 text-xs text-slate-500">Itemii marcați ca ascunși public nu vor fi livrați în header/footer până când target-ul lor este publicat și itemul este activ.</p>
          </CardContent>
        </Card>
      ) : null}

      <Card className="rounded-lg">
        <CardHeader className="flex-row items-center justify-between space-y-0">
          <CardTitle className="text-xl">Itemi meniu</CardTitle>
          <Button
            onClick={() => {
              setEditingItem(null);
              setIsModalOpen(true);
            }}
          >
            <PlusIcon className="h-4 w-4" />
            Adaugă item
          </Button>
        </CardHeader>
        <CardContent>
          {items.length === 0 ? (
            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-slate-500">Nu există itemi în acest meniu.</div>
          ) : (
            <div className="space-y-2">{tree.map(renderItem)}</div>
          )}
        </CardContent>
      </Card>

      <ItemModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSave={saveItem} options={options} item={editingItem} />
    </div>
  );
}
