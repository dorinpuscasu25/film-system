<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\CmsPage;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CmsPageController extends ApiController
{
    public function index(): JsonResponse
    {
        $locale = $this->adminLocale();
        $pages = CmsPage::query()
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'items' => $pages->map(fn (CmsPage $page) => $this->pageData($page, $locale))->values(),
            'options' => $this->optionsData(),
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['options' => $this->optionsData()]);
    }

    public function show(CmsPage $cmsPage): JsonResponse
    {
        return response()->json([
            'page' => $this->pageData($cmsPage, $this->adminLocale(), true),
            'options' => $this->optionsData(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $page = CmsPage::query()->create($payload);

        return response()->json([
            'page' => $this->pageData($page->fresh(), $this->adminLocale(), true),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, CmsPage $cmsPage): JsonResponse
    {
        $cmsPage->fill($this->validatedPayload($request))->save();

        return response()->json([
            'page' => $this->pageData($cmsPage->fresh(), $this->adminLocale(), true),
        ]);
    }

    public function destroy(CmsPage $cmsPage): JsonResponse
    {
        $cmsPage->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    private function validatedPayload(Request $request): array
    {
        $request->validate([
            'title' => ['required', 'array'],
            'title.ro' => ['nullable', 'string', 'max:255'],
            'title.ru' => ['nullable', 'string', 'max:255'],
            'title.en' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'array'],
            'slug.ro' => ['nullable', 'string', 'max:255'],
            'slug.ru' => ['nullable', 'string', 'max:255'],
            'slug.en' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(CmsPage::availableStatuses())],
            'excerpt' => ['nullable', 'array'],
            'content' => ['nullable', 'array'],
            'meta_title' => ['nullable', 'array'],
            'meta_description' => ['nullable', 'array'],
            'meta_keywords' => ['nullable', 'array'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $title = $this->localized($request->input('title', []));
        if (collect($title)->filter()->isEmpty()) {
            abort(response()->json([
                'message' => 'Titlul este obligatoriu în cel puțin o limbă.',
                'errors' => ['title' => ['Titlul este obligatoriu.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return [
            'title' => $title,
            'slug' => $this->localized($request->input('slug', [])),
            'status' => $request->input('status', CmsPage::STATUS_UNPUBLISHED),
            'excerpt' => $this->localized($request->input('excerpt', [])),
            'content' => $this->localized($request->input('content', [])),
            'meta_title' => $this->localized($request->input('meta_title', [])),
            'meta_description' => $this->localized($request->input('meta_description', [])),
            'meta_keywords' => $this->localized($request->input('meta_keywords', [])),
            'canonical_url' => $request->input('canonical_url'),
        ];
    }

    private function optionsData(): array
    {
        return [
            'locales' => collect(Taxonomy::supportedLocales())
                ->map(fn (string $locale) => ['value' => $locale, 'label' => strtoupper($locale)])
                ->values(),
            'statuses' => [
                ['value' => CmsPage::STATUS_PUBLISHED, 'label' => 'Published'],
                ['value' => CmsPage::STATUS_UNPUBLISHED, 'label' => 'Unpublished'],
            ],
        ];
    }

    private function pageData(CmsPage $page, string $locale, bool $full = false): array
    {
        $fallback = Taxonomy::LOCALE_RO;
        $data = [
            'id' => $page->id,
            'title' => $this->translated($page, 'title', $locale, $fallback),
            'slug' => $this->translated($page, 'slug', $locale, $fallback),
            'status' => $page->status,
            'status_label' => $page->status === CmsPage::STATUS_PUBLISHED ? 'Published' : 'Unpublished',
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];

        if ($full) {
            $data += [
                'title_translations' => $page->getTranslations('title'),
                'slug_translations' => $page->getTranslations('slug'),
                'excerpt_translations' => $page->getTranslations('excerpt'),
                'content_translations' => $page->getTranslations('content'),
                'meta_title_translations' => $page->getTranslations('meta_title'),
                'meta_description_translations' => $page->getTranslations('meta_description'),
                'meta_keywords_translations' => $page->getTranslations('meta_keywords'),
                'canonical_url' => $page->canonical_url,
            ];
        }

        return $data;
    }

    private function localized(array $values): array
    {
        return collect(Taxonomy::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($values[$locale] ?? ''))])
            ->all();
    }

    private function translated(CmsPage $page, string $field, string $locale, string $fallback): string
    {
        return $page->getTranslation($field, $locale, false)
            ?: $page->getTranslation($field, $fallback, false)
            ?: collect($page->getTranslations($field))->filter()->first()
            ?: '';
    }

    private function adminLocale(): string
    {
        return request()->user()?->preferred_locale ?? Taxonomy::LOCALE_RO;
    }
}
