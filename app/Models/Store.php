<?php

namespace App\Models;

use App\Enums\BusinessType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'phone',
        'email',
        'address',
        'city',
        'province',
        'postal_code',
        'logo',
        'is_active',
        'business_type',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'business_type' => BusinessType::class,
        ];
    }

    /**
     * Store owner.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'owner_id'
        );
    }

    /**
     * Users who have access to this store.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'store_user'
        )
            ->withPivot([
                'role',
                'is_active',
            ])
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }
}
