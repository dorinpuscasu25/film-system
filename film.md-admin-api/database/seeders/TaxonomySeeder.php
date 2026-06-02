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
            [
                'type' => Taxonomy::TYPE_CREW_ROLE,
                'slug' => 'director',
                'name' => ['ro' => 'Regizor', 'ru' => 'Режиссёр', 'en' => 'Director'],
                'description' => ['ro' => 'Rol de regie în echipa producției.', 'ru' => 'Режиссёрская роль в команде проекта.', 'en' => 'Directing role in the production crew.'],
                'sort_order' => 10,
            ],
            [
                'type' => Taxonomy::TYPE_CREW_ROLE,
                'slug' => 'screenwriter',
                'name' => ['ro' => 'Scenarist', 'ru' => 'Сценарист', 'en' => 'Screenwriter'],
                'description' => ['ro' => 'Rol de scenariu în echipa producției.', 'ru' => 'Сценарная роль в команде проекта.', 'en' => 'Screenwriting role in the production crew.'],
                'sort_order' => 20,
            ],
            [
                'type' => Taxonomy::TYPE_CREW_ROLE,
                'slug' => 'producer',
                'name' => ['ro' => 'Producător', 'ru' => 'Продюсер', 'en' => 'Producer'],
                'description' => ['ro' => 'Rol de producție în echipa proiectului.', 'ru' => 'Продюсерская роль в команде проекта.', 'en' => 'Producing role in the project crew.'],
                'sort_order' => 30,
            ],
            [
                'type' => Taxonomy::TYPE_CREW_ROLE,
                'slug' => 'cinematographer',
                'name' => ['ro' => 'Imagine', 'ru' => 'Оператор', 'en' => 'Cinematographer'],
                'description' => ['ro' => 'Responsabil de imagine și cameră.', 'ru' => 'Отвечает за изображение и камеру.', 'en' => 'Responsible for cinematography and camera.'],
                'sort_order' => 40,
            ],
            [
                'type' => Taxonomy::TYPE_CREW_ROLE,
                'slug' => 'composer',
                'name' => ['ro' => 'Compozitor', 'ru' => 'Композитор', 'en' => 'Composer'],
                'description' => ['ro' => 'Responsabil de muzica producției.', 'ru' => 'Отвечает за музыку проекта.', 'en' => 'Responsible for the production music.'],
                'sort_order' => 50,
            ],
            [
                'type' => Taxonomy::TYPE_CREW_ROLE,
                'slug' => 'editor',
                'name' => ['ro' => 'Montaj', 'ru' => 'Монтаж', 'en' => 'Editor'],
                'description' => ['ro' => 'Responsabil de montaj.', 'ru' => 'Отвечает за монтаж.', 'en' => 'Responsible for editing.'],
                'sort_order' => 60,
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
