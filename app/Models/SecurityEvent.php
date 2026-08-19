<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One observation of scraping-shaped behaviour. Insert-only, so there is no updated_at.
 *
 * @see \App\Support\ScrapeGuard for what writes these and why.
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ip', 'reason', 'score', 'method', 'path', 'user_agent', 'meta', 'created_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    /** Thai label for the admin table — the reason codes are written for machines, not people. */
    public function getReasonLabelAttribute(): string
    {
        return [
            'rate' => 'ยิงถี่ผิดปกติ',
            'sequential' => 'ไล่ไอดีเรียงลำดับ',
            'no_referer' => 'ขอข้อมูลโดยไม่ผ่านหน้าเว็บ',
            'token_abuse' => 'ขอลิงก์ดูหนังรัว',
            'bot_ua' => 'บอทที่ประกาศตัว',
            'probe' => 'สุ่มยิงหา endpoint',
        ][$this->reason] ?? $this->reason;
    }
}
