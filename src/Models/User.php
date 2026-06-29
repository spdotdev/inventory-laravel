<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property HouseholdUserPivot|null $pivot
 */
class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'inventory_users';

    // google_id and avatar_url are deliberately NOT mass-assignable — they are
    // set explicitly from verified Google claims, never from request input.
    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Households this user belongs to.
     *
     * @return BelongsToMany<Household, $this, HouseholdUserPivot>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(
            Household::class,
            'inventory_household_user',
            'user_id',
            'household_id',
        )->using(HouseholdUserPivot::class)->withPivot('joined_at');
    }
}
