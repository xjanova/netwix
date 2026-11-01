<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Http\Request;

/** The guest preview ends at the admin-configured free episode number; signing in lifts it. */
class GuestWatch
{
    private ?int $limit = null;

    public function __construct(private readonly Membership $membership) {}

    public function limit(): int
    {
        return $this->limit ??= max(0, (int) $this->membership->config()['free_episodes']);
    }

    public function requiresSignup(?User $viewer, Episode $episode): bool
    {
        return $viewer === null && (int) $episode->number > $this->limit();
    }

    public function signupUrl(Content $content, ?Episode $episode = null): string
    {
        return route('register', ['next' => route('watch', [$content, $episode], false)]);
    }

    public function rememberDestination(Request $request): void
    {
        $next = $request->query('next');
        if (! is_string($next)) {
            return;
        }

        // Rebuild a real watch route from its models; never store a supplied redirect URL.
        $parts = explode('/', $next);
        if (! in_array(count($parts), [3, 4], true) || $parts[0] !== '' || $parts[1] !== 'watch') {
            return;
        }

        $content = Content::where('slug', rawurldecode($parts[2]))->where('is_published', true)->first();
        if (! $content) {
            return;
        }

        $episode = null;
        if (isset($parts[3])) {
            if (! ctype_digit($parts[3])) {
                return;
            }
            $episode = $content->episodes()->find($parts[3]);
            if (! $episode) {
                return;
            }
        }
        $request->session()->put('url.intended', route('watch', [$content, $episode]));
    }
}
