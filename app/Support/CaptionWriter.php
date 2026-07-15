<?php

namespace App\Support;

use App\Models\Content;
use App\Models\MarketingClip;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Writes a Thai Facebook caption for a marketing clip.
 *
 * Split of responsibility, on purpose:
 *   - the LLM (Groq / any OpenAI-compatible chat API) only writes the CREATIVE hook —
 *     2-4 punchy lines that make someone want to watch. No links, no hashtags there.
 *   - this class then ALWAYS appends the CTA line + app download link + hashtags in code,
 *     so the marketing payload (the whole point) can never be hallucinated wrong or dropped.
 *
 * With no API key configured it falls back to a rotating template seeded by the clip id,
 * so captions work today and simply get better once a key is added.
 */
class CaptionWriter
{
    /** Build + return a ready-to-post caption. Never throws — worst case, the template. */
    public function for(MarketingClip $clip): string
    {
        $content = Content::withoutGlobalScopes()->with('genres:id,name')->find($clip->content_id);
        $title = $content?->title ?: 'หนังเรื่องนี้';
        $genres = $content ? $content->genres->pluck('name')->take(3)->implode(' · ') : '';
        $year = $content?->year;
        $synopsis = trim((string) ($content?->synopsis ?? ''));

        $hook = $this->hook($clip, $title, $genres, $year, $synopsis);

        return $this->assemble($hook, $title, $this->teaser($synopsis));
    }

    /**
     * A short teaser drawn from the synopsis (owner: "นำส่วนหนึ่งของสปอยมาร่วมด้วย"). Deliberately the
     * OPENING of the synopsis — the premise that hooks, not the ending — so it entices without
     * giving the whole plot away, then trails off with "…" to send them to watch the rest.
     * Toggle with services.caption.synopsis_teaser; capped by services.caption.teaser_chars.
     */
    private function teaser(string $synopsis): string
    {
        if ($synopsis === '' || ! config('services.caption.synopsis_teaser', true)) {
            return '';
        }

        // Clean: drop any HTML, a leading "เรื่องย่อ"/"เนื้อเรื่อง" label, and collapse whitespace.
        $s = trim(preg_replace('~\s+~u', ' ', strip_tags($synopsis)));
        $s = trim(preg_replace('~^(เรื่องย่อ|เนื้อเรื่อง|ย่อ)\s*[:：]?\s*~u', '', $s));
        if (mb_strlen($s, 'UTF-8') < 20) {
            return '';   // too short to be a real synopsis — skip rather than post a stub
        }

        $max = (int) config('services.caption.teaser_chars', 150);
        if (mb_strlen($s, 'UTF-8') > $max) {
            // Cut at the last space before the cap so we never split a word, then trail off.
            $cut = mb_substr($s, 0, $max, 'UTF-8');
            $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
            $s = rtrim($sp && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp, 'UTF-8') : $cut, " ,.·;:—-").'…';
        }

        return '📖 '.$s;
    }

    // ---- the creative hook --------------------------------------------------

    private function hook(MarketingClip $clip, string $title, string $genres, ?int $year, string $synopsis): string
    {
        $driver = (string) config('services.caption.driver', 'template');
        $key = (string) config('services.caption.api_key', '');

        if ($driver !== 'template' && $key !== '') {
            $ai = $this->llmHook($title, $genres, $year, $synopsis, $key);
            if ($ai !== null) {
                return $ai;
            }
        }

        return $this->templateHook($clip, $title, $genres, $year);
    }

    private function llmHook(string $title, string $genres, ?int $year, string $synopsis, string $key): ?string
    {
        $base = rtrim((string) config('services.caption.base_url', 'https://api.groq.com/openai/v1'), '/');
        $model = (string) config('services.caption.model', 'llama-3.3-70b-versatile');

        $sys = 'คุณเป็นนักเขียนแคปชันเฟซบุ๊กสายหนัง เขียนภาษาไทยเท่านั้น กระชับ สนุก ดึงดูด '
            .'ใช้อีโมจิพองาม ห้ามสปอยล์ตอนจบ ห้ามใส่แฮชแท็ก ห้ามใส่ลิงก์ ห้ามใส่เครื่องหมายคำพูดครอบทั้งข้อความ '
            .'ความยาว 2-4 บรรทัด ปิดท้ายด้วยประโยคชวนดูต่อ';
        $meta = trim("เรื่อง: {$title}".($year ? " ({$year})" : '').($genres ? " · แนว {$genres}" : ''));
        $user = $meta.($synopsis !== '' ? "\nเรื่องย่อ: ".mb_substr($synopsis, 0, 600) : '')
            ."\nเขียนแคปชันชวนดูคลิปนี้ แล้วอยากไปดูเต็มเรื่องต่อ";

        try {
            $resp = Http::timeout(30)->withToken($key)->acceptJson()
                ->post("{$base}/chat/completions", [
                    'model' => $model,
                    'temperature' => 0.9,
                    'max_tokens' => 300,
                    'messages' => [
                        ['role' => 'system', 'content' => $sys],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
            if (! $resp->successful()) {
                return null;
            }
            $text = trim((string) $resp->json('choices.0.message.content'));

            return $this->sanitize($text) ?: null;
        } catch (Throwable $e) {
            return null;   // any failure → caller uses the template
        }
    }

    /** Strip stray wrapping quotes / model-added hashtags / links so our footer is authoritative. */
    private function sanitize(string $text): string
    {
        $text = trim($text, " \t\n\r\0\x0B\"'“”");
        $text = preg_replace('~https?://\S+~i', '', $text);       // drop any link the model slipped in
        $text = preg_replace('~#[^\s#]+~u', '', $text);           // drop any hashtags
        $text = preg_replace('~\n{3,}~', "\n\n", (string) $text); // collapse blank runs

        return trim((string) $text);
    }

    private function templateHook(MarketingClip $clip, string $title, string $genres, ?int $year): string
    {
        // Seed the choice by clip id so a batch of clips for one title reads differently
        // but each clip is stable across re-generations.
        $hooks = [
            'เรื่องนี้ห้ามพลาดเด็ดขาด 🔥',
            'ดูแล้วหยุดไม่ได้จริงๆ 😱',
            'ใครยังไม่ได้ดู รีบเลย ⚡',
            'ฉากนี้คือของจริง 👀',
            'อินจนลืมเวลา 🍿',
            'สายหนังต้องจัด 🎬',
        ];
        $hook = $hooks[$clip->id % count($hooks)];
        $tail = trim($title.($year ? " ({$year})" : '').($genres ? " — {$genres}" : ''));

        return $hook."\n".$tail."\nดูตัวอย่างแล้วไปต่อเต็มเรื่องกันเลย ✨";
    }

    // ---- deterministic marketing footer -------------------------------------

    private function assemble(string $hook, string $title, string $teaser = ''): string
    {
        $parts = [$hook];

        if ($teaser !== '') {
            $parts[] = $teaser;
        }

        $lucky = trim((string) config('services.caption.lucky_line', ''));
        if ($lucky !== '') {
            $parts[] = $lucky;
        }

        $parts[] = '📲 โหลดแอป NetWix ดู "'.$title.'" เต็มเรื่องฟรี 👉 '.$this->appUrl();

        $tags = trim((string) config('services.caption.hashtags', ''));
        if ($tags !== '') {
            $parts[] = $tags;
        }

        return implode("\n\n", $parts);
    }

    private function appUrl(): string
    {
        $override = trim((string) config('services.caption.app_url', ''));
        if ($override !== '') {
            return $override;
        }
        try {
            return route('download');
        } catch (Throwable $e) {
            return rtrim((string) config('app.url', 'https://netwix.online'), '/').'/download';
        }
    }
}
