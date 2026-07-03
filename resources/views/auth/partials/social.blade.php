{{-- Google / LINE social sign-in.
     Each button only renders once that provider's OAuth credentials are set
     (config/services.php), so a fresh install never shows a button that would
     500 on click. Google is built into laravel/socialite; LINE is registered
     in AppServiceProvider via the socialiteproviders/line package. --}}
@php($hasGoogle = filled(config('services.google.client_id')))
@php($hasLine = filled(config('services.line.client_id')))

@if ($hasGoogle || $hasLine)
    <div class="my-6 flex items-center gap-3 text-[12.5px] text-cream/40">
        <span class="h-px flex-1 bg-white/10"></span>
        หรือดำเนินการต่อด้วย
        <span class="h-px flex-1 bg-white/10"></span>
    </div>

    <div class="flex flex-col gap-3">
        @if ($hasGoogle)
            <a href="{{ route('social.redirect', 'google') }}"
               class="flex items-center justify-center gap-3 rounded-lg border border-white/15 bg-white px-4 py-3 text-sm font-semibold text-[#1f1f1f] transition hover:bg-white/90">
                <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                    <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                    <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238C29.211 35.091 26.715 36 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                    <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303c-.792 2.237-2.231 4.166-4.087 5.571l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
                </svg>
                ดำเนินการต่อด้วย Google
            </a>
        @endif

        @if ($hasLine)
            <a href="{{ route('social.redirect', 'line') }}"
               class="flex items-center justify-center gap-3 rounded-lg bg-[#06C755] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#05b34c]">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 3C6.48 3 2 6.63 2 11.02c0 3.93 3.32 7.22 7.8 7.85.3.06.72.2.82.46.09.24.06.6.03.85l-.13.79c-.04.24-.19.94.83.51 1.02-.43 5.5-3.24 7.5-5.55C20.5 14.42 22 12.86 22 11.02 22 6.63 17.52 3 12 3zM8.16 13.2H6.3a.4.4 0 0 1-.4-.4V9.1a.4.4 0 0 1 .8 0v3.3h1.46a.4.4 0 0 1 0 .8zm1.6-.4a.4.4 0 0 1-.8 0V9.1a.4.4 0 0 1 .8 0v3.7zm4.15 0a.4.4 0 0 1-.27.38.4.4 0 0 1-.45-.14L11.4 10.6v2.2a.4.4 0 0 1-.8 0V9.1a.4.4 0 0 1 .72-.24l1.99 2.85V9.1a.4.4 0 0 1 .8 0v3.7zm2.74-2.25a.4.4 0 0 1 0 .8h-1.46v.85h1.46a.4.4 0 0 1 0 .8h-1.86a.4.4 0 0 1-.4-.4V9.1a.4.4 0 0 1 .4-.4h1.86a.4.4 0 0 1 0 .8h-1.46v.85h1.46z"/>
                </svg>
                ดำเนินการต่อด้วย LINE
            </a>
        @endif
    </div>
@endif
