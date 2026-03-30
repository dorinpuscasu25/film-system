<?php

namespace App\Http\Requests\Admin;

use App\Models\Content;
use Illuminate\Validation\Rule;

class UpdateContentRequest extends StoreContentRequest
{
    public function rules(): array
    {
        /** @var Content|null $content */
        $content = $this->route('content');

        return [
            ...parent::rules(),
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('contents', 'slug')->ignore($content?->id),
            ],
        ];
    }
}
