<?php

namespace Database\Seeders;

use App\Models\Content;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /** Open Creative-Commons demo media so the player actually works out of the box. */
    private const SAMPLE_MP4 = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4';
    private const SAMPLE_HLS = 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8';
    private const DEMO_TRAILER = 'aqz-KE-bpKQ'; // Big Buck Bunny (CC) — autoplays in hero

    private const EP_TITLES = ['จุดเริ่มต้น', 'เงาที่ซ่อนอยู่', 'รอยแตก', 'จุดพลิกผัน', 'ทางเลือก', 'บทสรุป'];
    private const EP_DESCS = [
        'จุดเริ่มต้นของทุกอย่างเผยตัวขึ้น',
        'ความลับที่ถูกซ่อนไว้เริ่มปรากฏ',
        'สถานการณ์พลิกผันเมื่อความจริงเผยออกมา',
        'แผนการที่วางไว้เริ่มสั่นคลอน',
        'จุดแตกหักที่ทุกคนต้องเลือกข้าง',
        'บทสรุปของฤดูกาลที่ไม่มีใครคาดคิด',
    ];

    public function run(): void
    {
        $this->seedUsers();
        $genres = $this->seedGenres();
        $this->seedCatalog($genres);
    }

    private function seedUsers(): void
    {
        $admin = User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@netwix.online')],
            [
                'name' => 'อดิศร ผู้ดูแล',
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'netwix-admin-2026')),
                'role' => 'admin',
                'plan' => 'premium',
            ],
        );
        $admin->profiles()->firstOrCreate(['name' => 'อดิศร'], ['avatar_color' => '#b026ff']);

        $demo = User::updateOrCreate(
            ['email' => 'demo@netwix.online'],
            [
                'name' => 'ผู้ชมทดลอง',
                'password' => Hash::make(env('SEED_DEMO_PASSWORD', 'netwix-demo-2026')),
                'role' => 'user',
                'plan' => 'premium',
            ],
        );
        foreach ([['เมย์', '#ff2d55', false], ['ต้นข้าว', '#00b8d4', false], ['น้องหนูดี', '#f5c518', true]] as [$name, $color, $kids]) {
            $demo->profiles()->firstOrCreate(['name' => $name], ['avatar_color' => $color, 'is_kids' => $kids]);
        }
    }

    /** @return array<string,Genre> */
    private function seedGenres(): array
    {
        $defs = ['ดราม่า', 'แอ็กชัน', 'แฟนตาซี & ไซไฟ', 'โรแมนติก', 'สยองขวัญ', 'ตลก', 'อาชญากรรม', 'ผจญภัย'];
        $genres = [];
        foreach ($defs as $i => $name) {
            $genres[$name] = Genre::updateOrCreate(
                ['slug' => Str::slug($name) ?: 'genre-'.$i],
                ['name' => $name, 'sort' => $i],
            );
        }

        return $genres;
    }

    /** @param array<string,Genre> $genres */
    private function seedCatalog(array $genres): void
    {
        // [title, type, [genres...], maturity, year, original, featured, rating, match]
        $catalog = [
            ['เงาสีเลือด', 'series', ['อาชญากรรม', 'ดราม่า'], '18+', 2025, true, true, 9.1, 98],
            ['ล่าพลิกเมือง', 'series', ['แอ็กชัน', 'อาชญากรรม'], '16+', 2024, true, false, 8.7, 96],
            ['ห้วงเวลาที่หายไป', 'series', ['แฟนตาซี & ไซไฟ', 'ดราม่า'], '13+', 2025, true, false, 8.9, 95],
            ['รักโรแมนติก ให้หัวใจละลาย', 'series', ['โรแมนติก', 'ดราม่า'], '13+', 2024, false, false, 8.4, 92],
            ['บ้านผีสิงหลังสุดท้าย', 'series', ['สยองขวัญ', 'ผจญภัย'], '18+', 2023, false, false, 8.0, 90],
            ['เจ้าสาวข้ามภพ', 'series', ['โรแมนติก', 'แฟนตาซี & ไซไฟ'], '13+', 2025, true, false, 8.8, 97],
            ['ปฏิบัติการเงียบ', 'series', ['แอ็กชัน', 'ผจญภัย'], '16+', 2024, false, false, 8.3, 93],
            ['เมื่อดาวร่วงหล่น', 'series', ['ดราม่า', 'โรแมนติก'], '13+', 2025, true, false, 9.0, 96],
            ['คำสาปแห่งห้วงลึก', 'movie', ['สยองขวัญ', 'แฟนตาซี & ไซไฟ'], '18+', 2024, false, true, 8.2, 91],
            ['วันสิ้นโลกที่รัก', 'movie', ['แฟนตาซี & ไซไฟ', 'แอ็กชัน'], '16+', 2025, true, false, 8.6, 95],
            ['เส้นทางนักสู้', 'movie', ['แอ็กชัน', 'ดราม่า'], '16+', 2023, false, false, 8.1, 89],
            ['หัวใจไม่มีปลอม', 'movie', ['โรแมนติก', 'ตลก'], '13+', 2024, false, false, 7.9, 88],
            ['ไขคดีเมืองมืด', 'movie', ['อาชญากรรม', 'ผจญภัย'], '16+', 2025, true, false, 8.7, 94],
            ['ผจญภัยสุดขอบฟ้า', 'movie', ['ผจญภัย', 'แฟนตาซี & ไซไฟ'], '7+', 2024, false, false, 8.0, 90],
        ];

        foreach ($catalog as $i => [$title, $type, $gnames, $maturity, $year, $original, $featured, $rating, $match]) {
            $content = Content::updateOrCreate(
                ['slug' => Str::slug($title) ?: 'title-'.$i],
                [
                    'title' => $title,
                    'type' => $type,
                    'synopsis' => 'เรื่องราว'.$title.' ที่จะพาคุณดำดิ่งสู่โลกที่คุณไม่อยากละสายตา เต็มไปด้วยปมปริศนาและการหักมุมที่คาดไม่ถึง',
                    'year' => $year,
                    'maturity' => $maturity,
                    'match_score' => $match,
                    'rating' => $rating,
                    'is_original' => $original,
                    'is_featured' => $featured,
                    'is_published' => true,
                    'trailer_youtube_id' => self::DEMO_TRAILER,
                    'video_url' => $type === 'movie' ? self::SAMPLE_MP4 : null,
                    'duration_minutes' => $type === 'movie' ? random_int(96, 148) : null,
                    'views' => random_int(120_000, 4_800_000),
                    'sort' => $i,
                ],
            );

            $this->attachGenres($content, $genres, $gnames);

            if ($type === 'series') {
                $this->seedSeries($content);
            }
        }

        // Vertical short-drama shows (ซีรีส์แนวตั้ง)
        $verticals = [
            ['สามีเผด็จการที่รัก', ['โรแมนติก', 'ดราม่า'], 16],
            ['แค้นนี้ต้องชำระ', ['ดราม่า', 'อาชญากรรม'], 14],
            ['ซีอีโอที่รักลับๆ', ['โรแมนติก'], 18],
            ['เกิดใหม่เป็นคุณหนู', ['แฟนตาซี & ไซไฟ', 'โรแมนติก'], 12],
            ['สัญญารักเหนือกาลเวลา', ['โรแมนติก', 'แฟนตาซี & ไซไฟ'], 15],
            ['ล้างแค้นด้วยหัวใจ', ['ดราม่า'], 13],
        ];
        foreach ($verticals as $i => [$title, $gnames, $epCount]) {
            $content = Content::updateOrCreate(
                ['slug' => Str::slug($title) ?: 'vertical-'.$i],
                [
                    'title' => $title,
                    'type' => 'vertical',
                    'synopsis' => 'ซีรีส์สั้นแนวตั้ง ดูจบไวในไม่กี่นาที กับเรื่อง'.$title,
                    'year' => 2025,
                    'maturity' => '15+',
                    'match_score' => random_int(90, 99),
                    'rating' => round(random_int(78, 96) / 10, 1),
                    'is_original' => $i % 2 === 0,
                    'is_published' => true,
                    'video_url' => self::SAMPLE_MP4,
                    'views' => random_int(500_000, 9_000_000),
                    'sort' => 100 + $i,
                ],
            );
            $this->attachGenres($content, $genres, $gnames);
            $this->seedVerticalEpisodes($content, $epCount);
        }
    }

    /** @param array<string,Genre> $genres */
    private function attachGenres(Content $content, array $genres, array $names): void
    {
        $sync = [];
        foreach ($names as $idx => $name) {
            if (isset($genres[$name])) {
                $sync[$genres[$name]->id] = ['is_primary' => $idx === 0];
            }
        }
        $content->genres()->sync($sync);
    }

    private function seedSeries(Content $content): void
    {
        foreach ([1, 2] as $seasonNumber) {
            $season = $content->seasons()->updateOrCreate(
                ['number' => $seasonNumber],
                ['title' => 'ซีซั่น '.$seasonNumber],
            );
            foreach (self::EP_TITLES as $n => $epTitle) {
                $content->episodes()->updateOrCreate(
                    ['season_id' => $season->id, 'number' => $n + 1],
                    [
                        'title' => 'ตอนที่ '.($n + 1).': '.$epTitle,
                        'description' => self::EP_DESCS[$n],
                        'duration_minutes' => [42, 45, 39, 51, 44, 48][$n],
                        'video_url' => $n % 2 === 0 ? self::SAMPLE_MP4 : self::SAMPLE_HLS,
                        'sort' => $n + 1,
                    ],
                );
            }
        }
    }

    private function seedVerticalEpisodes(Content $content, int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $content->episodes()->updateOrCreate(
                ['season_id' => null, 'number' => $n],
                [
                    'title' => 'ตอนที่ '.$n,
                    'duration_minutes' => 2,
                    'video_url' => self::SAMPLE_MP4,
                    'sort' => $n,
                ],
            );
        }
    }
}
