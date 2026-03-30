<?php

namespace App\Http\Requests\Admin;

use App\Models\Taxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxonomyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Taxonomy::availableTypes())],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('taxonomies', 'slug')->where(
                    fn ($query) => $query->where('type', (string) $this->input('type')),
                ),
            ],
            'active' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'content_count' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'array'],
            'name.ro' => ['required', 'string', 'max:255'],
            'name.ru' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ro' => ['nullable', 'string', 'max:1000'],
            'description.ru' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('type') === Taxonomy::TYPE_BADGE && ! $this->filled('color')) {
                $validator->errors()->add('color', 'The color field is required for badges.');
            }
        });
    }

    public function normalizedPayload(): array
    {
        return [
            'type' => (string) $this->input('type'),
            'slug' => (string) $this->input('slug'),
            'active' => (bool) $this->boolean('active', true),
            'color' => $this->filled('color') ? (string) $this->input('color') : null,
            'sort_order' => (int) $this->integer('sort_order', 0),
            'content_count' => (int) $this->integer('content_count', 0),
            'name' => collect(Taxonomy::supportedLocales())
                ->mapWithKeys(fn (string $locale) => [$locale => trim((string) data_get($this->input('name', []), $locale))])
                ->all(),
            'description' => collect(Taxonomy::supportedLocales())
                ->mapWithKeys(fn (string $locale) => [$locale => trim((string) data_get($this->input('description', []), $locale))])
                ->all(),
        ];
    }
}
