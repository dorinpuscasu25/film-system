<?php

namespace Database\Seeders;

use App\Models\Content;
use App\Models\HomePageSection;
use App\Models\Taxonomy;
use Illuminate\Database\Seeder;

class HomePageSectionSeeder extends Seeder
{
    public function run(): void
    {
        $carbon = Content::query()->where('slug', 'carbon')->first();
        $teambuilding = Content::query()->where('slug', 'teambuilding')->first();
        $hackerville = Content::query()->where('slug', 'hackerville')->first();
        $afacereaEst = Content::query()->where('slug', 'afacerea-est')->first();
        $drama = Taxonomy::query()->where('slug', 'drama')->first();
        $comedy = Taxonomy::query()->where('slug', 'comedy')->first();
        $romanianCinema = Taxonomy::query()->where('slug', 'romanian-cinema')->first();

        if (! $carbon || ! $teambuilding || ! $hackerville || ! $afacereaEst) {
            return;
        }

        HomePageSection::query()->delete();

        HomePageSection::query()->create([
            'name' => 'Main hero slider',
            'section_type' => HomePageSection::TYPE_HERO_SLIDER,
            'active' => true,
            'sort_order' => 0,
            'title' => ['ro' => '', 'ru' => '', 'en' => ''],
            'subtitle' => ['ro' => '', 'ru' => '', 'en' => ''],
            'hero_slides' => [
                [
                    'id' => 'carbon-hero',
                    'content_id' => $carbon->id,
                    'active' => true,
                    'sort_order' => 0,
                    'desktop_image_url' => $carbon->hero_desktop_url ?: $carbon->backdrop_url,
                    'mobile_image_url' => $carbon->hero_mobile_url ?: $carbon->poster_url,
                    'eyebrow' => ['ro' => 'Recomandarea săptămânii', 'ru' => 'Рекомендация недели', 'en' => 'Weekly spotlight'],
                    'title' => ['ro' => 'Carbon', 'ru' => 'Карбон', 'en' => 'Carbon'],
                    'description' => [
                        'ro' => 'Controlezi manual imaginea, headline-ul și CTA-urile pentru slide-ul principal.',
                        'ru' => 'Вы вручную контролируете изображение, заголовок и CTA главного слайда.',
                        'en' => 'Control the image, headline, and CTA copy for the main hero slide.',
                    ],
                    'primary_cta_label' => ['ro' => 'Vezi detalii', 'ru' => 'Подробнее', 'en' => 'See details'],
                    'secondary_cta_label' => ['ro' => 'Trailer', 'ru' => 'Трейлер', 'en' => 'Trailer'],
                ],
                [
                    'id' => 'hackerville-hero',
                    'content_id' => $hackerville->id,
                    'active' => true,
                    'sort_order' => 1,
                    'desktop_image_url' => $hackerville->hero_desktop_url ?: $hackerville->backdrop_url,
                    'mobile_image_url' => $hackerville->hero_mobile_url ?: $hackerville->poster_url,
                    'eyebrow' => ['ro' => 'Serial premium', 'ru' => 'Премиальный сериал', 'en' => 'Premium series'],
                    'title' => ['ro' => 'Hackerville', 'ru' => 'Хакервиль', 'en' => 'Hackerville'],
                    'description' => [
                        'ro' => 'Poți promova separat serialele, cu art dedicat pentru desktop și mobil.',
                        'ru' => 'Можно продвигать сериалы отдельно, с отдельным артом для desktop и mobile.',
                        'en' => 'Promote a series separately with dedicated desktop and mobile artwork.',
                    ],
                    'primary_cta_label' => ['ro' => 'Deschide serialul', 'ru' => 'Открыть сериал', 'en' => 'Open series'],
                    'secondary_cta_label' => ['ro' => 'Vezi episoade', 'ru' => 'Смотреть эпизоды', 'en' => 'View episodes'],
                ],
            ],
        ]);

        HomePageSection::query()->create([
            'name' => 'Trending now',
            'section_type' => HomePageSection::TYPE_CONTENT_CAROUSEL,
            'active' => true,
            'sort_order' => 10,
            'title' => ['ro' => 'În trend acum', 'ru' => 'Сейчас в тренде', 'en' => 'Trending now'],
            'subtitle' => [
                'ro' => 'Selecție dinamică după titlurile care performează cel mai bine.',
                'ru' => 'Динамическая подборка тайтлов, которые сейчас показывают лучший результат.',
                'en' => 'A dynamic row for titles performing best right now.',
            ],
            'source_mode' => HomePageSection::SOURCE_DYNAMIC,
            'limit' => 12,
            'rule_filters' => [
                'taxonomy_ids' => [],
                'content_types' => [],
                'access' => HomePageSection::ACCESS_ALL,
                'sort_mode' => HomePageSection::SORT_RELEASE_YEAR_DESC,
                'matching_strategy' => HomePageSection::MATCH_ANY,
                'featured_only' => false,
                'trending_only' => true,
            ],
        ]);

        HomePageSection::query()->create([
            'name' => 'Comedy picks',
            'section_type' => HomePageSection::TYPE_CONTENT_CAROUSEL,
            'active' => true,
            'sort_order' => 20,
            'title' => ['ro' => 'Comedii pentru seara asta', 'ru' => 'Комедии на вечер', 'en' => 'Comedy for tonight'],
            'subtitle' => [
                'ro' => 'Titluri automate filtrate după genul selectat.',
                'ru' => 'Автоматические тайтлы, отфильтрованные по выбранному жанру.',
                'en' => 'Dynamic titles filtered by the selected genre.',
            ],
            'source_mode' => HomePageSection::SOURCE_DYNAMIC,
            'limit' => 12,
            'rule_filters' => [
                'taxonomy_ids' => array_values(array_filter([$comedy?->id])),
                'content_types' => [Content::TYPE_MOVIE],
                'access' => HomePageSection::ACCESS_ALL,
                'sort_mode' => HomePageSection::SORT_RELEASE_YEAR_DESC,
                'matching_strategy' => HomePageSection::MATCH_ANY,
                'featured_only' => false,
                'trending_only' => false,
            ],
        ]);

        HomePageSection::query()->create([
            'name' => 'Romanian cinema',
            'section_type' => HomePageSection::TYPE_CONTENT_CAROUSEL,
            'active' => true,
            'sort_order' => 30,
            'title' => ['ro' => 'Cinema românesc și moldovenesc', 'ru' => 'Румынское и молдавское кино', 'en' => 'Romanian and Moldovan cinema'],
            'subtitle' => [
                'ro' => 'Poți construi shelves editoriale și după colecții sau tag-uri.',
                'ru' => 'Можно строить редакционные полки по коллекциям или тегам.',
                'en' => 'Build editorial shelves using collections or tags.',
            ],
            'source_mode' => HomePageSection::SOURCE_DYNAMIC,
            'limit' => 12,
            'rule_filters' => [
                'taxonomy_ids' => array_values(array_filter([$romanianCinema?->id, $drama?->id])),
                'content_types' => [],
                'access' => HomePageSection::ACCESS_ALL,
                'sort_mode' => HomePageSection::SORT_IMDB_DESC,
                'matching_strategy' => HomePageSection::MATCH_ANY,
                'featured_only' => false,
                'trending_only' => false,
            ],
        ]);

        HomePageSection::query()->create([
            'name' => 'Manual spotlight',
            'section_type' => HomePageSection::TYPE_CONTENT_CAROUSEL,
            'active' => true,
            'sort_order' => 40,
            'title' => ['ro' => 'Selecție manuală', 'ru' => 'Ручная подборка', 'en' => 'Manual spotlight'],
            'subtitle' => [
                'ro' => 'Ordinea este controlată manual din admin.',
                'ru' => 'Порядок полностью контролируется вручную из админки.',
                'en' => 'Control the exact order manually from admin.',
            ],
            'source_mode' => HomePageSection::SOURCE_MANUAL,
            'limit' => 12,
            'content_ids' => [
                $teambuilding->id,
                $carbon->id,
                $hackerville->id,
                $afacereaEst->id,
            ],
            'rule_filters' => [
                'taxonomy_ids' => [],
                'content_types' => [],
                'access' => HomePageSection::ACCESS_ALL,
                'sort_mode' => HomePageSection::SORT_MANUAL,
                'matching_strategy' => HomePageSection::MATCH_ANY,
                'featured_only' => false,
                'trending_only' => false,
            ],
        ]);
    }
}
