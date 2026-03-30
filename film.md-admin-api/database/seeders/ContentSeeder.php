<?php

namespace Database\Seeders;

use App\Models\Content;
use App\Models\Offer;
use App\Models\Taxonomy;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $contents = [
            [
                'type' => Content::TYPE_MOVIE,
                'slug' => 'carbon',
                'default_locale' => 'ro',
                'status' => Content::STATUS_PUBLISHED,
                'original_title' => 'Carbon',
                'title' => ['ro' => 'Carbon', 'ru' => 'Карбон', 'en' => 'Carbon'],
                'tagline' => ['ro' => 'Un drum absurd prin haosul războiului.', 'ru' => 'Абсурдное путешествие сквозь хаос войны.', 'en' => 'An absurd road through the chaos of war.'],
                'short_description' => ['ro' => 'O comedie neagră moldovenească despre război, prietenie și întâmplări imposibile.', 'ru' => 'Молдавская чёрная комедия о войне, дружбе и невозможных совпадениях.', 'en' => 'A Moldovan dark comedy about war, friendship and impossible coincidences.'],
                'description' => ['ro' => 'Doi bărbați obișnuiți găsesc un tanc abandonat și pornesc într-o aventură absurdă care le schimbă complet viețile.', 'ru' => 'Два обычных мужчины находят брошенный танк и отправляются в абсурдное приключение, которое полностью меняет их жизнь.', 'en' => 'Two ordinary men discover an abandoned tank and begin an absurd adventure that changes their lives.'],
                'editor_notes' => ['ro' => 'Titlu puternic pentru homepage și campanii locale.', 'ru' => 'Сильный тайтл для главной и локальных кампаний.', 'en' => 'A strong title for the homepage and local campaigns.'],
                'meta_title' => ['ro' => 'Carbon pe film.md', 'ru' => 'Карбон на film.md', 'en' => 'Carbon on film.md'],
                'meta_description' => ['ro' => 'Vezi Carbon online pe film.md.', 'ru' => 'Смотрите Карбон онлайн на film.md.', 'en' => 'Watch Carbon online on film.md.'],
                'release_year' => 2022,
                'country_code' => 'MD',
                'imdb_rating' => 8.3,
                'platform_rating' => 4.7,
                'runtime_minutes' => 103,
                'age_rating' => '16+',
                'poster_url' => 'https://picsum.photos/seed/carbon-poster/400/600',
                'backdrop_url' => 'https://picsum.photos/seed/carbon-backdrop/1440/810',
                'hero_desktop_url' => 'https://picsum.photos/seed/carbon-hero-desktop/1600/760',
                'hero_mobile_url' => 'https://picsum.photos/seed/carbon-hero-mobile/720/1080',
                'trailer_url' => 'https://www.youtube.com/watch?v=carbon-trailer',
                'preview_images' => [
                    'https://picsum.photos/seed/carbon-preview-1/1200/800',
                    'https://picsum.photos/seed/carbon-preview-2/1200/800',
                ],
                'cast_members' => [
                    ['name' => 'Dumitru Roman', 'role' => 'Vasile', 'avatar_url' => 'https://picsum.photos/seed/carbon-cast-1/120/120', 'sort_order' => 0],
                    ['name' => 'Ion Vutcărău', 'role' => 'Sergiu', 'avatar_url' => 'https://picsum.photos/seed/carbon-cast-2/120/120', 'sort_order' => 1],
                ],
                'crew_members' => [
                    ['name' => 'Ion Borș', 'job' => 'Director', 'avatar_url' => 'https://picsum.photos/seed/carbon-crew-1/120/120', 'sort_order' => 0],
                    ['name' => 'Marin Damaschin', 'job' => 'Writer', 'avatar_url' => 'https://picsum.photos/seed/carbon-crew-2/120/120', 'sort_order' => 1],
                ],
                'videos' => [
                    ['type' => 'trailer', 'title' => 'Official Trailer', 'video_url' => 'https://example.com/video/carbon-trailer.mp4', 'thumbnail_url' => 'https://picsum.photos/seed/carbon-video-1/1280/720', 'is_primary' => true, 'sort_order' => 0],
                    ['type' => 'extra', 'title' => 'Behind the scenes', 'video_url' => 'https://example.com/video/carbon-bts.mp4', 'thumbnail_url' => 'https://picsum.photos/seed/carbon-video-2/1280/720', 'sort_order' => 1],
                ],
                'seasons' => [],
                'subtitle_locales' => ['ro', 'en'],
                'available_qualities' => ['HD', 'Full HD'],
                'is_featured' => true,
                'is_trending' => true,
                'is_free' => false,
                'price_amount' => 4.99,
                'currency' => 'USD',
                'rental_days' => 2,
                'sort_order' => 10,
                'taxonomy_slugs' => ['drama', 'family-night', 'editor-choice'],
                'offers' => [
                    ['name' => '2 days SD', 'offer_type' => Offer::TYPE_RENTAL, 'quality' => 'HD', 'price_amount' => 4.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/carbon-hd.mp4', 'rental_days' => 2, 'sort_order' => 10],
                    ['name' => 'Forever SD', 'offer_type' => Offer::TYPE_LIFETIME, 'quality' => 'HD', 'price_amount' => 9.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/carbon-hd.mp4', 'sort_order' => 20],
                    ['name' => 'Forever Full HD', 'offer_type' => Offer::TYPE_LIFETIME, 'quality' => 'Full HD', 'price_amount' => 12.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/carbon-fullhd.mp4', 'sort_order' => 30],
                ],
            ],
            [
                'type' => Content::TYPE_MOVIE,
                'slug' => 'teambuilding',
                'default_locale' => 'ro',
                'status' => Content::STATUS_PUBLISHED,
                'original_title' => 'Teambuilding',
                'title' => ['ro' => 'Teambuilding', 'ru' => 'Тимбилдинг', 'en' => 'Teambuilding'],
                'tagline' => ['ro' => 'Corporatiști, weekend și prea mult adevăr.', 'ru' => 'Корпоратив, выходные и слишком много правды.', 'en' => 'Corporate retreats, weekends and too much truth.'],
                'short_description' => ['ro' => 'Comedie mainstream despre cultura corporate din România.', 'ru' => 'Мейнстрим-комедия о корпоративной культуре Румынии.', 'en' => 'A mainstream comedy about Romanian corporate culture.'],
                'description' => ['ro' => 'O gașcă de colegi pleacă într-un team building care scapă rapid de sub control.', 'ru' => 'Группа коллег отправляется на тимбилдинг, который быстро выходит из-под контроля.', 'en' => 'A group of co-workers goes on a team building trip that quickly spirals out of control.'],
                'editor_notes' => ['ro' => 'Ideal pentru campanii comerciale și zona de comedy.', 'ru' => 'Подходит для коммерческих кампаний и раздела комедий.', 'en' => 'Ideal for commercial campaigns and the comedy shelf.'],
                'meta_title' => ['ro' => 'Teambuilding online', 'ru' => 'Тимбилдинг онлайн', 'en' => 'Watch Teambuilding online'],
                'meta_description' => ['ro' => 'Comedia Teambuilding disponibilă pe film.md.', 'ru' => 'Комедия Тимбилдинг доступна на film.md.', 'en' => 'The comedy Teambuilding is available on film.md.'],
                'release_year' => 2022,
                'country_code' => 'RO',
                'imdb_rating' => 6.7,
                'platform_rating' => 4.2,
                'runtime_minutes' => 91,
                'age_rating' => '16+',
                'poster_url' => 'https://picsum.photos/seed/teambuilding-poster/400/600',
                'backdrop_url' => 'https://picsum.photos/seed/teambuilding-backdrop/1440/810',
                'hero_desktop_url' => 'https://picsum.photos/seed/teambuilding-hero-desktop/1600/760',
                'hero_mobile_url' => 'https://picsum.photos/seed/teambuilding-hero-mobile/720/1080',
                'trailer_url' => 'https://www.youtube.com/watch?v=teambuilding-trailer',
                'preview_images' => [
                    'https://picsum.photos/seed/teambuilding-preview-1/1200/800',
                    'https://picsum.photos/seed/teambuilding-preview-2/1200/800',
                ],
                'cast_members' => [
                    ['name' => 'Matei Dima', 'role' => 'Emil', 'avatar_url' => 'https://picsum.photos/seed/team-cast-1/120/120', 'sort_order' => 0],
                    ['name' => 'Micutzu', 'role' => 'Alex', 'avatar_url' => 'https://picsum.photos/seed/team-cast-2/120/120', 'sort_order' => 1],
                ],
                'crew_members' => [
                    ['name' => 'Matei Dima', 'job' => 'Director', 'avatar_url' => 'https://picsum.photos/seed/team-crew-1/120/120', 'sort_order' => 0],
                ],
                'videos' => [
                    ['type' => 'trailer', 'title' => 'Official Trailer', 'video_url' => 'https://example.com/video/teambuilding-trailer.mp4', 'thumbnail_url' => 'https://picsum.photos/seed/team-video-1/1280/720', 'is_primary' => true, 'sort_order' => 0],
                ],
                'seasons' => [],
                'subtitle_locales' => ['ro', 'ru', 'en'],
                'available_qualities' => ['HD', 'Full HD', '4K'],
                'is_featured' => true,
                'is_trending' => true,
                'is_free' => false,
                'price_amount' => 3.99,
                'currency' => 'USD',
                'rental_days' => 2,
                'sort_order' => 20,
                'taxonomy_slugs' => ['comedy', 'family-night', 'new'],
                'offers' => [
                    ['name' => '2 days HD', 'offer_type' => Offer::TYPE_RENTAL, 'quality' => 'HD', 'price_amount' => 3.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/teambuilding-hd.mp4', 'rental_days' => 2, 'sort_order' => 10],
                    ['name' => '2 days Full HD', 'offer_type' => Offer::TYPE_RENTAL, 'quality' => 'Full HD', 'price_amount' => 4.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/teambuilding-fullhd.mp4', 'rental_days' => 2, 'sort_order' => 20],
                    ['name' => 'Forever 4K', 'offer_type' => Offer::TYPE_LIFETIME, 'quality' => '4K', 'price_amount' => 14.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/teambuilding-4k.mp4', 'sort_order' => 30],
                ],
            ],
            [
                'type' => Content::TYPE_MOVIE,
                'slug' => 'afacerea-est',
                'default_locale' => 'ro',
                'status' => Content::STATUS_PUBLISHED,
                'original_title' => 'Afacerea Est',
                'title' => ['ro' => 'Afacerea Est', 'ru' => 'Восточное дело', 'en' => 'Eastern Business'],
                'tagline' => ['ro' => 'O călătorie mică, cu ambiții uriașe.', 'ru' => 'Небольшое путешествие с гигантскими амбициями.', 'en' => 'A small journey with huge ambitions.'],
                'short_description' => ['ro' => 'Road movie cu umor sec și identitate est-europeană.', 'ru' => 'Роуд-муви с сухим юмором и восточноевропейской идентичностью.', 'en' => 'A road movie with dry humor and Eastern European identity.'],
                'description' => ['ro' => 'Doi prieteni pornesc prin țară cu un plan simplu, dar descoperă că nimic nu e chiar atât de simplu.', 'ru' => 'Двое друзей отправляются в путь с простым планом, но быстро понимают, что всё сложнее, чем кажется.', 'en' => 'Two friends hit the road with a simple plan and learn that nothing is ever that simple.'],
                'editor_notes' => ['ro' => 'Poate intra ușor în featured după activarea comerțului.', 'ru' => 'Можно быстро поднять в featured после запуска коммерции.', 'en' => 'Can move into featured quickly once commerce is enabled.'],
                'meta_title' => ['ro' => 'Afacerea Est pe film.md', 'ru' => 'Восточное дело на film.md', 'en' => 'Eastern Business on film.md'],
                'meta_description' => ['ro' => 'Descoperă Afacerea Est pe film.md.', 'ru' => 'Откройте для себя Восточное дело на film.md.', 'en' => 'Discover Eastern Business on film.md.'],
                'release_year' => 2016,
                'country_code' => 'MD',
                'imdb_rating' => 7.4,
                'platform_rating' => 4.4,
                'runtime_minutes' => 87,
                'age_rating' => '12+',
                'poster_url' => 'https://picsum.photos/seed/afacereaest-poster/400/600',
                'backdrop_url' => 'https://picsum.photos/seed/afacereaest-backdrop/1440/810',
                'preview_images' => [
                    'https://picsum.photos/seed/afacereaest-preview-1/1200/800',
                ],
                'cast_members' => [
                    ['name' => 'Constantin Pușcașu', 'role' => 'Petru', 'avatar_url' => 'https://picsum.photos/seed/afacerea-cast-1/120/120', 'sort_order' => 0],
                    ['name' => 'Daniel Busuioc', 'role' => 'Marcel', 'avatar_url' => 'https://picsum.photos/seed/afacerea-cast-2/120/120', 'sort_order' => 1],
                ],
                'crew_members' => [
                    ['name' => 'Igor Cobileanski', 'job' => 'Director', 'avatar_url' => 'https://picsum.photos/seed/afacerea-crew-1/120/120', 'sort_order' => 0],
                ],
                'videos' => [
                    ['type' => 'trailer', 'title' => 'Official Trailer', 'video_url' => 'https://example.com/video/afacerea-est-trailer.mp4', 'thumbnail_url' => 'https://picsum.photos/seed/afacerea-video-1/1280/720', 'is_primary' => true, 'sort_order' => 0],
                ],
                'seasons' => [],
                'subtitle_locales' => ['ro', 'en'],
                'available_qualities' => ['HD'],
                'is_featured' => false,
                'is_trending' => false,
                'is_free' => true,
                'price_amount' => 0,
                'currency' => 'USD',
                'rental_days' => null,
                'sort_order' => 30,
                'taxonomy_slugs' => ['comedy', 'festival-picks'],
                'offers' => [],
            ],
            [
                'type' => Content::TYPE_SERIES,
                'slug' => 'hackerville',
                'default_locale' => 'ro',
                'status' => Content::STATUS_PUBLISHED,
                'original_title' => 'Hackerville',
                'title' => ['ro' => 'Hackerville', 'ru' => 'Хакервиль', 'en' => 'Hackerville'],
                'tagline' => ['ro' => 'Crimă cibernetică fără granițe.', 'ru' => 'Киберпреступность без границ.', 'en' => 'Cybercrime without borders.'],
                'short_description' => ['ro' => 'Serial tensionat despre investigații digitale și crimă organizată.', 'ru' => 'Напряжённый сериал о цифровых расследованиях и организованной преступности.', 'en' => 'A tense series about digital investigations and organized crime.'],
                'description' => ['ro' => 'Un procuror din Germania și un polițist din Timișoara intră într-o anchetă despre un atac informatic major.', 'ru' => 'Прокурор из Германии и полицейский из Тимишоары расследуют масштабную кибератаку.', 'en' => 'A German prosecutor and a Timisoara detective investigate a major cyberattack.'],
                'editor_notes' => ['ro' => 'Bun pentru colecții de thrillere și secțiunea de seriale.', 'ru' => 'Подходит для триллер-коллекций и полки сериалов.', 'en' => 'Great for thriller collections and the series shelf.'],
                'meta_title' => ['ro' => 'Hackerville serial online', 'ru' => 'Хакервиль сериал онлайн', 'en' => 'Hackerville streaming'],
                'meta_description' => ['ro' => 'Vezi serialul Hackerville online.', 'ru' => 'Смотрите сериал Хакервиль онлайн.', 'en' => 'Stream the Hackerville series online.'],
                'release_year' => 2018,
                'country_code' => 'RO',
                'imdb_rating' => 8.0,
                'platform_rating' => 4.6,
                'runtime_minutes' => 55,
                'age_rating' => '16+',
                'poster_url' => 'https://picsum.photos/seed/hackerville-poster/400/600',
                'backdrop_url' => 'https://picsum.photos/seed/hackerville-backdrop/1440/810',
                'hero_desktop_url' => 'https://picsum.photos/seed/hackerville-hero-desktop/1600/760',
                'hero_mobile_url' => 'https://picsum.photos/seed/hackerville-hero-mobile/720/1080',
                'trailer_url' => 'https://www.youtube.com/watch?v=hackerville-trailer',
                'preview_images' => [
                    'https://picsum.photos/seed/hackerville-preview-1/1200/800',
                    'https://picsum.photos/seed/hackerville-preview-2/1200/800',
                ],
                'cast_members' => [
                    ['name' => 'Anna Schumacher', 'role' => 'Lisa Metz', 'avatar_url' => 'https://picsum.photos/seed/hackerville-cast-1/120/120', 'sort_order' => 0],
                    ['name' => 'Andi Vasluianu', 'role' => 'Adam Sandor', 'avatar_url' => 'https://picsum.photos/seed/hackerville-cast-2/120/120', 'sort_order' => 1],
                ],
                'crew_members' => [
                    ['name' => 'Anca Miruna Lăzărescu', 'job' => 'Director', 'avatar_url' => 'https://picsum.photos/seed/hackerville-crew-1/120/120', 'sort_order' => 0],
                ],
                'videos' => [
                    ['type' => 'trailer', 'title' => 'Season Trailer', 'video_url' => 'https://example.com/video/hackerville-trailer.mp4', 'thumbnail_url' => 'https://picsum.photos/seed/hackerville-video-1/1280/720', 'is_primary' => true, 'sort_order' => 0],
                    ['type' => 'extra', 'title' => 'Cybercrime featurette', 'video_url' => 'https://example.com/video/hackerville-featurette.mp4', 'thumbnail_url' => 'https://picsum.photos/seed/hackerville-video-2/1280/720', 'sort_order' => 1],
                ],
                'seasons' => [
                    [
                        'season_number' => 1,
                        'title' => 'Season 1',
                        'description' => 'A cross-border cybercrime case opens a much bigger investigation.',
                        'poster_url' => 'https://picsum.photos/seed/hackerville-season-1/400/600',
                        'sort_order' => 0,
                        'episodes' => [
                            ['episode_number' => 1, 'title' => 'Episode 1', 'description' => 'The first breach points toward a network in Eastern Europe.', 'runtime_minutes' => 52, 'thumbnail_url' => 'https://picsum.photos/seed/hackerville-ep-1/1280/720', 'video_url' => 'https://example.com/video/hackerville-s1e1.mp4', 'sort_order' => 0],
                            ['episode_number' => 2, 'title' => 'Episode 2', 'description' => 'The team follows new traces through Romania and Germany.', 'runtime_minutes' => 55, 'thumbnail_url' => 'https://picsum.photos/seed/hackerville-ep-2/1280/720', 'video_url' => 'https://example.com/video/hackerville-s1e2.mp4', 'sort_order' => 1],
                            ['episode_number' => 3, 'title' => 'Episode 3', 'description' => 'Personal stakes rise as the hackers strike again.', 'runtime_minutes' => 53, 'thumbnail_url' => 'https://picsum.photos/seed/hackerville-ep-3/1280/720', 'video_url' => 'https://example.com/video/hackerville-s1e3.mp4', 'sort_order' => 2],
                        ],
                    ],
                ],
                'subtitle_locales' => ['ro', 'en', 'ru'],
                'available_qualities' => ['HD', 'Full HD'],
                'is_featured' => false,
                'is_trending' => true,
                'is_free' => false,
                'price_amount' => 5.99,
                'currency' => 'USD',
                'rental_days' => 7,
                'sort_order' => 40,
                'taxonomy_slugs' => ['drama', 'festival-picks', 'romanian-cinema'],
                'offers' => [
                    ['name' => '7 days HD', 'offer_type' => Offer::TYPE_RENTAL, 'quality' => 'HD', 'price_amount' => 5.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/hackerville-hd.m3u8', 'rental_days' => 7, 'sort_order' => 10],
                    ['name' => '30 days Full HD', 'offer_type' => Offer::TYPE_RENTAL, 'quality' => 'Full HD', 'price_amount' => 8.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/hackerville-fullhd.m3u8', 'rental_days' => 30, 'sort_order' => 20],
                    ['name' => 'Forever Full HD', 'offer_type' => Offer::TYPE_LIFETIME, 'quality' => 'Full HD', 'price_amount' => 18.99, 'currency' => 'USD', 'playback_url' => 'https://storage.film.md/playback/hackerville-fullhd.m3u8', 'sort_order' => 30],
                ],
            ],
        ];

        foreach ($contents as $item) {
            $content = Content::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'type' => $item['type'],
                    'default_locale' => $item['default_locale'],
                    'status' => $item['status'],
                    'original_title' => $item['original_title'],
                    'title' => $item['title'],
                    'tagline' => $item['tagline'],
                    'short_description' => $item['short_description'],
                    'description' => $item['description'],
                    'editor_notes' => $item['editor_notes'],
                    'meta_title' => $item['meta_title'],
                    'meta_description' => $item['meta_description'],
                    'release_year' => $item['release_year'],
                    'country_code' => $item['country_code'],
                    'imdb_rating' => $item['imdb_rating'] ?? null,
                    'platform_rating' => $item['platform_rating'] ?? null,
                    'runtime_minutes' => $item['runtime_minutes'],
                    'age_rating' => $item['age_rating'],
                    'poster_url' => $item['poster_url'],
                    'backdrop_url' => $item['backdrop_url'],
                    'hero_desktop_url' => $item['hero_desktop_url'] ?? null,
                    'hero_mobile_url' => $item['hero_mobile_url'] ?? null,
                    'trailer_url' => $item['trailer_url'] ?? null,
                    'preview_images' => $item['preview_images'] ?? [],
                    'cast_members' => $item['cast_members'] ?? [],
                    'crew_members' => $item['crew_members'] ?? [],
                    'videos' => $item['videos'] ?? [],
                    'seasons' => $item['seasons'] ?? [],
                    'subtitle_locales' => $item['subtitle_locales'] ?? [],
                    'available_qualities' => $item['available_qualities'] ?? ['HD'],
                    'is_featured' => $item['is_featured'] ?? false,
                    'is_trending' => $item['is_trending'] ?? false,
                    'is_free' => $item['is_free'] ?? false,
                    'price_amount' => $item['price_amount'] ?? 0,
                    'currency' => $item['currency'] ?? Content::DEFAULT_CURRENCY,
                    'rental_days' => $item['rental_days'] ?? null,
                    'sort_order' => $item['sort_order'] ?? 0,
                    'published_at' => ($item['status'] ?? null) === Content::STATUS_PUBLISHED ? now() : null,
                ],
            );

            $taxonomyIds = Taxonomy::query()
                ->whereIn('slug', $item['taxonomy_slugs'] ?? [])
                ->pluck('id')
                ->all();

            $content->syncTaxonomyIds($taxonomyIds);

            $content->offers()->delete();
            foreach ($item['offers'] ?? [] as $offer) {
                $content->offers()->create([
                    'name' => $offer['name'],
                    'offer_type' => $offer['offer_type'],
                    'quality' => $offer['quality'],
                    'currency' => $offer['currency'] ?? ($item['currency'] ?? Content::DEFAULT_CURRENCY),
                    'price_amount' => $offer['price_amount'],
                    'playback_url' => $offer['playback_url'] ?? null,
                    'rental_days' => $offer['rental_days'] ?? null,
                    'is_active' => $offer['is_active'] ?? true,
                    'starts_at' => $offer['starts_at'] ?? null,
                    'ends_at' => $offer['ends_at'] ?? null,
                    'sort_order' => $offer['sort_order'] ?? 0,
                ]);
            }
        }

        Content::recalculateTaxonomyCounts();
    }
}
