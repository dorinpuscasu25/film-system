<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Invitation;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\AccountProfile;
use App\Models\Taxonomy;
use App\Models\ContentEntitlement;
use App\Models\PremiereEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;

class ApiController extends Controller
{
    protected function userData(User $user): array
    {
        $user->loadMissing('roles.permissions', 'contentAccesses.content');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'preferred_locale' => $user->preferred_locale,
            'status' => $user->status,
            'avatar_url' => $user->avatar_url,
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'roles' => $user->roles->map(fn (Role $role) => $this->roleData($role))->values(),
            'permission_codes' => $user->permissionCodes(),
            'admin_panel_access' => $user->hasAdminPanelAccess(),
            'assigned_content_ids' => $user->assignedContentIds(),
            'assigned_contents' => $user->relationLoaded('contentAccesses')
                ? $user->contentAccesses
                    ->filter(fn ($assignment) => (bool) $assignment->can_view)
                    ->map(fn ($assignment) => [
                        'id' => $assignment->content_id,
                        'title' => $assignment->content?->original_title,
                        'slug' => $assignment->content?->slug,
                    ])
                    ->values()
                : [],
            'wallet' => $user->relationLoaded('wallet') && $user->wallet !== null
                ? $this->walletSummaryData($user->wallet)
                : null,
            'profiles' => $user->relationLoaded('profiles')
                ? $user->profiles->map(fn (AccountProfile $profile) => $this->accountProfileData($profile))->values()
                : [],
        ];
    }

    protected function walletSummaryData(Wallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'currency' => $wallet->currency,
            'balance_amount' => round((float) $wallet->balance_amount, 2),
            'created_at' => $wallet->created_at?->toIso8601String(),
            'updated_at' => $wallet->updated_at?->toIso8601String(),
        ];
    }

    protected function walletTransactionData(WalletTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => round((float) $transaction->amount, 2),
            'balance_after' => round((float) $transaction->balance_after, 2),
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'meta' => $transaction->meta ?? [],
            'processed_at' => $transaction->processed_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    protected function libraryItemData(ContentEntitlement $entitlement, string $locale = 'ro'): array
    {
        $entitlement->loadMissing('content.taxonomies', 'offer');
        $content = $entitlement->content;
        $defaultLocale = in_array($content->default_locale, Content::supportedLocales(), true)
            ? $content->default_locale
            : Content::supportedLocales()[0];
        $resolvedLocale = in_array($locale, Content::supportedLocales(), true)
            ? $locale
            : $defaultLocale;

        return [
            'id' => $entitlement->id,
            'content_id' => $content->id,
            'content_slug' => $content->slug,
            'content_type' => $content->type,
            'content_title' => $content->getTranslation('title', $resolvedLocale, false)
                ?? $content->getTranslation('title', $defaultLocale, false)
                ?? $content->original_title,
            'poster_url' => $content->poster_url,
            'backdrop_url' => $content->backdrop_url,
            'offer_id' => $entitlement->offer_id,
            'offer_name' => $entitlement->offer?->name,
            'access_type' => $entitlement->access_type,
            'quality' => $entitlement->quality,
            'status' => $entitlement->isActive() ? ContentEntitlement::STATUS_ACTIVE : ContentEntitlement::STATUS_EXPIRED,
            'is_active' => $entitlement->isActive(),
            'currency' => $entitlement->currency,
            'price_amount' => round((float) $entitlement->price_amount, 2),
            'granted_at' => $entitlement->granted_at?->toIso8601String(),
            'expires_at' => $entitlement->expires_at?->toIso8601String(),
            'created_at' => $entitlement->created_at?->toIso8601String(),
            'updated_at' => $entitlement->updated_at?->toIso8601String(),
        ];
    }

    protected function accountProfileData(AccountProfile $profile): array
    {
        return [
            'id' => (string) $profile->id,
            'name' => $profile->name,
            'avatar_label' => $profile->avatar_label,
            'avatar_color' => $profile->avatar_color,
            'is_kids' => $profile->is_kids,
            'is_default' => $profile->is_default,
            'sort_order' => $profile->sort_order,
            'favorite_slugs' => $profile->relationLoaded('favorites')
                ? $profile->favorites->pluck('slug')->values()
                : [],
            'created_at' => $profile->created_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }

    protected function favoriteMapData(Collection $profiles): array
    {
        return $profiles
            ->mapWithKeys(fn (AccountProfile $profile) => [
                (string) $profile->id => $profile->relationLoaded('favorites')
                    ? $profile->favorites->pluck('slug')->values()->all()
                    : [],
            ])
            ->all();
    }

    protected function roleData(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_default' => $role->is_default,
            'admin_panel_access' => $role->admin_panel_access,
            'permission_codes' => $role->permissions->pluck('code')->values(),
            'permission_ids' => $role->permissions->pluck('id')->values(),
        ];
    }

    protected function permissionData(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'code' => $permission->code,
            'name' => $permission->name,
            'group' => $permission->group,
            'description' => $permission->description,
            'is_system' => $permission->is_system,
        ];
    }

    protected function invitationData(Invitation $invitation, ?Collection $roles = null): array
    {
        $roles ??= Role::query()
            ->whereIn('id', $invitation->role_ids ?? [])
            ->get();

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'status' => $invitation->status,
            'role_ids' => $roles->pluck('id')->values(),
            'role_names' => $roles->pluck('name')->values(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }

    protected function taxonomyData(Taxonomy $taxonomy, string $locale = 'ro'): array
    {
        return [
            'id' => $taxonomy->id,
            'type' => $taxonomy->type,
            'slug' => $taxonomy->slug,
            'active' => $taxonomy->active,
            'color' => $taxonomy->color,
            'content_count' => $taxonomy->content_count,
            'sort_order' => $taxonomy->sort_order,
            'name' => $taxonomy->getTranslations('name'),
            'description' => $taxonomy->getTranslations('description'),
            'localized_name' => $taxonomy->getTranslation('name', $locale, false)
                ?? $taxonomy->getTranslation('name', 'ro', false)
                ?? $taxonomy->slug,
            'localized_description' => $taxonomy->getTranslation('description', $locale, false)
                ?? $taxonomy->getTranslation('description', 'ro', false),
            'created_at' => $taxonomy->created_at?->toIso8601String(),
            'updated_at' => $taxonomy->updated_at?->toIso8601String(),
        ];
    }

    protected function contentData(Content $content, string $locale = 'ro'): array
    {
        $content->loadMissing('taxonomies', 'offers', 'formats', 'rightsWindows', 'subtitleTracks', 'creators', 'premiereEvents');
        $taxonomies = $content->taxonomies;
        $offers = $content->offers;
        $formats = $content->formats;
        $rightsWindows = $content->rightsWindows;
        $subtitleTracks = $content->subtitleTracks;
        $creators = $content->creators;
        $premiereEvents = $content->premiereEvents;
        $castMembers = collect($content->cast_members ?? [])->sortBy('sort_order')->values();
        $crewMembers = collect($content->crew_members ?? [])->sortBy('sort_order')->values();
        $videos = collect($content->videos ?? [])->sortBy('sort_order')->values();
        $primaryVideo = $videos->firstWhere('is_primary', true)
            ?? $videos->firstWhere('type', 'trailer')
            ?? $videos->first();
        $seasonRecords = collect($content->seasons ?? [])->sortBy('sort_order')->values();
        $activeOffers = $offers
            ->filter(fn (Offer $offer): bool => $offer->isCurrentlyAvailable())
            ->values();
        $hasFreeOffer = $offers->contains(fn (Offer $offer): bool => $offer->offer_type === Offer::TYPE_FREE);
        $availableQualities = $activeOffers->pluck('quality')->filter()->unique()->values();
        $fallbackQualities = collect($content->available_qualities ?? [])
            ->filter()
            ->unique()
            ->values();
        $resolvedQualities = $availableQualities->isNotEmpty() ? $availableQualities : $fallbackQualities;
        $resolvedIsFree = (bool) $content->is_free || $hasFreeOffer;
        $lowestOfferPrice = $resolvedIsFree
            ? 0
            : ($activeOffers->isNotEmpty()
            ? (float) $activeOffers->min('price_amount')
            : null);
        $countryOptions = Content::countryOptions();
        $countryCode = $content->country_code;
        $defaultLocale = in_array($content->default_locale, Content::supportedLocales(), true)
            ? $content->default_locale
            : Content::supportedLocales()[0];
        $resolvedLocale = in_array($locale, Content::supportedLocales(), true)
            ? $locale
            : $defaultLocale;
        $seasons = $seasonRecords
            ->map(function (array $season) use ($defaultLocale, $resolvedLocale) {
                $title = data_get($season, 'title');
                $description = data_get($season, 'description');
                $seasonNumber = (int) data_get($season, 'season_number', 1);
                $seasonId = (string) (data_get($season, 'id') ?: "season-{$seasonNumber}");

                return [
                    'id' => $seasonId,
                    'season_number' => $seasonNumber,
                    'title' => $this->translatableValueOrNull($title),
                    'localized_title' => $this->localizedValue($title, $resolvedLocale, $defaultLocale),
                    'description' => $this->translatableValueOrNull($description),
                    'localized_description' => $this->localizedValue($description, $resolvedLocale, $defaultLocale),
                    'poster_url' => data_get($season, 'poster_url'),
                    'sort_order' => (int) data_get($season, 'sort_order', 0),
                    'episodes' => collect(data_get($season, 'episodes', []))
                        ->sortBy('sort_order')
                        ->values()
                        ->map(function (array $episode) use ($defaultLocale, $resolvedLocale, $seasonNumber) {
                            $title = data_get($episode, 'title');
                            $description = data_get($episode, 'description');
                            $episodeNumber = (int) data_get($episode, 'episode_number', 1);
                            $episodeId = (string) (data_get($episode, 'id') ?: "season-{$seasonNumber}-episode-{$episodeNumber}");

                            return [
                                'id' => $episodeId,
                                'episode_number' => $episodeNumber,
                                'title' => $this->translatableValue($title),
                                'localized_title' => $this->localizedValue($title, $resolvedLocale, $defaultLocale),
                                'description' => $this->translatableValueOrNull($description),
                                'localized_description' => $this->localizedValue($description, $resolvedLocale, $defaultLocale),
                                'runtime_minutes' => data_get($episode, 'runtime_minutes'),
                                'thumbnail_url' => data_get($episode, 'thumbnail_url'),
                                'backdrop_url' => data_get($episode, 'backdrop_url'),
                                'video_url' => data_get($episode, 'video_url'),
                                'trailer_url' => data_get($episode, 'trailer_url'),
                                'sort_order' => (int) data_get($episode, 'sort_order', 0),
                            ];
                        })
                        ->all(),
                ];
            })
            ->values();

        return [
            'id' => $content->id,
            'type' => $content->type,
            'slug' => $content->slug,
            'default_locale' => $defaultLocale,
            'status' => $content->status,
            'original_title' => $content->original_title,
            'title' => $content->getTranslations('title'),
            'tagline' => $content->getTranslations('tagline'),
            'short_description' => $content->getTranslations('short_description'),
            'description' => $content->getTranslations('description'),
            'editor_notes' => $content->getTranslations('editor_notes'),
            'meta_title' => $content->getTranslations('meta_title'),
            'meta_description' => $content->getTranslations('meta_description'),
            'localized_title' => $content->getTranslation('title', $resolvedLocale, false)
                ?? $content->getTranslation('title', $defaultLocale, false)
                ?? $content->original_title,
            'localized_short_description' => $content->getTranslation('short_description', $resolvedLocale, false)
                ?? $content->getTranslation('short_description', $defaultLocale, false),
            'release_year' => $content->release_year,
            'country_code' => $countryCode,
            'country_name' => $countryCode ? ($countryOptions[$countryCode] ?? $countryCode) : null,
            'imdb_rating' => $content->imdb_rating,
            'platform_rating' => $content->platform_rating,
            'runtime_minutes' => $content->runtime_minutes,
            'age_rating' => $content->age_rating,
            'poster_url' => $content->poster_url,
            'backdrop_url' => $content->backdrop_url,
            'hero_desktop_url' => $content->hero_desktop_url,
            'hero_mobile_url' => $content->hero_mobile_url,
            'trailer_url' => $content->trailer_url ?: data_get($primaryVideo, 'video_url'),
            'preview_images' => $content->preview_images ?? [],
            'cast' => $castMembers
                ->map(function (array $member) use ($defaultLocale, $resolvedLocale) {
                    $creditType = (string) (data_get($member, 'credit_type') ?: 'lead_actor');
                    $characterName = data_get($member, 'character_name', data_get($member, 'role'));

                    return [
                        'id' => (string) data_get($member, 'id'),
                        'name' => data_get($member, 'name'),
                        'credit_type' => $creditType,
                        'credit_type_label' => Content::localizedOptionLabel(
                            Content::castCreditTypeTranslations(),
                            $creditType,
                            $resolvedLocale,
                            $defaultLocale,
                        ),
                        'character_name' => $this->translatableValue($characterName),
                        'localized_character_name' => $this->localizedValue($characterName, $resolvedLocale, $defaultLocale),
                        'role' => $this->localizedValue($characterName, $resolvedLocale, $defaultLocale),
                        'avatar_url' => data_get($member, 'avatar_url'),
                        'sort_order' => (int) data_get($member, 'sort_order', 0),
                    ];
                })
                ->values(),
            'crew' => $crewMembers
                ->map(function (array $member) use ($defaultLocale, $resolvedLocale) {
                    $creditType = (string) (data_get($member, 'credit_type') ?: 'director');
                    $jobTitle = data_get($member, 'job_title', data_get($member, 'job'));

                    return [
                        'id' => (string) data_get($member, 'id'),
                        'name' => data_get($member, 'name'),
                        'credit_type' => $creditType,
                        'credit_type_label' => Content::localizedOptionLabel(
                            Content::crewCreditTypeTranslations(),
                            $creditType,
                            $resolvedLocale,
                            $defaultLocale,
                        ),
                        'job_title' => $this->translatableValue($jobTitle),
                        'localized_job_title' => $this->localizedValue($jobTitle, $resolvedLocale, $defaultLocale),
                        'job' => $this->localizedValue($jobTitle, $resolvedLocale, $defaultLocale),
                        'avatar_url' => data_get($member, 'avatar_url'),
                        'sort_order' => (int) data_get($member, 'sort_order', 0),
                    ];
                })
                ->values(),
            'videos' => $videos
                ->map(function (array $video) use ($defaultLocale, $resolvedLocale) {
                    $title = data_get($video, 'title');
                    $description = data_get($video, 'description');

                    return [
                        'id' => (string) data_get($video, 'id'),
                        'type' => data_get($video, 'type'),
                        'title' => $this->translatableValue($title),
                        'localized_title' => $this->localizedValue($title, $resolvedLocale, $defaultLocale),
                        'description' => $this->translatableValueOrNull($description),
                        'localized_description' => $this->localizedValue($description, $resolvedLocale, $defaultLocale),
                        'video_url' => data_get($video, 'video_url'),
                        'thumbnail_url' => data_get($video, 'thumbnail_url'),
                        'duration_seconds' => data_get($video, 'duration_seconds'),
                        'is_primary' => (bool) data_get($video, 'is_primary', false),
                        'sort_order' => (int) data_get($video, 'sort_order', 0),
                    ];
                })
                ->values(),
            'seasons' => $seasons->all(),
            'seasons_count' => $seasons->count(),
            'episodes_count' => $seasons->sum(fn (array $season): int => count($season['episodes'] ?? [])),
            'subtitle_locales' => $content->subtitle_locales ?? [],
            'available_qualities' => $resolvedQualities->values(),
            'is_featured' => $content->is_featured,
            'is_trending' => $content->is_trending,
            'is_free' => $resolvedIsFree,
            'price_amount' => (float) ($content->price_amount ?? 0),
            'currency' => $content->currency ?: Content::DEFAULT_CURRENCY,
            'rental_days' => $content->rental_days,
            'lowest_price' => $resolvedIsFree
                ? 0
                : ($lowestOfferPrice ?? (float) ($content->price_amount ?? 0)),
            'offers_count' => $offers->count(),
            'active_offers_count' => $activeOffers->count(),
            'sort_order' => $content->sort_order,
            'canonical_url' => $content->canonical_url,
            'published_at' => $content->published_at?->toIso8601String(),
            'taxonomy_ids' => $taxonomies->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'genres' => $taxonomies
                ->where('type', Taxonomy::TYPE_GENRE)
                ->map(fn (Taxonomy $taxonomy) => $this->taxonomyData($taxonomy, $resolvedLocale))
                ->values(),
            'collections' => $taxonomies
                ->where('type', Taxonomy::TYPE_COLLECTION)
                ->map(fn (Taxonomy $taxonomy) => $this->taxonomyData($taxonomy, $resolvedLocale))
                ->values(),
            'tags' => $taxonomies
                ->where('type', Taxonomy::TYPE_TAG)
                ->map(fn (Taxonomy $taxonomy) => $this->taxonomyData($taxonomy, $resolvedLocale))
                ->values(),
            'badges' => $taxonomies
                ->where('type', Taxonomy::TYPE_BADGE)
                ->map(fn (Taxonomy $taxonomy) => $this->taxonomyData($taxonomy, $resolvedLocale))
                ->values(),
            'offers' => $offers
                ->map(fn (Offer $offer) => $this->offerData($offer, $resolvedLocale))
                ->values(),
            'content_formats' => $formats
                ->map(fn ($format) => [
                    'id' => $format->id,
                    'quality' => $format->quality,
                    'format_type' => $format->format_type,
                    'bunny_library_id' => $format->bunny_library_id,
                    'bunny_video_id' => $format->bunny_video_id,
                    'stream_url' => $format->stream_url,
                    'token_path' => $format->token_path,
                    'drm_policy' => $format->drm_policy,
                    'is_active' => $format->is_active,
                    'is_default' => $format->is_default,
                    'sort_order' => $format->sort_order,
                    'meta' => $format->meta ?? [],
                ])
                ->values(),
            'rights_windows' => $rightsWindows
                ->map(fn ($window) => [
                    'id' => $window->id,
                    'content_format_id' => $window->content_format_id,
                    'content_format_quality' => optional($formats->firstWhere('id', $window->content_format_id))->quality,
                    'country_code' => $window->country_code,
                    'is_allowed' => $window->is_allowed,
                    'starts_at' => $window->starts_at?->toIso8601String(),
                    'ends_at' => $window->ends_at?->toIso8601String(),
                    'meta' => $window->meta ?? [],
                ])
                ->values(),
            'subtitle_tracks' => $subtitleTracks
                ->map(fn ($track) => [
                    'id' => $track->id,
                    'content_format_id' => $track->content_format_id,
                    'content_format_quality' => optional($formats->firstWhere('id', $track->content_format_id))->quality,
                    'locale' => $track->locale,
                    'label' => $track->label,
                    'file_url' => $track->file_url,
                    'is_default' => $track->is_default,
                    'sort_order' => $track->sort_order,
                ])
                ->values(),
            'premiere_events' => $premiereEvents
                ->map(fn (PremiereEvent $event) => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'is_active' => $event->is_active,
                    'is_public' => $event->is_public,
                    'meta' => $event->meta ?? [],
                ])
                ->values(),
            'creators' => $creators
                ->map(fn ($creator) => [
                    'id' => $creator->id,
                    'name' => $creator->name,
                    'email' => $creator->email,
                    'company_name' => $creator->company_name,
                    'platform_fee_percent' => $creator->platform_fee_percent,
                    'assignment_role' => data_get($creator->pivot, 'role', 'owner'),
                    'is_primary' => (bool) data_get($creator->pivot, 'is_primary', false),
                ])
                ->values(),
            'creator_ids' => $creators->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'created_at' => $content->created_at?->toIso8601String(),
            'updated_at' => $content->updated_at?->toIso8601String(),
        ];
    }

    protected function publicContentCardData(Content $content, string $locale = 'ro'): array
    {
        $content->loadMissing('premiereEvents');
        $adminData = $this->contentData($content, $locale);
        $nextPremiere = $content->premiereEvents
            ->where('is_active', true)
            ->where('is_public', true)
            ->filter(fn (PremiereEvent $event): bool => $event->starts_at !== null && $event->starts_at->isFuture())
            ->sortBy('starts_at')
            ->first();

        return [
            'id' => (string) $adminData['id'],
            'type' => $adminData['type'],
            'title' => $adminData['localized_title'],
            'original_title' => $adminData['original_title'],
            'slug' => $adminData['slug'],
            'short_description' => $adminData['localized_short_description'] ?? '',
            'tagline' => data_get($adminData, "tagline.$locale")
                ?? data_get($adminData, 'tagline.ro')
                ?? '',
            'release_year' => $adminData['release_year'],
            'country_code' => $adminData['country_code'],
            'country_name' => $adminData['country_name'],
            'imdb_rating' => $adminData['imdb_rating'],
            'platform_rating' => $adminData['platform_rating'],
            'genres' => collect($adminData['genres'])->pluck('localized_name')->filter()->values(),
            'collections' => collect($adminData['collections'])->pluck('localized_name')->filter()->values(),
            'tags' => collect($adminData['tags'])->pluck('localized_name')->filter()->values(),
            'badges' => collect($adminData['badges'])
                ->map(fn (array $badge) => [
                    'id' => (string) $badge['id'],
                    'slug' => $badge['slug'],
                    'label' => $badge['localized_name'],
                    'color' => $badge['color'],
                ])
                ->values(),
            'is_featured' => $adminData['is_featured'],
            'is_trending' => $adminData['is_trending'],
            'is_free' => $adminData['is_free'],
            'poster_url' => $adminData['poster_url'],
            'backdrop_url' => $adminData['backdrop_url'],
            'hero_desktop_url' => $adminData['hero_desktop_url'],
            'hero_mobile_url' => $adminData['hero_mobile_url'],
            'trailer_url' => $adminData['trailer_url'],
            'premiere_event' => $nextPremiere ? [
                'id' => $nextPremiere->id,
                'title' => $nextPremiere->title,
                'starts_at' => $nextPremiere->starts_at?->toIso8601String(),
                'ends_at' => $nextPremiere->ends_at?->toIso8601String(),
            ] : null,
            'lowest_price' => (float) ($adminData['lowest_price'] ?? 0),
            'currency' => $adminData['currency'],
            'available_qualities' => $adminData['available_qualities'],
        ];
    }

    protected function publicContentDetailData(Content $content, string $locale = 'ro'): array
    {
        $cardData = $this->publicContentCardData($content, $locale);
        $adminData = $this->contentData($content, $locale);
        $description = data_get($adminData, "description.$locale")
            ?? data_get($adminData, "description.{$adminData['default_locale']}")
            ?? '';
        $editorNotes = data_get($adminData, "editor_notes.$locale")
            ?? data_get($adminData, "editor_notes.{$adminData['default_locale']}");
        $metaTitle = data_get($adminData, "meta_title.$locale")
            ?? data_get($adminData, "meta_title.{$adminData['default_locale']}");
        $metaDescription = data_get($adminData, "meta_description.$locale")
            ?? data_get($adminData, "meta_description.{$adminData['default_locale']}");
        $offers = collect($adminData['offers'] ?? [])
            ->filter(fn (array $offer): bool => (bool) ($offer['is_currently_available'] ?? false))
            ->map(fn (array $offer): array => collect($offer)->except(['playback_url'])->all())
            ->values();
        $cast = collect($adminData['cast'] ?? [])
            ->map(fn (array $member): array => [
                'id' => (string) data_get($member, 'id'),
                'name' => data_get($member, 'name'),
                'credit_type' => data_get($member, 'credit_type'),
                'credit_type_label' => data_get($member, 'credit_type_label'),
                'role' => data_get($member, 'localized_character_name', data_get($member, 'role')),
                'avatar_url' => data_get($member, 'avatar_url'),
                'sort_order' => (int) data_get($member, 'sort_order', 0),
            ])
            ->values()
            ->all();
        $crew = collect($adminData['crew'] ?? [])
            ->map(fn (array $member): array => [
                'id' => (string) data_get($member, 'id'),
                'name' => data_get($member, 'name'),
                'credit_type' => data_get($member, 'credit_type'),
                'credit_type_label' => data_get($member, 'credit_type_label'),
                'job' => data_get($member, 'localized_job_title', data_get($member, 'job')),
                'avatar_url' => data_get($member, 'avatar_url'),
                'sort_order' => (int) data_get($member, 'sort_order', 0),
            ])
            ->values()
            ->all();
        $videos = collect($adminData['videos'] ?? [])
            ->map(fn (array $video): array => [
                'id' => (string) data_get($video, 'id'),
                'type' => data_get($video, 'type'),
                'title' => data_get($video, 'localized_title', data_get($video, 'title')),
                'description' => data_get($video, 'localized_description'),
                'video_url' => data_get($video, 'video_url'),
                'thumbnail_url' => data_get($video, 'thumbnail_url'),
                'duration_seconds' => data_get($video, 'duration_seconds'),
                'is_primary' => (bool) data_get($video, 'is_primary', false),
                'sort_order' => (int) data_get($video, 'sort_order', 0),
            ])
            ->values()
            ->all();
        $seasons = collect($adminData['seasons'] ?? [])
            ->map(fn (array $season): array => [
                'id' => (string) data_get($season, 'id'),
                'season_number' => (int) data_get($season, 'season_number', 1),
                'title' => data_get($season, 'localized_title'),
                'description' => data_get($season, 'localized_description'),
                'poster_url' => data_get($season, 'poster_url'),
                'sort_order' => (int) data_get($season, 'sort_order', 0),
                'episodes' => collect(data_get($season, 'episodes', []))
                    ->map(fn (array $episode): array => [
                        'id' => (string) data_get($episode, 'id'),
                        'episode_number' => (int) data_get($episode, 'episode_number', 1),
                        'title' => data_get($episode, 'localized_title', data_get($episode, 'title')),
                        'description' => data_get($episode, 'localized_description'),
                        'runtime_minutes' => data_get($episode, 'runtime_minutes'),
                        'thumbnail_url' => data_get($episode, 'thumbnail_url'),
                        'backdrop_url' => data_get($episode, 'backdrop_url'),
                        'video_url' => data_get($episode, 'video_url'),
                        'trailer_url' => data_get($episode, 'trailer_url'),
                        'sort_order' => (int) data_get($episode, 'sort_order', 0),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            ...$cardData,
            'description' => $description,
            'editor_notes' => $editorNotes,
            'runtime_minutes' => $adminData['runtime_minutes'],
            'age_rating' => $adminData['age_rating'],
            'default_locale' => $adminData['default_locale'],
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'canonical_url' => $adminData['canonical_url'],
            'preview_images' => $adminData['preview_images'],
            'cast' => $cast,
            'crew' => $crew,
            'videos' => $videos,
            'seasons' => $seasons,
            'seasons_count' => $adminData['seasons_count'],
            'episodes_count' => $adminData['episodes_count'],
            'subtitle_locales' => $adminData['subtitle_locales'],
            'offers' => ($adminData['is_free'] ?? false) && $offers->isEmpty()
                ? [[
                    'id' => "{$content->id}-free",
                    'name' => 'Free access',
                    'offer_type' => 'free',
                    'offer_type_label' => 'Free',
                    'quality' => data_get($adminData, 'available_qualities.0', 'HD'),
                    'currency' => $content->currency ?: Content::DEFAULT_CURRENCY,
                    'price_amount' => 0,
                    'rental_days' => null,
                    'access_label' => 'Free',
                    'starts_at' => null,
                    'ends_at' => null,
                    'is_active' => true,
                    'is_currently_available' => true,
                    'availability_status' => 'active',
                ]]
                : $offers->values()->all(),
            'has_active_access' => (bool) ($adminData['is_free'] ?? false),
            'access_expires_at' => null,
            'parent' => null,
            'children' => [],
        ];
    }

    protected function translatableValue(mixed $value): array
    {
        if (is_array($value) && $this->hasLocalizedPayload($value)) {
            return collect(Content::supportedLocales())
                ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($value[$locale] ?? ''))])
                ->all();
        }

        $stringValue = trim((string) $value);

        return collect(Content::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => $stringValue])
            ->all();
    }

    protected function translatableValueOrNull(mixed $value): ?array
    {
        $translations = $this->translatableValue($value);

        return collect($translations)->filter(fn (?string $item): bool => filled($item))->isNotEmpty()
            ? $translations
            : null;
    }

    protected function localizedValue(mixed $value, string $locale, string $fallbackLocale): ?string
    {
        if (is_array($value) && $this->hasLocalizedPayload($value)) {
            $resolved = trim((string) ($value[$locale] ?? ''))
                ?: trim((string) ($value[$fallbackLocale] ?? ''));

            return $resolved !== '' ? $resolved : null;
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : null;
    }

    protected function hasLocalizedPayload(array $value): bool
    {
        return collect(Content::supportedLocales())
            ->contains(fn (string $locale): bool => array_key_exists($locale, $value));
    }

    protected function offerData(Offer $offer, string $locale = 'ro'): array
    {
        $offer->loadMissing('content');
        $content = $offer->content;
        $contentTitle = $content?->getTranslation('title', $locale, false)
            ?? $content?->getTranslation('title', $content?->default_locale ?: 'ro', false)
            ?? $content?->original_title;
        $availabilityStatus = $offer->is_active ? 'active' : 'inactive';

        if ($offer->is_active && $offer->starts_at !== null && $offer->starts_at->isFuture()) {
            $availabilityStatus = 'scheduled';
        }

        if ($offer->is_active && $offer->ends_at !== null && $offer->ends_at->isPast()) {
            $availabilityStatus = 'expired';
        }

        return [
            'id' => $offer->id,
            'content_id' => $offer->content_id,
            'content_slug' => $content?->slug,
            'content_title' => $contentTitle,
            'content_type' => $content?->type,
            'poster_url' => $content?->poster_url,
            'name' => $offer->name,
            'offer_type' => $offer->offer_type,
            'offer_type_label' => Offer::typeLabels()[$offer->offer_type] ?? ucfirst($offer->offer_type),
            'quality' => $offer->quality,
            'currency' => $offer->currency,
            'price_amount' => (float) $offer->price_amount,
            'playback_url' => $offer->playback_url,
            'rental_days' => $offer->rental_days,
            'access_label' => $offer->offer_type === Offer::TYPE_FREE
                ? 'Free'
                : ($offer->offer_type === Offer::TYPE_LIFETIME
                    ? 'Forever'
                    : "{$offer->rental_days} days"),
            'is_active' => $offer->is_active,
            'is_currently_available' => $offer->isCurrentlyAvailable(),
            'availability_status' => $availabilityStatus,
            'starts_at' => $offer->starts_at?->toIso8601String(),
            'ends_at' => $offer->ends_at?->toIso8601String(),
            'sort_order' => $offer->sort_order,
            'created_at' => $offer->created_at?->toIso8601String(),
            'updated_at' => $offer->updated_at?->toIso8601String(),
        ];
    }
}
