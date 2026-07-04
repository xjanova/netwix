<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** Keys whose values are encrypted at rest (never cached or stored in plaintext). */
    public const SECRET_KEYS = ['google_client_secret', 'line_client_secret', 'app_github_token', 'turnstile_secret'];

    private const CACHE_KEY = 'settings.map';

    /** Raw key=>value map (secret values stay ENCRYPTED here — cache never holds plaintext). */
    private static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::pluck('value', 'key')->all());
    }

    /** Read a setting, decrypting secret keys. Returns $default when unset/blank/undecryptable. */
    public static function get(string $key, $default = null)
    {
        $raw = static::map()[$key] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (in_array($key, self::SECRET_KEYS, true)) {
            try {
                return Crypt::decryptString($raw);
            } catch (Throwable) {
                return $default;
            }
        }

        return $raw;
    }

    /** Read a boolean flag setting. Absent → $default. */
    public static function flag(string $key, bool $default = false): bool
    {
        $v = static::get($key);

        return $v === null ? $default : in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
    }

    /** Write a setting (encrypting secret keys) and invalidate the cache. */
    public static function write(string $key, ?string $value): void
    {
        if ($value !== null && in_array($key, self::SECRET_KEYS, true)) {
            $value = Crypt::encryptString($value);
        }

        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }
}
