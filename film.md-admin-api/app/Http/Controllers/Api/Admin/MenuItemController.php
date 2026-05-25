<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\CmsPage;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Taxonomy;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class MenuItemController extends ApiController
{
    public function store(Request $request, Menu $menu): JsonResponse
    {
        $item = $menu->items()->create($this->validatedPayload($request, $menu));

        return response()->json([
            'item' => self::itemData($item->fresh(['page', 'content']), $this->adminLocale()),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, Menu $menu, MenuItem $item): JsonResponse
    {
        abort_if($item->menu_id !== $menu->id, Response::HTTP_NOT_FOUND);

        $item->fill($this->validatedPayload($request, $menu, $item))->save();
        $this->updateChildrenDepth($item);

        return response()->json([
            'item' => self::itemData($item->fresh(['page', 'content']), $this->adminLocale()),
        ]);
    }

    public function destroy(Menu $menu, MenuItem $item): JsonResponse
    {
        abort_if($item->menu_id !== $menu->id, Response::HTTP_NOT_FOUND);

        $children = $item->children()->get();
        foreach ($children as $child) {
            $child->parent_id = $item->parent_id;
            $child->depth = max(0, $item->depth);
            $child->save();
            $this->updateChildrenDepth($child);
        }

        $item->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    public function reorder(Request $request, Menu $menu): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
            'items.*.depth' => ['required', 'integer', 'min:0', 'max:' . MenuItem::MAX_DEPTH],
        ]);

        $ids = collect($validated['items'])->pluck('id')->all();
        $menuItems = MenuItem::query()->where('menu_id', $menu->id)->get()->keyBy('id');
        abort_if($menuItems->count() !== count($ids), Response::HTTP_UNPROCESSABLE_ENTITY, 'Trimite ordinea completă a meniului.');
        abort_if($menuItems->keys()->diff($ids)->isNotEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Unele item-uri lipsesc din reorder.');

        $rows = collect($validated['items'])->keyBy('id');
        $this->assertValidTree($rows, $menuItems);

        foreach ($validated['items'] as $row) {
            $item = $menuItems->get($row['id']);
            $item->forceFill([
                'parent_id' => $row['parent_id'] ?? null,
                'sort_order' => $row['sort_order'],
                'depth' => $row['depth'],
            ])->save();
        }

        return response()->json(['items' => $menu->fresh(['items.page', 'items.content'])->items->map(
            fn (MenuItem $item) => self::itemData($item, $this->adminLocale()),
        )->values()]);
    }

    public static function itemData(MenuItem $item, string $locale): array
    {
        return [
            'id' => $item->id,
            'menu_id' => $item->menu_id,
            'parent_id' => $item->parent_id,
            'title' => $item->getTranslations('title'),
            'label' => self::resolvedTitle($item, $locale),
            'type' => $item->type,
            'cms_page_id' => $item->cms_page_id,
            'content_id' => $item->content_id,
            'url' => $item->url,
            'resolved_url' => self::resolvedUrl($item, $locale),
            'target' => $item->target,
            'active' => $item->active,
            'public_visible' => self::isPubliclyVisible($item),
            'nestable' => $item->nestable,
            'sort_order' => $item->sort_order,
            'depth' => $item->depth,
        ];
    }

    public static function resolvedTitle(MenuItem $item, string $locale): string
    {
        $customTitle = $item->getTranslation('title', $locale, false)
            ?: $item->getTranslation('title', Taxonomy::LOCALE_RO, false)
            ?: collect($item->getTranslations('title'))->filter()->first();

        if ($customTitle) {
            return $customTitle;
        }

        if ($item->type === MenuItem::TYPE_PAGE && $item->page) {
            return $item->page->getTranslation('title', $locale, false)
                ?: $item->page->getTranslation('title', Taxonomy::LOCALE_RO, false)
                ?: collect($item->page->getTranslations('title'))->filter()->first()
                ?: '';
        }

        if ($item->type === MenuItem::TYPE_CONTENT && $item->content) {
            return $item->content->getTranslation('title', $locale, false)
                ?: $item->content->getTranslation('title', Taxonomy::LOCALE_RO, false)
                ?: $item->content->original_title;
        }

        return $item->url ?: 'Item';
    }

    public static function resolvedUrl(MenuItem $item, string $locale): string
    {
        if ($item->type === MenuItem::TYPE_PAGE && $item->page) {
            $slug = $item->page->getTranslation('slug', $locale, false)
                ?: $item->page->getTranslation('slug', Taxonomy::LOCALE_RO, false);

            return '/page/' . ltrim((string) $slug, '/');
        }

        if ($item->type === MenuItem::TYPE_CONTENT && $item->content) {
            return '/movie/' . $item->content->slug;
        }

        return $item->url ?: '#';
    }

    public static function isPubliclyVisible(MenuItem $item): bool
    {
        if (! $item->active) {
            return false;
        }

        if ($item->type === MenuItem::TYPE_PAGE) {
            return $item->page?->status === CmsPage::STATUS_PUBLISHED;
        }

        if ($item->type === MenuItem::TYPE_CONTENT) {
            return $item->content?->status === Content::STATUS_PUBLISHED;
        }

        return trim((string) $item->url) !== '';
    }

    private function validatedPayload(Request $request, Menu $menu, ?MenuItem $item = null): array
    {
        $request->validate([
            'title' => ['nullable', 'array'],
            'type' => ['required', Rule::in(array_keys(MenuItem::types()))],
            'cms_page_id' => ['nullable', 'integer', Rule::exists('cms_pages', 'id')],
            'content_id' => ['nullable', 'integer', Rule::exists('contents', 'id')],
            'url' => ['nullable', 'string', 'max:2048'],
            'target' => ['required', Rule::in(['_self', '_blank'])],
            'active' => ['boolean'],
            'nestable' => ['boolean'],
            'parent_id' => ['nullable', 'integer', Rule::exists('menu_items', 'id')->where('menu_id', $menu->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $type = $request->input('type');
        if ($type === MenuItem::TYPE_PAGE && ! $request->integer('cms_page_id')) {
            abort(response()->json(['message' => 'Selectează o pagină.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        if ($type === MenuItem::TYPE_CONTENT && ! $request->integer('content_id')) {
            abort(response()->json(['message' => 'Selectează un film.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        if ($type === MenuItem::TYPE_CUSTOM && ! trim((string) $request->input('url'))) {
            abort(response()->json(['message' => 'Introdu un URL custom.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        if ($item && $request->integer('parent_id') === $item->id) {
            abort(response()->json(['message' => 'Un item nu poate fi propriul părinte.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $parent = $request->integer('parent_id') ? MenuItem::query()->find($request->integer('parent_id')) : null;
        if ($parent && ! $parent->nestable) {
            abort(response()->json(['message' => 'Itemul părinte nu permite copii.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        if ($item && $parent && $this->isDescendant($item, $parent)) {
            abort(response()->json(['message' => 'Nu poți muta un item sub propriul copil.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        if ($parent && $parent->depth + 1 > MenuItem::MAX_DEPTH) {
            abort(response()->json(['message' => 'Ai depășit adâncimea maximă permisă pentru meniu.'], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $sortOrder = $request->has('sort_order')
            ? (int) $request->input('sort_order')
            : ($item?->sort_order ?? $this->nextSortOrder($menu, $parent?->id));

        return [
            'parent_id' => $parent?->id,
            'title' => $this->localized($request->input('title', [])),
            'type' => $type,
            'cms_page_id' => $type === MenuItem::TYPE_PAGE ? $request->integer('cms_page_id') : null,
            'content_id' => $type === MenuItem::TYPE_CONTENT ? $request->integer('content_id') : null,
            'url' => $type === MenuItem::TYPE_CUSTOM ? trim((string) $request->input('url')) : null,
            'target' => $request->input('target', '_self'),
            'active' => (bool) $request->boolean('active', true),
            'nestable' => (bool) $request->boolean('nestable', false),
            'sort_order' => $sortOrder,
            'depth' => $parent ? $parent->depth + 1 : 0,
        ];
    }

    private function nextSortOrder(Menu $menu, ?int $parentId): int
    {
        $query = $menu->items()->where('parent_id', $parentId);
        if ($parentId === null) {
            $query = $menu->items()->whereNull('parent_id');
        }

        return ((int) $query->max('sort_order')) + 1;
    }

    private function updateChildrenDepth(MenuItem $item): void
    {
        $item->load('children');
        foreach ($item->children as $child) {
            $child->depth = $item->depth + 1;
            $child->save();
            $this->updateChildrenDepth($child);
        }
    }

    private function assertValidTree(Collection $rows, Collection $menuItems): void
    {
        foreach ($rows as $id => $row) {
            $parentId = $row['parent_id'] ?? null;

            if ($parentId === null) {
                if ((int) $row['depth'] !== 0) {
                    abort(response()->json(['message' => 'Itemii de nivel principal trebuie să aibă depth 0.'], Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                continue;
            }

            if ((int) $parentId === (int) $id) {
                abort(response()->json(['message' => 'Un item nu poate fi propriul părinte.'], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $parent = $menuItems->get($parentId);
            if (! $parent) {
                abort(response()->json(['message' => 'Itemul părinte nu aparține acestui meniu.'], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if (! $parent->nestable) {
                abort(response()->json(['message' => 'Un item a fost mutat sub un părinte care nu permite copii.'], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $visited = [(int) $id => true];
            $depth = 0;
            $cursor = $parentId;
            while ($cursor !== null) {
                if (isset($visited[(int) $cursor])) {
                    abort(response()->json(['message' => 'Structura meniului conține un ciclu.'], Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                $visited[(int) $cursor] = true;
                $depth++;

                if ($depth > MenuItem::MAX_DEPTH) {
                    abort(response()->json(['message' => 'Ai depășit adâncimea maximă permisă pentru meniu.'], Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                $cursor = $rows->get($cursor)['parent_id'] ?? null;
            }

            if ((int) $row['depth'] !== $depth) {
                abort(response()->json(['message' => 'Nivelul itemilor nu corespunde structurii trimise.'], Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }
    }

    private function isDescendant(MenuItem $item, MenuItem $potentialChild): bool
    {
        $cursor = $potentialChild->parent_id;

        while ($cursor !== null) {
            if ((int) $cursor === (int) $item->id) {
                return true;
            }

            $cursor = MenuItem::query()->whereKey($cursor)->value('parent_id');
        }

        return false;
    }

    private function localized(array $values): array
    {
        return collect(Taxonomy::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($values[$locale] ?? ''))])
            ->all();
    }

    private function adminLocale(): string
    {
        return request()->user()?->preferred_locale ?? Taxonomy::LOCALE_RO;
    }
}
