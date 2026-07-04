<?php

namespace App\Support;

use App\Models\Genre;

/**
 * Best-effort genre guesser for vertical (rongyok) short-dramas from their Thai title.
 * rongyok exposes NO genre metadata anywhere (catalogue, category filter, and the watch page all
 * lack it — verified 2026-07-04), and its titles are extremely genre-loaded, so a priority-ordered
 * keyword match is the sustainable way to tag imports. First rule to match wins; ดราม่า is the
 * catch-all. Keep this in sync with the one-off backfill that seeded the existing catalogue.
 */
class VerticalGenre
{
    /** @var array<int, array{0:string,1:string}> [genre name, keyword alternation] — order = priority. */
    private const RULES = [
        ['สยองขวัญ', 'ผี|วิญญาณ|หลอน|สยอง|คำสาป|ซอมบี้|อาถรรพ์|นรก'],
        ['เกิดใหม่ / ทะลุมิติ', 'ทะลุมิติ|ข้ามภพ|ข้ามเวลา|ย้อนเวลา|ย้อนอดีต|เกิดใหม่|จุติ|ฟื้นคืน|ชาติที่แล้ว|ชาติก่อน|ระบบ|สลับร่าง|กลับไปตอน|กลับชาติ|เกมออนไลน์'],
        ['ย้อนยุค', 'ฮ่องเต้|องค์หญิง|องค์ชาย|ท่านอ๋อง|อ๋อง|ราชวงศ|ราชสำนัก|วังหลวง|ขันที|แม่ทัพ|จอมยุทธ|ยุทธภพ|กำลังภายใน|เซียน|จักรพรรดิ|ราชินี|ท่านหญิง|บัลลังก|ขุนนาง|สำนัก|ศิษย|จอมนาง|เมืองหลวง|โบราณ|ว่านฉาย|ราชัน|นางสนม'],
        ['อาชญากรรม', 'มาเฟีย|นักฆ่า|แก๊ง|ตำรวจ|สายลับ|เจ้าพ่อ|ล้างบาง|คดี|ฆาตกร|โจร|ปล้น|พนัน|คาสิโน|สืบสวน|อันธพาล|มือปืน'],
        ['แฟนตาซี & ไซไฟ', 'เทพ|มังกร|เวทมนตร|จอมเวท|พลังวิเศษ|พลังพิเศษ|อมตะ|เอเลี่ยน|หุ่นยนต|ต่างดาว|ซูเปอร|อวกาศ|แวมไพร|มนตร|ปีศาจ|อสูร'],
        ['แอ็กชัน', 'นักสู้|ต่อสู้|สังเวียน|ทหาร|สงคราม|นักรบ|วีรบุรุษ|มวย|สนามรบ|ปล่อยพลัง|คมดาบ|ยอดฝีมือ|จอมพลัง|นักมวย'],
        ['ตลก', 'ฮา|ป่วน|เฮฮา|ขำ|กวนๆ|ตลก|วุ่นวาย'],
        ['โรแมนติก', 'รัก|หลง|หัวใจ|สามี|ภรรยา|เมีย|แต่งงาน|วิวาห์|ประธาน|เจ้าสัว|มหาเศรษฐี|ซีอีโอ|CEO|ไฮโซ|คุณชาย|แฟน|จีบ|เสน่หา|ใคร่|แซ่บ|สวีท|คุณหนู|เจ้าสาว|เขย|หวาน|ชู้|เลิฟ|นายท่าน|บอส'],
    ];

    private const FALLBACK = 'ดราม่า';

    /** The best-guess genre NAME for a title (always returns something). */
    public static function guess(?string $title): string
    {
        $t = trim((string) $title);
        if ($t !== '') {
            foreach (self::RULES as [$name, $re]) {
                if (preg_match('/'.$re.'/u', $t)) {
                    return $name;
                }
            }
        }

        return self::FALLBACK;
    }

    /** The genre id for the guessed name (all guessable genres are seeded); null only if none exist. */
    public static function guessId(?string $title): ?int
    {
        return Genre::where('name', self::guess($title))->value('id')
            ?? Genre::where('name', self::FALLBACK)->value('id');
    }
}
