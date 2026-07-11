<?php

namespace Database\Seeders;

use App\Models\ClipCampaign;
use App\Models\Genre;
use Illuminate\Database\Seeder;

/**
 * Three ready-made example clip campaigns so the admin has concrete templates to tweak
 * instead of a blank page. Every one ships DISABLED — the owner reviews, adjusts, then flips
 * the switch. First-run only (skips entirely if any campaign already exists), so re-running
 * db:seed never resurrects or duplicates them. NOT wired into DatabaseSeeder on purpose —
 * run it explicitly: `php artisan db:seed --class=Database\\Seeders\\ClipCampaignSeeder`.
 */
class ClipCampaignSeeder extends Seeder
{
    public function run(): void
    {
        if (ClipCampaign::exists()) {
            return;   // never touch a catalogue the admin has started curating
        }

        $anime = Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->value('id');

        $examples = [
            [
                'name' => 'หนังมาแรง โพสต์เย็นทุกวัน',
                'slug' => 'trending-movies-evening',
                'content_type' => 'movie', 'pick' => 'trending',
                'duration' => 45, 'aspect' => '9:16', 'targets' => 'reels,feed',
                'days' => '', 'slots' => ['18:00'], 'avoid_recent_days' => 14,
            ],
            [
                'name' => 'ซีรีส์ใหม่ เที่ยง + ค่ำ',
                'slug' => 'new-series-noon-night',
                'content_type' => 'series', 'pick' => 'newest',
                'duration' => 50, 'aspect' => '9:16', 'targets' => 'reels,feed',
                'days' => '', 'slots' => ['12:00', '20:00'], 'avoid_recent_days' => 21,
            ],
            [
                'name' => 'อนิเมะ สุ่ม (เสาร์–อาทิตย์)',
                'slug' => 'anime-random-weekend',
                'content_type' => 'anime', 'genre_id' => $anime, 'pick' => 'random',
                'duration' => 40, 'aspect' => '9:16', 'targets' => 'reels,feed',
                'days' => '0,6', 'slots' => ['19:00'], 'avoid_recent_days' => 30,
            ],
        ];

        foreach ($examples as $data) {
            ClipCampaign::create($data + ['is_enabled' => false, 'include_adult' => false]);
        }
    }
}
