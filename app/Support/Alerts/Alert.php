<?php

namespace App\Support\Alerts;

/**
 * One operational alert, described once and rendered per channel: plain text for LINE, a drawn card
 * plus an HTML caption for Telegram, a row in the history table for the admin page.
 *
 * It is structured rather than a pre-formatted string because a card needs to know which part is
 * the headline, which numbers deserve a tile, and what to chart — and a string that already has
 * all of that flattened into lines cannot be un-flattened reliably.
 */
final class Alert
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    /** Worth a record, not a buzz — Telegram delivers it silently. */
    public const INFO = 'info';

    public const OK = 'ok';

    public const LEVELS = [self::CRITICAL, self::WARNING, self::INFO, self::OK];

    /**
     * @param  string  $key  throttle identity ("source-down:24hdx") — the same key is silent for a while
     * @param  array<string,string|int>  $facts  label => value, drawn as tiles (first 4)
     * @param  array<string,int>  $bars  label => count, drawn as a bar chart (first 6)
     * @param  array<string,bool>  $chips  label => healthy?, drawn as status pills
     * @param  string  $category  one of AdminAlerts::CATEGORIES — decides which channels it goes to
     * @param  string  $barsLabel  heading over the bar chart
     */
    public function __construct(
        public readonly string $key,
        public readonly string $level,
        public readonly string $title,
        public readonly string $body = '',
        public readonly array $facts = [],
        public readonly array $bars = [],
        public readonly array $chips = [],
        public readonly ?string $url = null,
        public readonly string $urlLabel = 'เปิดดูในหน้าแอดมิน',
        public readonly string $category = 'system',
        public readonly string $barsLabel = 'แยกตามแหล่ง',
    ) {}

    public function emoji(): string
    {
        return match ($this->level) {
            self::CRITICAL => '🚨',
            self::WARNING => '⚠️',
            self::INFO => '🛡️',
            default => '✅',
        };
    }

    /** Short Thai word for the level — the badge on the card. */
    public function levelLabel(): string
    {
        return match ($this->level) {
            self::CRITICAL => 'ด่วน',
            self::WARNING => 'ควรตรวจสอบ',
            self::INFO => 'แจ้งให้ทราบ',
            default => 'เรียบร้อย',
        };
    }

    /** "label: value" lines for the facts — what a text-only channel shows instead of tiles. */
    public function factLines(): array
    {
        $lines = [];
        foreach ($this->facts as $label => $value) {
            $lines[] = $label.': '.$value;
        }

        return $lines;
    }

    /** Plain text — the whole LINE message, and what the history table keeps. */
    public function toText(): string
    {
        $parts = [$this->emoji().' '.$this->title];
        if ($this->facts !== []) {
            $parts[] = implode("\n", $this->factLines());
        }
        if (trim($this->body) !== '') {
            $parts[] = trim($this->body);
        }
        if ($this->url) {
            $parts[] = $this->url;
        }

        return implode("\n\n", $parts);
    }
}
