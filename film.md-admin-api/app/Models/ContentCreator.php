<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'user_id',
    'name',
    'email',
    'company_name',
    'person_type',
    'idno',
    'legal_address',
    'iban',
    'bank_name',
    'bank_swift',
    'is_vat_payer',
    'vat_code',
    'contact_person',
    'phone',
    'platform_fee_percent',
    'is_active',
    'meta',
])]
/**
 * A rights holder ("titular") of titles. Every title has at most one; the link lives in
 * content_creator_assignments (unique per content_id) and is only written through
 * assignTitle() / assignTitles() so that rule holds whatever screen changes it.
 */
class ContentCreator extends Model
{
    public const PERSON_TYPES = ['PF', 'PJ'];

    /** Gives a title this holder (or none), replacing whichever holder it had. */
    public static function assignTitle(int $contentId, ?int $holderId): void
    {
        DB::transaction(function () use ($contentId, $holderId): void {
            DB::table('content_creator_assignments')
                ->where('content_id', $contentId)
                ->when($holderId !== null, fn ($query) => $query->where('content_creator_id', '!=', $holderId))
                ->delete();

            if ($holderId === null) {
                return;
            }

            $exists = DB::table('content_creator_assignments')
                ->where('content_id', $contentId)
                ->where('content_creator_id', $holderId)
                ->exists();
            if (! $exists) {
                DB::table('content_creator_assignments')->insert([
                    'content_id' => $contentId,
                    'content_creator_id' => $holderId,
                    'role' => 'owner',
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Makes this holder the titular of exactly these titles: titles picked here move over from
     * their previous holder, titles no longer picked are left without one.
     *
     * @param  array<int, int|string>  $contentIds
     */
    public function assignTitles(array $contentIds): void
    {
        $ids = collect($contentIds)->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values();

        DB::transaction(function () use ($ids): void {
            DB::table('content_creator_assignments')
                ->where('content_creator_id', $this->id)
                ->whereNotIn('content_id', $ids->all())
                ->delete();

            $ids->each(fn (int $contentId) => self::assignTitle($contentId, $this->id));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_creator_assignments')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    public function monthlyStatements(): HasMany
    {
        return $this->hasMany(CreatorMonthlyStatement::class);
    }

    public function contractVersions(): HasMany
    {
        return $this->hasMany(CreatorContractVersion::class);
    }

    public function fiscalProfiles(): HasMany
    {
        return $this->hasMany(CreatorFiscalProfile::class);
    }

    protected function casts(): array
    {
        return [
            'platform_fee_percent' => 'float',
            'is_active' => 'boolean',
            'is_vat_payer' => 'boolean',
            'meta' => 'array',
        ];
    }
}
