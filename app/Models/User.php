<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'phone',
        'address',
        'plan',
        'provider',
        'provider_id',
        'avatar',
        'referral_code',
        'referred_by',
        'pro_until',
        'coins',
        'gold_coins',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Mirror the column default in memory. Eloquent does not read DB defaults back after an insert,
     * so a freshly created social account had is_active = null → false, and SocialController's
     * suspended-account check turned away every first-time Google / LINE sign-up.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pro_until' => 'datetime',
            'coins' => 'integer',
            'gold_coins' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function appTokens(): HasMany
    {
        return $this->hasMany(AppToken::class);
    }

    /** The user's first profile, creating a starter one if none exists. */
    public function defaultProfile(): Profile
    {
        return $this->profiles()->oldest('id')->first()
            ?? $this->profiles()->create([
                'name' => \Illuminate\Support\Str::limit($this->name, 20, ''),
                'avatar_color' => '#b026ff',
            ]);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Pro membership (paid plan or an active time-limited grant). Delegates to the Membership rules. */
    public function isProMember(): bool
    {
        return app(\App\Services\Membership::class)->isPro($this);
    }
}
