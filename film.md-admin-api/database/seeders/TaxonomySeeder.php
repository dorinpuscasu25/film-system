<?php

namespace Database\Seeders;

use App\Models\Taxonomy;
use Illuminate\Database\Seeder;

class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'type' => Taxonomy::TYPE_GENRE,
                'slug' => 'comedy',
                'name' => ['ro' => 'Comedie', 'ru' => 'Комедия', 'en' => 'Comedy'],
                'description' => ['ro' => 'Filme și seriale cu ton relaxat.', 'ru' => 'Фильмы и сериалы с лёгким тоном.', 'en' => 'Films and series with a light tone.'],
                'content_count' => 342,
                'sort_order' => 10,
            ],
            [
                'type' => Taxonomy::TYPE_GENRE,
                'slug' => 'drama',
                'name' => ['ro' => 'Dramă', 'ru' => 'Драма', 'en' => 'Drama'],
                'description' => ['ro' => 'Povești cu încărcătură emoțională puternică.', 'ru' => 'Истории с сильной эмоциональной нагрузкой.', 'en' => 'Stories with strong emotional depth.'],
                'content_count' => 512,
                'sort_order' => 20,
            ],
            [
                'type' => Taxonomy::TYPE_COLLECTION,
                'slug' => 'romanian-cinema',
                'name' => ['ro' => 'Cinema românesc', 'ru' => 'Румынское кино', 'en' => 'Romanian cinema'],
                'description' => ['ro' => 'Selecție editorială de titluri românești.', 'ru' => 'Редакционная подборка румынских тайтлов.', 'en' => 'Editorial selection of Romanian titles.'],
                'content_count' => 450,
                'sort_order' => 10,
            ],
            [
                'type' => Taxonomy::TYPE_TAG,
                'slug' => 'family-night',
                'name' => ['ro' => 'Seară în familie', 'ru' => 'Семейный вечер', 'en' => 'Family night'],
                'description' => ['ro' => 'Titluri potrivite pentru vizionare în familie.', 'ru' => 'Тайтлы для семейного просмотра.', 'en' => 'Titles suitable for family viewing.'],
                'content_count' => 121,
                'sort_order' => 10,
            ],
            [
                'type' => Taxonomy::TYPE_TAG,
                'slug' => 'festival-picks',
                'name' => ['ro' => 'Selecții de festival', 'ru' => 'Фестивальные хиты', 'en' => 'Festival picks'],
                'description' => ['ro' => 'Producții premiate și selecționate în festivaluri.', 'ru' => 'Отмеченные и отобранные фестивалями проекты.', 'en' => 'Awarded productions and festival picks.'],
                'content_count' => 47,
                'sort_order' => 20,
            ],
            [
                'type' => Taxonomy::TYPE_BADGE,
                'slug' => 'new',
                'name' => ['ro' => 'Nou', 'ru' => 'Новинка', 'en' => 'New'],
                'description' => ['ro' => 'Titlu recent publicat.', 'ru' => 'Недавно опубликованный тайтл.', 'en' => 'Recently published title.'],
                'color' => '#0F172A',
                'content_count' => 93,
                'sort_order' => 10,
            ],
            [
                'type' => Taxonomy::TYPE_BADGE,
                'slug' => 'editor-choice',
                'name' => ['ro' => 'Alegerea editorului', 'ru' => 'Выбор редакции', 'en' => 'Editor choice'],
                'description' => ['ro' => 'Recomandare editorială pentru homepage.', 'ru' => 'Редакционная рекомендация для главной.', 'en' => 'Editorial recommendation for the homepage.'],
                'color' => '#F97316',
                'content_count' => 18,
                'sort_order' => 20,
            ],
        ];

        foreach ($items as $item) {
            Taxonomy::query()->updateOrCreate(
                [
                    'type' => $item['type'],
                    'slug' => $item['slug'],
                ],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'active' => true,
                    'color' => $item['color'] ?? null,
                    'content_count' => $item['content_count'] ?? 0,
                    'sort_order' => $item['sort_order'] ?? 0,
                ],
            );
        }
    }
}
