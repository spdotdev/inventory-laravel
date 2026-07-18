<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $join_code
 * @property string|null $color
 * @property string|null $icon
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property HouseholdUserPivot|null $pivot
 */
class Household extends Model
{
    protected $table = 'inventory_households';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'join_code',
        'color',
        'icon',
    ];

    /**
     * Generate a unique, human-friendly join code (e.g. ABCD-2345). Uses an
     * unambiguous alphabet (no 0/O/1/I/L) and a CSPRNG, retrying on collision.
     */
    public static function generateUniqueJoinCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;

        do {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= $alphabet[random_int(0, $max)];
            }
            $code = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        } while (static::query()->where('join_code', $code)->exists());

        return $code;
    }

    /**
     * Members of this household.
     *
     * @return BelongsToMany<User, $this, HouseholdUserPivot>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'inventory_household_user',
            'household_id',
            'user_id',
        )->using(HouseholdUserPivot::class)->withPivot('joined_at', 'role');
    }

    /**
     * Per-instance memoization of roleOf() results, keyed by user id. Avoids
     * repeated queries when multiple policy checks / resource fields read the
     * same user's role off the same Household instance within one request.
     *
     * @var array<int|string, string|null>
     */
    private array $roleOfCache = [];

    /**
     * Does this household currently have an Owner? Should always be true for a
     * household with members (the single-Owner invariant) — callers use this to
     * HEAL an owner-less household (e.g. one created by the artisan command with
     * no members) by promoting the first member to arrive, mirroring the
     * backfill migration's earliest-member rule.
     */
    public function hasOwner(): bool
    {
        return $this->users()->wherePivot('role', 'owner')->exists();
    }

    /**
     * The given user's role in this household, or null if they aren't a member.
     * The one place every policy method and resource reads role from — no other
     * code should query `inventory_household_user.role` directly.
     */
    public function roleOf(User $user): ?string
    {
        $key = $user->getKey();

        if (array_key_exists($key, $this->roleOfCache)) {
            return $this->roleOfCache[$key];
        }

        // When this Household was loaded off $user->households() (e.g. the
        // household-index listing), the pivot for exactly that user is
        // already hydrated onto the model — reuse it instead of firing one
        // extra query per household to avoid an N+1 across the listing.
        if ($this->relationLoaded('pivot')) {
            /** @var HouseholdUserPivot $loadedPivot */
            $loadedPivot = $this->getRelation('pivot');

            if ((string) $loadedPivot->user_id === (string) $key) {
                return $this->roleOfCache[$key] = $loadedPivot->role;
            }
        }

        /** @var HouseholdUserPivot|null $pivot */
        $pivot = $this->users()->wherePivot('user_id', $key)->first()?->pivot;

        return $this->roleOfCache[$key] = $pivot?->role;
    }

    /**
     * @return HasMany<StorageLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(StorageLocation::class, 'household_id');
    }

    /**
     * Shelves across all of the household's locations. Backs scoped binding for
     * the /households/{household}/shelves/{shelf}/... routes.
     *
     * Laravel scopes the THROUGH-parent's soft deletes automatically: when the
     * intermediate model (StorageLocation) uses SoftDeletes,
     * HasOneOrManyThrough::performJoin() adds a SoftDeletableHasManyThrough
     * global scope, so a shelf inside a soft-deleted location is already
     * unreachable here with no explicit whereNull needed —
     * withTrashedParents() exists precisely to opt back out of that.
     *
     * @return HasManyThrough<Shelf, StorageLocation, $this>
     */
    public function shelves(): HasManyThrough
    {
        return $this->hasManyThrough(
            Shelf::class,
            StorageLocation::class,
            'household_id', // FK on storage_locations
            'location_id',  // FK on shelves
            'id',
            'id',
        );
    }
}
