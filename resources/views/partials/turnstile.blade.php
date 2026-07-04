{{-- Cloudflare Turnstile widget — renders only when configured (App\Support\Turnstile::enabled()).
     On real <form>s it auto-injects a hidden `cf-turnstile-response` input; AJAX callers read the
     token via window.turnstile.getResponse(). --}}
@if (\App\Support\Turnstile::enabled())
    <div class="cf-turnstile mt-4 flex justify-center" data-sitekey="{{ \App\Support\Turnstile::siteKey() }}" data-theme="dark" data-language="th" data-size="flexible"></div>
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
