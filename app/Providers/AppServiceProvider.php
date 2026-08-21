<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-entered credentials (settings table) override the .env defaults
        // at runtime, so Google/LINE login can be configured from the admin panel
        // without shell access.
        $this->applyDynamicConfig();

        /*
         * Catalogue listing limiter.
         *
         * `/api/app/titles` accepts `per=100`, so the whole 19,351-title catalogue was 194 requests —
         * about two minutes at the old blanket 90/min, and invisible to every scraping rule we have
         * (no `stream/` in the path, no ascending ids, a browser User-Agent and a Referer are all it
         * takes to stay silent). It was the cheapest way to take the entire catalogue and nothing
         * watched it.
         *
         * An identified app client keeps a generous allowance, because that is a client we can revoke.
         * An anonymous one gets enough to browse — ten pages a minute is far more scrolling than a
         * person does — and a full sweep goes from two minutes to over an hour, at which point it is
         * cheaper to ask us. Deliberately a limit, not a block: no real reader is ever refused.
         */
        RateLimiter::for('catalog', function (Request $request) {
            $identified = $request->attributes->has('app_token') || $request->user() !== null;

            return $identified
                ? Limit::perMinute(120)->by('cat:u:'.($request->user()?->id ?? sha1((string) $request->bearerToken())))
                : Limit::perMinute(30)->by('cat:ip:'.$request->ip());
        });

        // LINE login runs through the socialiteproviders/line package, which
        // plugs into Socialite via an event. Google is built into
        // laravel/socialite and needs no registration. Guarded with class_exists
        // so the app never fatals when the optional package isn't installed yet
        // (it is composer-required on the server — see config/services.php).
        if (class_exists(\SocialiteProviders\Line\Provider::class)) {
            Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
                $event->extendSocialite('line', \SocialiteProviders\Line\Provider::class);
            });
        }
    }

    /**
     * Merge admin-editable settings into config(). Wrapped in try/catch so a
     * fresh install (settings table not migrated yet) silently falls back to
     * the .env / config defaults instead of fataling on every request.
     */
    private function applyDynamicConfig(): void
    {
        try {
            $googleId = Setting::get('google_client_id');
            $googleSecret = Setting::get('google_client_secret');
            if (filled($googleId) && filled($googleSecret)) {
                config([
                    'services.google.client_id' => $googleId,
                    'services.google.client_secret' => $googleSecret,
                    'services.google.redirect' => url('/auth/google/callback'),
                ]);
            }

            $lineId = Setting::get('line_client_id');
            $lineSecret = Setting::get('line_client_secret');
            if (filled($lineId) && filled($lineSecret)) {
                config([
                    'services.line.client_id' => $lineId,
                    'services.line.client_secret' => $lineSecret,
                    'services.line.redirect' => url('/auth/line/callback'),
                ]);
            }

            if (filled($url = Setting::get('support_line_url'))) {
                config(['services.support.line_url' => $url]);
            }
            if (filled($email = Setting::get('support_email'))) {
                config(['services.support.email' => $email]);
            }
        } catch (Throwable) {
            // settings table not available yet — use config/.env defaults.
        }
    }
}
