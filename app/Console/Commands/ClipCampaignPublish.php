<?php

namespace App\Console\Commands;

use App\Models\ClipCampaign;
use App\Models\Setting;
use App\Support\ClipCampaignRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Clip campaign publisher — the every-5-minute heartbeat of the auto-post pipeline.
 *
 * For each enabled campaign it asks {@see ClipCampaign::dueSlot()} whether a slot falls in
 * the current window and, if so, hands off to {@see ClipCampaignRunner} (pick title → row →
 * enqueue cut). The DB unique key on clip_campaign_posts makes a double-fire a no-op, so this
 * is safe to schedule aggressively. Cheap by design: it never touches ffmpeg.
 *
 * Self-gates on the `clip_campaigns_enabled` admin kill-switch, so the scheduler can always
 * call it. Manual forms for testing one campaign:
 *   php artisan netwix:clips:publish --campaign=<slug> --now   (fire immediately, any slot)
 */
class ClipCampaignPublish extends Command
{
    protected $signature = 'netwix:clips:publish
        {--campaign= : run only this campaign (slug or id); bypasses the enabled + day filters}
        {--slot= : force this slot "HH:MM" (implies a manual run for --campaign)}
        {--now : fire the campaign right now regardless of its schedule (manual test)}';

    protected $description = 'Fire due clip marketing campaigns: pick a title, cut a clip, auto-post to Facebook.';

    public function handle(ClipCampaignRunner $runner): int
    {
        // Thai wall-clock time: slots are Asia/Bangkok, and post_date must be the Thai date
        // (a 01:00 slot belongs to that Thai day, not the previous UTC day). See ClipCampaign::TZ.
        $now = Carbon::now(ClipCampaign::TZ);

        // ── Manual single-campaign run (admin "โพสต์ทันที" / CLI testing) ──────────────
        if ($only = $this->option('campaign')) {
            $campaign = ClipCampaign::where('slug', $only)->orWhere('id', (int) $only)->first();
            if (! $campaign) {
                $this->error("campaign '{$only}' not found");

                return self::FAILURE;
            }
            $slot = (string) ($this->option('slot') ?: $now->format('H:i'));
            $normalized = ClipCampaign::normalizeSlot($slot) ?? $now->format('H:i');
            $post = $runner->fire($campaign, $normalized, $now->toDateString(), manual: true);
            $this->info("campaign '{$campaign->slug}' slot {$normalized} → ".($post?->status ?? 'no-op'));

            return self::SUCCESS;
        }

        // ── Scheduled run — the kill-switch only guards the automatic path ─────────────
        if (! Setting::flag('clip_campaigns_enabled', false)) {
            $this->info('clip campaigns are OFF (admin kill-switch) — skipping.');

            return self::SUCCESS;
        }

        try {
            $fired = $runner->runDue($now);
        } catch (Throwable $e) {
            report($e);
            $this->error('runDue failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($fired
            ? 'fired '.count($fired).' campaign(s): '.implode(', ', array_map(
                fn ($id, $slot) => "#{$id}@{$slot}", array_keys($fired), $fired))
            : 'no campaigns due this window.');

        return self::SUCCESS;
    }
}
