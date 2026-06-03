<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\CmsPage;
use App\Models\Menu;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class MenuController extends ApiController
{
    public function index(): JsonResponse
    {
        $locale = $this->adminLocale();
        $menus = Menu::query()
            ->withCount('items')
            ->orderBy('location')
            ->orderBy('slug')
            ->get();

        return response()->json([
            'items' => $menus->map(fn (Menu $menu) => $this->menuData($menu, $locale))->values(),
            'options' => $this->optionsData(),
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['options' => $this->optionsData()]);
    }

    public function show(Menu $menu): JsonResponse
    {
        $locale = $this->adminLocale();
        $menu->load(['items.page', 'items.content']);

        return response()->json([
            'menu' => $this->menuData($menu, $locale, true),
            'items' => $menu->items->map(fn ($item) => MenuItemController::itemData($item, $locale))->values(),
            'options' => $this->optionsData(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $menu = Menu::query()->create($this->validatedPayload($request));
        $this->ensureSingleActiveMenu($menu);

        return response()->json([
            'menu' => $this->menuData($menu->fresh(), $this->adminLocale(), true),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        $menu->fill($this->validatedPayload($request, $menu))->save();
        $this->ensureSingleActiveMenu($menu);

        return response()->json([
            'menu' => $this->menuData($menu->fresh(), $this->adminLocale(), true),
        ]);
    }

    public function destroy(Menu $menu): JsonResponse
    {
        $menu->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    private function validatedPayload(Request $request, ?Menu $menu = null): array
    {
        $request->validate([
            'name' => ['required', 'array'],
            'name.ro' => ['nullable', 'string', 'max:255'],
            'name.ru' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('menus', 'slug')->ignore($menu?->id)],
            'location' => ['required', Rule::in(array_keys(Menu::locations()))],
            'description' => ['nullable', 'array'],
            'active' => ['boolean'],
        ]);

        $name = $this->localized($request->input('name', []));
        if (collect($name)->filter()->isEmpty()) {
            abort(response()->json([
                'message' => 'Numele meniului este obligatoriu.',
                'errors' => ['name' => ['Numele meniului este obligatoriu.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $slug = trim((string) $request->input('slug'));
        if ($slug === '') {
            $slug = Str::slug($name[Taxonomy::LOCALE_RO] ?: $name[Taxonomy::LOCALE_EN] ?: collect($name)->filter()->first() ?: 'menu');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'location' => $request->input('location'),
            'description' => $this->localized($request->input('description', [])),
            'active' => (bool) $request->boolean('active', true),
        ];
    }

    private function menuData(Menu $menu, string $locale, bool $full = false): array
    {
        $data = [
            'id' => $menu->id,
            'name' => $this->translated($menu, 'name', $locale),
            'slug' => $menu->slug,
            'location' => $menu->location,
            'location_label' => Menu::locations()[$menu->location] ?? ucfirst($menu->location),
            'description' => $this->translated($menu, 'description', $locale),
            'active' => $menu->active,
            'items_count' => $menu->items_count ?? $menu->items()->count(),
            'updated_at' => $menu->updated_at?->toIso8601String(),
        ];

        if ($full) {
            $data += [
                'name_translations' => $menu->getTranslations('name'),
                'description_translations' => $menu->getTranslations('description'),
            ];
        }

        return $data;
    }

    private function ensureSingleActiveMenu(Menu $menu): void
    {
        if (! $menu->active || $menu->location !== Menu::LOCATION_HEADER) {
            return;
        }

        Menu::query()
            ->whereKeyNot($menu->id)
            ->where('location', $menu->location)
            ->where('active', true)
            ->update(['active' => false]);
    }

    private function optionsData(): array
    {
        return [
            'locales' => collect(Taxonomy::supportedLocales())
                ->map(fn (string $locale) => ['value' => $locale, 'label' => strtoupper($locale)])
                ->values(),
            'locations' => collect(Menu::locations())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'pages' => CmsPage::query()
                ->orderBy('id')
                ->get()
                ->map(fn (CmsPage $page) => [
                    'id' => $page->id,
                    'title' => $page->getTranslation('title', $this->adminLocale(), false)
                        ?: $page->getTranslation('title', Taxonomy::LOCALE_RO, false)
                        ?: collect($page->getTranslations('title'))->filter()->first(),
                    'slug' => $page->getTranslation('slug', $this->adminLocale(), false)
                        ?: $page->getTranslation('slug', Taxonomy::LOCALE_RO, false),
                    'status' => $page->status,
                ])
                ->values(),
            'contents' => Content::query()
                ->orderBy('original_title')
                ->get(['id', 'slug', 'title', 'original_title', 'status'])
                ->map(fn (Content $content) => [
                    'id' => $content->id,
                    'title' => $content->getTranslation('title', $this->adminLocale(), false)
                        ?: $content->getTranslation('title', Taxonomy::LOCALE_RO, false)
                        ?: $content->original_title,
                    'slug' => $content->slug,
                    'status' => $content->status,
                ])
                ->values(),
        ];
    }

    private function localized(array $values): array
    {
        return collect(Taxonomy::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($values[$locale] ?? ''))])
            ->all();
    }

    private function translated(Menu $menu, string $field, string $locale): string
    {
        return $menu->getTranslation($field, $locale, false)
            ?: $menu->getTranslation($field, Taxonomy::LOCALE_RO, false)
            ?: collect($menu->getTranslations($field))->filter()->first()
            ?: '';
    }

    private function adminLocale(): string
    {
        return request()->user()?->preferred_locale ?? Taxonomy::LOCALE_RO;
    }
}
