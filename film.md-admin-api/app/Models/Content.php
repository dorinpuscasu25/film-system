<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'type',
    'slug',
    'default_locale',
    'status',
    'original_title',
    'title',
    'tagline',
    'short_description',
    'description',
    'editor_notes',
    'meta_title',
    'meta_description',
    'release_year',
    'country_code',
    'imdb_rating',
    'platform_rating',
    'runtime_minutes',
    'age_rating',
    'poster_url',
    'backdrop_url',
    'hero_desktop_url',
    'hero_mobile_url',
    'trailer_url',
    'preview_images',
    'cast_members',
    'crew_members',
    'videos',
    'seasons',
    'subtitle_locales',
    'available_qualities',
    'is_featured',
    'is_trending',
    'is_free',
    'price_amount',
    'currency',
    'rental_days',
    'sort_order',
    'canonical_url',
    'published_at',
    'meta',
])]
class Content extends Model
{
    use HasTranslations;

    public const TYPE_MOVIE = 'movie';
    public const TYPE_DOCUMENTARY = 'documentary';
    public const TYPE_SHORT = 'short';
    public const TYPE_ANIMATION = 'animation';
    public const TYPE_SERIES = 'series';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const DEFAULT_CURRENCY = 'MDL';

    public array $translatable = [
        'title',
        'tagline',
        'short_description',
        'description',
        'editor_notes',
        'meta_title',
        'meta_description',
    ];

    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, 'content_taxonomy')->withTimestamps();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderBy('sort_order')->orderBy('price_amount');
    }

    public function formats(): HasMany
    {
        return $this->hasMany(ContentFormat::class)->orderBy('sort_order')->orderByDesc('is_default');
    }

    public function rightsWindows(): HasMany
    {
        return $this->hasMany(ContentRightsWindow::class)->orderBy('country_code')->orderBy('starts_at');
    }

    public function subtitleTracks(): HasMany
    {
        return $this->hasMany(SubtitleTrack::class)->orderBy('sort_order');
    }

    public function creators(): BelongsToMany
    {
        return $this->belongsToMany(ContentCreator::class, 'content_creator_assignments')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    public function premiereEvents(): HasMany
    {
        return $this->hasMany(PremiereEvent::class)->orderBy('starts_at');
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    public function monthlyCosts(): HasMany
    {
        return $this->hasMany(VideoMonthlyCost::class);
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_content_accesses')
            ->withPivot(['can_view', 'can_view_stats', 'meta'])
            ->withTimestamps();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ContentEntitlement::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ContentReview::class)->latest();
    }

    public function syncTaxonomyIds(array $taxonomyIds): void
    {
        $this->taxonomies()->sync(array_values(array_unique(array_map('intval', $taxonomyIds))));
        $this->unsetRelation('taxonomies');
    }

    public static function supportedLocales(): array
    {
        return Taxonomy::supportedLocales();
    }

    public static function availableTypes(): array
    {
        return [
            self::TYPE_MOVIE,
            self::TYPE_DOCUMENTARY,
            self::TYPE_SHORT,
            self::TYPE_ANIMATION,
            self::TYPE_SERIES,
        ];
    }

    public static function typeLabels(string $locale = 'en'): array
    {
        return collect(self::typeTranslations())
            ->map(fn (array $labels): string => $labels[$locale] ?? $labels['en'] ?? array_values($labels)[0] ?? '')
            ->all();
    }

    public static function typeTranslations(): array
    {
        return [
            self::TYPE_MOVIE => ['ro' => 'Film', 'ru' => 'Фильм', 'en' => 'Movie'],
            self::TYPE_DOCUMENTARY => ['ro' => 'Documentar', 'ru' => 'Документальный', 'en' => 'Documentary'],
            self::TYPE_SHORT => ['ro' => 'Scurtmetraj', 'ru' => 'Короткометражный', 'en' => 'Short'],
            self::TYPE_ANIMATION => ['ro' => 'Animație', 'ru' => 'Анимация', 'en' => 'Animation'],
            self::TYPE_SERIES => ['ro' => 'Serial', 'ru' => 'Сериал', 'en' => 'Series'],
        ];
    }

    public static function typeLabel(?string $type, string $locale = 'ro'): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        $translations = self::typeTranslations()[$type] ?? null;

        return $translations[$locale] ?? $translations['ro'] ?? $translations['en'] ?? ucfirst($type);
    }

    public static function availableStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_READY,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_READY => 'Ready',
            self::STATUS_PUBLISHED => 'Published',
            self::STATUS_ARCHIVED => 'Archived',
        ];
    }

    public static function availableAgeRatings(): array
    {
        return ['0+', '6+', '12+', '16+', '18+'];
    }

    public static function availableQualities(): array
    {
        return ['SD', 'HD', 'Full HD', '4K'];
    }

    public static function videoTypeLabels(): array
    {
        return [
            'trailer' => 'Trailer',
            'teaser' => 'Teaser',
            'clip' => 'Clip',
            'extra' => 'Extra',
            'behind_scenes' => 'Behind the Scenes',
            'interview' => 'Interview',
        ];
    }

    public static function castCreditTypeTranslations(): array
    {
        return [
            'lead_actor' => ['ro' => 'Actor principal', 'ru' => 'Главная роль', 'en' => 'Lead actor'],
            'supporting_actor' => ['ro' => 'Actor secundar', 'ru' => 'Второстепенная роль', 'en' => 'Supporting actor'],
            'voice_actor' => ['ro' => 'Voce', 'ru' => 'Озвучка', 'en' => 'Voice actor'],
            'guest_star' => ['ro' => 'Invitat special', 'ru' => 'Специальное участие', 'en' => 'Guest star'],
            'cameo' => ['ro' => 'Cameo', 'ru' => 'Камео', 'en' => 'Cameo'],
        ];
    }

    public static function crewCreditTypeTranslations(): array
    {
        return [
            'director' => ['ro' => 'Regizor', 'ru' => 'Режиссёр', 'en' => 'Director'],
            'screenwriter' => ['ro' => 'Scenarist', 'ru' => 'Сценарист', 'en' => 'Screenwriter'],
            'producer' => ['ro' => 'Producător', 'ru' => 'Продюсер', 'en' => 'Producer'],
            'creator' => ['ro' => 'Creator', 'ru' => 'Создатель', 'en' => 'Creator'],
            'showrunner' => ['ro' => 'Showrunner', 'ru' => 'Шоураннер', 'en' => 'Showrunner'],
            'cinematographer' => ['ro' => 'Imagine', 'ru' => 'Оператор', 'en' => 'Cinematographer'],
            'composer' => ['ro' => 'Compozitor', 'ru' => 'Композитор', 'en' => 'Composer'],
            'editor' => ['ro' => 'Montaj', 'ru' => 'Монтаж', 'en' => 'Editor'],
        ];
    }

    public static function localizedOptionLabel(array $translations, ?string $value, string $locale, string $fallbackLocale = 'ro'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $localizedOptions = $translations[$value] ?? null;
        if (! is_array($localizedOptions)) {
            return $value;
        }

        return $localizedOptions[$locale]
            ?? $localizedOptions[$fallbackLocale]
            ?? reset($localizedOptions)
            ?: $value;
    }

    public static function countryOptions(): array
    {
        $codes = preg_split('/\s+/', trim('
            AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ
            CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR
            GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO
            JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR
            MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO
            RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV
            TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW
        '));

        return collect($codes)
            ->filter()
            ->mapWithKeys(function (string $code): array {
                $label = class_exists(\Locale::class) ? \Locale::getDisplayRegion('und_'.$code, 'en') : $code;

                return [$code => $label !== '' ? $label : $code];
            })
            ->sort()
            ->all();
    }

    public static function recalculateTaxonomyCounts(): void
    {
        Taxonomy::query()
            ->withCount('contents')
            ->get()
            ->each(function (Taxonomy $taxonomy): void {
                $taxonomy->forceFill([
                    'content_count' => (int) ($taxonomy->contents_count ?? 0),
                ])->save();
            });
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    protected function casts(): array
    {
        return [
            'release_year' => 'integer',
            'imdb_rating' => 'float',
            'platform_rating' => 'float',
            'runtime_minutes' => 'integer',
            'preview_images' => 'array',
            'cast_members' => 'array',
            'crew_members' => 'array',
            'videos' => 'array',
            'seasons' => 'array',
            'subtitle_locales' => 'array',
            'available_qualities' => 'array',
            'is_featured' => 'boolean',
            'is_trending' => 'boolean',
            'is_free' => 'boolean',
            'price_amount' => 'float',
            'rental_days' => 'integer',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
