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
        )->using(HouseholdUserPivot::class)->withPivot('joined_at');
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
