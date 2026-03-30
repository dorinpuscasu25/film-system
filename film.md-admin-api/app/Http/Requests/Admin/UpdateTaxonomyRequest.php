<?php

namespace App\Http\Requests\Admin;

use App\Models\Taxonomy;
use Illuminate\Validation\Rule;

class UpdateTaxonomyRequest extends StoreTaxonomyRequest
{
    public function rules(): array
    {
        /** @var Taxonomy $taxonomy */
        $taxonomy = $this->route('taxonomy');

        return [
            'type' => ['required', Rule::in(Taxonomy::availableTypes())],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('taxonomies', 'slug')
                    ->ignore($taxonomy?->id)
                    ->where(fn ($query) => $query->where('type', (string) $this->input('type'))),
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
}
