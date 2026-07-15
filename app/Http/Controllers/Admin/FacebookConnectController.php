<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * "เชื่อมต่อ Facebook" for the clip auto-post pipeline — an admin-clicked OAuth flow that
 * ends with a LONG-LIVED Page access token stored (encrypted, see Setting::SECRET_KEYS)
 * in the settings table. FacebookPublisher reads it from there; no token ever needs to be
 * pasted into .env by hand.
 *
 * Flow: redirect() → FB login dialog (pages_* scopes) → callback() exchanges the code for
 * a long-lived USER token → GET /me/accounts lists the admin's pages with their PAGE tokens
 * (long-lived user token ⇒ non-expiring page tokens). One page → connected immediately;
 * several → a picker (the page list is parked in the session, tokens never hit the browser).
 *
 * Needs FB_APP_ID + FB_APP_SECRET in .env (the "NetwixAI" app) and the app's Facebook Login
 * product configured with https://<host>/admin/facebook/callback as a valid redirect URI.
 */
class FacebookConnectController extends Controller
{
    private const SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts';

    /**
     * Store the app secret pasted into the admin form (encrypted at rest). This exists so
     * the secret can travel browser → server directly instead of being copied into .env
     * by hand (or worse, through a chat/support channel).
     */
    public function storeSecret(Request $request): RedirectResponse
    {
        $request->validate(['app_secret' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/']]);
        Setting::write('fb_app_secret', (string) $request->input('app_secret'));

        return redirect()->route('admin.clip-campaigns.index')
            ->with('status', 'บันทึก App Secret แล้ว — กด "เชื่อมต่อเพจ Facebook" ต่อได้เลย');
    }

    /** Kick off the OAuth dialog. */
    public function redirect(Request $request): RedirectResponse
    {
        if (blank(config('services.facebook.app_id')) || blank($this->secret())) {
            return back()->with('status', 'ยังตั้งค่าแอพ Facebook ไม่ครบ — ต้องมี FB_APP_ID ใน .env และวาง App Secret ในช่องด้านบนก่อน');
        }

        $state = Str::random(40);
        $request->session()->put('fb_oauth_state', $state);

        return redirect()->away('https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.facebook.app_id'),
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
            'scope' => self::SCOPES,
        ]));
    }

    /** FB redirects back here with ?code — exchange it and store the page credentials. */
    public function callback(Request $request): RedirectResponse
    {
        $index = redirect()->route('admin.clip-campaigns.index');

        $expected = (string) $request->session()->pull('fb_oauth_state');
        if ($expected === '' || ! hash_equals($expected, (string) $request->input('state'))) {
            return $index->with('status', 'เชื่อมต่อไม่สำเร็จ (state ไม่ตรง — เปิดหน้าแคมเปญแล้วกดเชื่อมต่อใหม่)');
        }
        if (blank($request->input('code'))) {
            // user cancelled the dialog, or FB returned an error
            return $index->with('status', 'ยกเลิกการเชื่อมต่อ Facebook ('.($request->input('error_description') ?: 'ไม่ได้รับ code').')');
        }

        try {
            $shortToken = $this->graph('/oauth/access_token', [
                'client_id' => config('services.facebook.app_id'),
                'client_secret' => $this->secret(),
                'redirect_uri' => $this->callbackUrl(),
                'code' => (string) $request->input('code'),
            ])['access_token'] ?? null;
            if (! $shortToken) {
                throw new RuntimeException('no user token');
            }

            // Long-lived user token (~60 days) ⇒ the page tokens it mints do not expire.
            $longToken = $this->graph('/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('services.facebook.app_id'),
                'client_secret' => $this->secret(),
                'fb_exchange_token' => $shortToken,
            ])['access_token'] ?? $shortToken;

            // Keep the long-lived USER token too (encrypted): it lets us refresh page
            // tokens, inspect /me/permissions, or revoke the grant without the browser.
            Setting::write('fb_user_token', (string) $longToken);

            $pages = collect($this->graph('/me/accounts', [
                'fields' => 'id,name,access_token',
                'limit' => 100,
                'access_token' => $longToken,
            ])['data'] ?? [])->filter(fn ($p) => filled($p['id'] ?? null) && filled($p['access_token'] ?? null))->values();
        } catch (Throwable $e) {
            report($e);

            return $index->with('status', 'เชื่อมต่อไม่สำเร็จ: '.mb_substr($e->getMessage(), 0, 120));
        }

        if ($pages->isEmpty()) {
            return $index->with('status', 'บัญชีนี้ไม่มีเพจที่แอพมองเห็น — เช็คว่าตอน login ได้เลือกให้สิทธิ์เพจ แล้วลองใหม่');
        }
        if ($pages->count() === 1) {
            $this->connect($pages->first());

            return $index->with('status', 'เชื่อมต่อเพจ "'.$pages->first()['name'].'" สำเร็จ — ระบบพร้อมโพสต์จริงแล้ว');
        }

        // Several pages — park the list server-side (tokens stay in the session store,
        // never rendered) and let the admin pick which page the campaigns post to.
        $request->session()->put('fb_page_choices', $pages->all());

        return redirect()->route('admin.facebook.pick');
    }

    /** Page picker (only reached when the account admins several pages). */
    public function pick(Request $request)
    {
        $pages = collect($request->session()->get('fb_page_choices', []));
        if ($pages->isEmpty()) {
            return redirect()->route('admin.clip-campaigns.index')
                ->with('status', 'ไม่พบรายการเพจ — กดเชื่อมต่อใหม่อีกครั้ง');
        }

        return view('admin.clip-campaigns.facebook-pick', [
            'pages' => $pages->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])->values(),
        ]);
    }

    /** Store the chosen page's credentials. */
    public function select(Request $request): RedirectResponse
    {
        $request->validate(['page_id' => ['required', 'string', 'max:32']]);
        $chosen = collect($request->session()->get('fb_page_choices', []))
            ->first(fn ($p) => (string) $p['id'] === (string) $request->input('page_id'));

        if (! $chosen) {
            return redirect()->route('admin.clip-campaigns.index')
                ->with('status', 'ไม่พบเพจที่เลือก — กดเชื่อมต่อใหม่อีกครั้ง');
        }

        $request->session()->forget('fb_page_choices');
        $this->connect($chosen);

        return redirect()->route('admin.clip-campaigns.index')
            ->with('status', 'เชื่อมต่อเพจ "'.$chosen['name'].'" สำเร็จ — ระบบพร้อมโพสต์จริงแล้ว');
    }

    /** Disconnect — wipe the stored page credentials (campaigns fall back to dry-run). */
    public function disconnect(): RedirectResponse
    {
        foreach (['fb_page_id', 'fb_page_token', 'fb_page_name', 'fb_connected_at'] as $key) {
            Setting::write($key, null);
        }

        return back()->with('status', 'ตัดการเชื่อมต่อ Facebook แล้ว — แคมเปญจะกลับเป็นโหมดทดสอบ (ไม่โพสต์จริง)');
    }

    // ---- internals ----------------------------------------------------------

    /** @param array{id: string, name: string, access_token: string} $page */
    private function connect(array $page): void
    {
        Setting::write('fb_page_id', (string) $page['id']);
        Setting::write('fb_page_token', (string) $page['access_token']);   // encrypted at rest
        Setting::write('fb_page_name', (string) $page['name']);
        Setting::write('fb_connected_at', now()->toIso8601String());
    }

    /**
     * GET a Graph endpoint, returning the decoded JSON. Throws with FB's own error message
     * so the admin sees WHY a connect failed instead of a generic error.
     *
     * @return array<string, mixed>
     */
    private function graph(string $path, array $params): array
    {
        $resp = Http::timeout(30)->get('https://graph.facebook.com/'.$this->version().$path, $params);
        $json = $resp->json();
        if (! $resp->successful() || isset($json['error'])) {
            throw new RuntimeException((string) ($json['error']['message'] ?? 'Graph API HTTP '.$resp->status()));
        }

        return is_array($json) ? $json : [];
    }

    /** App secret: pasted via the admin form (encrypted setting) wins; .env is the fallback. */
    private function secret(): string
    {
        return (string) (Setting::get('fb_app_secret') ?: config('services.facebook.app_secret'));
    }

    private function callbackUrl(): string
    {
        return route('admin.facebook.callback');
    }

    private function version(): string
    {
        return (string) config('services.facebook.api_version', 'v21.0');
    }
}
