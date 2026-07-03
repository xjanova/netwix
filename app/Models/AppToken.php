<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A mobile-app bearer token. Store only the hash; hand the plaintext to the
 * client exactly once (at issue time).
 */
class AppToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Mint a new token for a user; returns the plaintext (shown once). */
    public static function issue(User $user, ?string $name = null): string
    {
        $plain = Str::random(64);
        static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
        ]);

        return $plain;
    }

    /** Resolve a plaintext bearer to its user, or null. Touches last_used_at. */
    public static function resolveUser(string $plain): ?User
    {
        $row = static::where('token_hash', hash('sha256', $plain))->first();
        if (! $row) {
            return null;
        }
        $row->forceFill(['last_used_at' => now()])->saveQuietly();

        return $row->user;
    }

    /** Revoke a plaintext bearer (logout). */
    public static function revoke(string $plain): void
    {
        static::where('token_hash', hash('sha256', $plain))->delete();
    }
}
