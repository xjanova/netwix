<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Only the genre taxonomy is seeded.
     *
     * The old demo catalog (Big Buck Bunny placeholder titles) and the demo
     * viewer account were removed on 2026-07-02. The real catalog now comes
     * from the import system (rongyok / wowdrama) and the primary admin is
     * created once via the first-run /setup page. Do NOT re-add demo content
     * or demo users here — running db:seed must never repopulate placeholders.
     */
    public function run(): void
    {
        $this->seedGenres();
    }

    private function seedGenres(): void
    {
        $defs = ['ดราม่า', 'แอ็กชัน', 'แฟนตาซี & ไซไฟ', 'โรแมนติก', 'สยองขวัญ', 'ตลก', 'อาชญากรรม', 'ผจญภัย', 'อนิเมะ', 'การ์ตูน'];
        foreach ($defs as $i => $name) {
            Genre::updateOrCreate(
                ['slug' => Str::slug($name) ?: 'genre-'.$i],
                ['name' => $name, 'sort' => $i],
            );
        }

        // Vertical short-drama genres (added 2026-07-04) — explicit slugs so a fresh seed matches
        // the live catalogue that VerticalGenre guesses into. See App\Support\VerticalGenre.
        foreach (['ย้อนยุค' => 'period', 'เกิดใหม่ / ทะลุมิติ' => 'rebirth'] as $name => $slug) {
            Genre::updateOrCreate(['slug' => $slug], ['name' => $name, 'sort' => 50]);
        }
    }
}
