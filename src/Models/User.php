<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $google_id
 * @property string|null $avatar_url
 */
class User extends Model
{
    protected $table = 'inventory_users';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar_url',
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
     * @return BelongsToMany<Household, $this>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(
            Household::class,
            'inventory_household_user',
            'user_id',
            'household_id',
        )->withPivot('joined_at');
    }
}
