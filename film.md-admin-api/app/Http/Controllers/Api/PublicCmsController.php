<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\MenuItemController;
use App\Models\CmsPage;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicCmsController extends ApiController
{
    public function page(Request $request, string $slug): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $page = CmsPage::query()
            ->published()
            ->get()
            ->first(fn (CmsPage $page) => in_array($slug, $page->getTranslations('slug'), true));

        if (! $page) {
            return response()->json(['message' => 'Pagina nu a fost găsită.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'id' => $page->id,
            'title' => $this->translated($page, 'title', $locale),
            'slug' => $this->translated($page, 'slug', $locale),
            'excerpt' => $this->translated($page, 'excerpt', $locale),
            'content' => $this->translated($page, 'content', $locale),
            'meta_title' => $this->translated($page, 'meta_title', $locale),
            'meta_description' => $this->translated($page, 'meta_description', $locale),
            'meta_keywords' => $this->translated($page, 'meta_keywords', $locale),
            'canonical_url' => $page->canonical_url,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]);
    }

    public function menu(Request $request, string $location): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $menus = Menu::query()
            ->with(['items.page', 'items.content'])
            ->where('location', $location)
            ->where('active', true)
            ->orderBy('id')
            ->get();
        if ($location !== Menu::LOCATION_FOOTER) {
            $menus = $menus->take(1);
        }

        if ($menus->isEmpty()) {
            return response()->json(['menu' => null, 'menus' => [], 'items' => []]);
        }

        $items = $menus
            ->flatMap(fn (Menu $menu) => $this->publicItems($menu, $locale))
            ->values();
        $menuData = $menus
            ->map(fn (Menu $menu) => $this->menuData($menu, $locale))
            ->values();

        $menu = $menus->first();

        return response()->json([
            'menu' => $menu ? $this->menuData($menu, $locale) : null,
            'menus' => $menuData,
            'items' => $items,
        ]);
    }

    private function publicItems(Menu $menu, string $locale)
    {
        return $menu->items
            ->filter(fn (MenuItem $item) => MenuItemController::isPubliclyVisible($item))
            ->filter(function (MenuItem $item) use ($menu): bool {
                $parentId = $item->parent_id;

                while ($parentId !== null) {
                    $parent = $menu->items->firstWhere('id', $parentId);
                    if (! $parent || ! MenuItemController::isPubliclyVisible($parent)) {
                        return false;
                    }
                    $parentId = $parent->parent_id;
                }

                return true;
            })
            ->map(fn (MenuItem $item) => MenuItemController::itemData($item, $locale));
    }

    private function menuData(Menu $menu, string $locale): array
    {
        return [
            'id' => $menu->id,
            'name' => $menu->getTranslation('name', $locale, false)
                ?: $menu->getTranslation('name', Taxonomy::LOCALE_RO, false),
            'slug' => $menu->slug,
            'location' => $menu->location,
        ];
    }

    private function translated(CmsPage $page, string $field, string $locale): string
    {
        return $page->getTranslation($field, $locale, false)
            ?: $page->getTranslation($field, Taxonomy::LOCALE_RO, false)
            ?: collect($page->getTranslations($field))->filter()->first()
            ?: '';
    }

    private function resolveLocale(Request $request): string
    {
        $locale = (string) $request->query('locale', Taxonomy::LOCALE_RO);

        return in_array($locale, Taxonomy::supportedLocales(), true) ? $locale : Taxonomy::LOCALE_RO;
    }
}
