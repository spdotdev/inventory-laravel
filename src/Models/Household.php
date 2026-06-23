<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $join_code
 */
class Household extends Model
{
    protected $table = 'inventory_households';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'join_code',
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
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'inventory_household_user',
            'household_id',
            'user_id',
        )->withPivot('joined_at');
    }

    /**
     * @return HasMany<StorageLocation, $this>
     */
    public function storageLocations(): HasMany
    {
        return $this->hasMany(StorageLocation::class, 'household_id');
    }
}
