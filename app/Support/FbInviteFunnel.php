<?php

namespace App\Support;

use App\Models\Content;
use App\Models\FbEngagement;
use App\Models\MarketingClip;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * The comment→invite-DM funnel rules (admin-configurable, one JSON blob in Settings, same
 * pattern as [App\Services\Membership]). Decides whether to privately reply to a commenter and
 * builds the title-specific invite ("watch <title> on the web / grab the app").
 *
 * Anti-spam is deliberate: master kill-switch OFF by default, one DM per (user, title) inside a
 * cooldown window, and an optional per-user daily cap — a page that mass-DMs gets its Messenger
 * quality rating tanked, so restraint is a feature.
 */
class FbInviteFunnel
{
    public const DEFAULTS = [
        'enabled' => false,          // master kill-switch (off until the webhook + reply mode are set)
        // How we invite a commenter:
        //   'public' → reply UNDER their comment (needs only pages_manage_engagement — works TODAY).
        //   'dm'     → private message (needs pages_messaging → App Review; falls back to nothing until then).
        'reply_mode' => 'public',
        'cooldown_days' => 7,        // don't re-invite the same user for the same title within N days
        'daily_cap_per_user' => 3,   // max invites to one user per day across all titles (0 = ∞)
        'skip_reactions' => true,    // reactions can't be replied to individually; comments only for now
        // {title} = the title, {web} = its watch page, {app} = the app-download page.
        'messages' => [
            'ดู {title} เต็มเรื่องได้เลยที่ 👉 {web}\nหรือโหลดแอป NetWix ดูสะดวกกว่า ไม่มีโฆษณาคั่น 📱 {app}',
            'ชอบ {title} ใช่ไหมครับ ดูจบทุกตอนที่ {web} 🎬\nโหลดแอปดูบนมือถือได้ที่ {app}',
            'อยากดู {title} แบบเต็มๆ แวะที่เว็บเราได้เลย {web}\nหรือติดตั้งแอป NetWix 👉 {app}',
        ],
    ];

    private const KEY = 'fb_dm_config';

    public function config(): array
    {
        $raw = Setting::get(self::KEY);
        $override = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return array_replace_recursive(self::DEFAULTS, is_array($override) ? $override : []);
    }

    public function saveConfig(array $config): void
    {
        Setting::write(self::KEY, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function enabled(): bool
    {
        return (bool) ($this->config()['enabled'] ?? false);
    }

    /**
     * Which title a webhook post_id is about. Fast path = the story id we stored at post time;
     * fallback resolves the post's underlying video id via the Graph API and matches that. Null
     * when the post isn't one of ours (someone commented on a non-clip post).
     */
    public function contentForPost(string $postId, FacebookMessenger $fb): ?Content
    {
        $clip = MarketingClip::where('remote_story_id', $postId)->first();

        if (! $clip) {
            // Older post with no stored story id → ask Graph for its video id, match remote_post_id.
            $videoId = $fb->resolvePostVideoId($postId);
            if ($videoId) {
                $clip = MarketingClip::where('remote_post_id', $videoId)->first();
                $clip?->update(['remote_story_id' => $postId]);   // memoise for next time
            }
        }

        return $clip?->content;
    }

    /**
     * Should we DM this user for this title now? False when: the funnel is off, we already invited
     * them for this title inside the cooldown, or they've hit today's per-user cap.
     */
    public function shouldInvite(string $fbUserId, int $contentId): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $cfg = $this->config();

        $cooldownDays = max(0, (int) ($cfg['cooldown_days'] ?? 0));
        if ($cooldownDays > 0) {
            $already = FbEngagement::where('fb_user_id', $fbUserId)
                ->where('content_id', $contentId)
                ->where('dm_status', 'sent')
                ->where('created_at', '>=', now()->subDays($cooldownDays))
                ->exists();
            if ($already) {
                return false;
            }
        }

        $cap = max(0, (int) ($cfg['daily_cap_per_user'] ?? 0));
        if ($cap > 0) {
            $today = FbEngagement::where('fb_user_id', $fbUserId)
                ->where('dm_status', 'sent')
                ->whereDate('created_at', today())
                ->count();
            if ($today >= $cap) {
                return false;
            }
        }

        return true;
    }

    /** Build the title-specific invite from a rotated template (varies per user so it's not identical spam). */
    public function buildMessage(Content $content, string $seed = ''): string
    {
        $messages = array_values(array_filter((array) ($this->config()['messages'] ?? []), 'is_string'));
        if ($messages === []) {
            $messages = self::DEFAULTS['messages'];
        }
        // Deterministic rotation keyed by the commenter so the same person doesn't always get msg #1,
        // but we don't need randomness (which is unavailable in some runtimes anyway).
        $idx = $seed !== '' ? (hexdec(substr(md5($seed), 0, 6)) % count($messages)) : 0;
        $tpl = $messages[$idx];

        return strtr($tpl, [
            '{title}' => $content->title,
            '{web}' => $this->webUrl($content),
            '{app}' => $this->appUrl(),
        ]);
    }

    private function webUrl(Content $content): string
    {
        try {
            return route('title.show', $content);
        } catch (\Throwable) {
            return rtrim((string) config('app.url'), '/').'/title/'.$content->slug;
        }
    }

    private function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/download';
    }
}
