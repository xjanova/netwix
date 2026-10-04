<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EpisodeController extends Controller
{
    /**
     * A hand-entered link is handed to the player as-is ([EpisodeSourceController::resolve] returns
     * video_url before any source), so it must be something a browser on https can load: https only,
     * which also rules out javascript:/data: and mixed-content http.
     */
    private const LINK_RULES = ['required', 'string', 'max:2048', 'url:https'];

    private const LINK_MESSAGES = [
        'required' => 'กรุณาใส่ลิงก์วิดีโอ',
        'max' => 'ลิงก์ยาวเกิน 2048 ตัวอักษร',
        'url' => 'ลิงก์ต้องเป็น URL ที่ขึ้นต้นด้วย https:// (เช่น https://cdn.example.com/ep1.m3u8)',
    ];

    /** Cap the bulk box so one paste can't tie a worker up — a 999-episode title still fits easily. */
    private const BULK_MAX_CHARS = 300_000;

    public function store(Request $request, Content $content): RedirectResponse
    {
        $data = $request->validate([
            'season_number' => ['nullable', 'integer', 'between:1,50'],
            'number' => ['required', 'integer', 'between:1,999'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'between:0,600'],
            'video_url' => ['nullable', 'string', 'max:2048'],
            'thumbnail_path' => ['nullable', 'string', 'max:2048'],
        ]);

        $seasonId = null;
        if ($content->type === 'series') {
            $season = $content->seasons()->firstOrCreate(
                ['number' => $data['season_number'] ?? 1],
                ['title' => 'ซีซั่น '.($data['season_number'] ?? 1)],
            );
            $seasonId = $season->id;
        }

        $content->episodes()->create([
            'season_id' => $seasonId,
            'number' => $data['number'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'manual_link_at' => ($data['video_url'] ?? null) ? now() : null,
            'thumbnail_path' => $data['thumbnail_path'] ?? null,
            'sort' => $data['number'],
        ]);

        return back()->with('status', 'เพิ่มตอนเรียบร้อยแล้ว');
    }

    public function destroy(Content $content, Episode $episode): RedirectResponse
    {
        abort_unless($episode->content_id === $content->id, 404);
        $episode->delete();

        return back()->with('status', 'ลบตอนแล้ว');
    }

    /** Per-episode playback markers (blank = inherit the content-level default). */
    public function setMarkers(Request $request, Content $content, Episode $episode): RedirectResponse
    {
        abort_unless($episode->content_id === $content->id, 404);

        $data = $request->validate([
            'intro_end_seconds' => ['nullable', 'integer', 'between:0,36000'],
            'outro_seconds' => ['nullable', 'integer', 'between:0,36000'],
        ]);

        $episode->update([
            'intro_end_seconds' => $data['intro_end_seconds'] ?? null,
            'outro_seconds' => $data['outro_seconds'] ?? null,
        ]);

        return back()->with('status', "บันทึกมาร์คเวลาตอนที่ {$episode->number} แล้ว");
    }

    /** Admin types in the video link for one episode. It plays ahead of the episode's source. */
    public function setLink(Request $request, Content $content, Episode $episode): RedirectResponse
    {
        abort_unless($episode->content_id === $content->id, 404);

        $data = $request->validate(['video_url' => self::LINK_RULES], self::LINK_MESSAGES);

        if ($episode->is_mirrored) {
            return back()->withErrors(['video_url' => "ตอนที่ {$episode->number} มีไฟล์เก็บไว้ในเซิร์ฟเวอร์อยู่ — กด \"ลบไฟล์\" ก่อน แล้วค่อยใส่ลิงก์"]);
        }

        $this->applyLink($episode, $data['video_url']);

        return back()->with('status', "บันทึกลิงก์ตอนที่ {$episode->number} แล้ว");
    }

    /** Remove a hand-entered link: the episode goes back to its source (or to "no video" if it has none). */
    public function clearLink(Content $content, Episode $episode): RedirectResponse
    {
        abort_unless($episode->content_id === $content->id, 404);

        // Only ever clears what an admin typed in. A mirrored file or an ep1 preview also lives in
        // video_url, and those have their own delete flows that remove the stored file with it.
        if (! $episode->is_manual_link) {
            return back()->withErrors(['video_url' => "ตอนที่ {$episode->number} ไม่มีลิงก์ที่แอดมินใส่ไว้"]);
        }

        $episode->update(['video_url' => null, 'manual_link_at' => null]);

        return back()->with('status', "ลบลิงก์ตอนที่ {$episode->number} แล้ว".($episode->source ? ' — กลับไปใช้แหล่งเดิม' : ''));
    }

    /**
     * Paste links for many episodes of one title at once. One line per episode, either
     *   "12 https://…"   (episode number, then the link — also "EP12 …", "ตอนที่ 12 …", "12|…")
     * or bare links, which fill the episodes in list order; "-" skips an episode in that mode.
     * All-or-nothing: one bad line and nothing is saved, so a half-applied paste never has to be untangled.
     */
    public function bulkLinks(Request $request, Content $content): RedirectResponse
    {
        $data = $request->validate([
            'links' => ['required', 'string', 'max:'.self::BULK_MAX_CHARS],
            'overwrite' => ['nullable', 'boolean'],
        ], [
            'links.required' => 'กรุณาวางลิงก์อย่างน้อย 1 บรรทัด',
            'links.max' => 'ข้อความยาวเกินไป — แบ่งวางทีละส่วน',
        ]);
        $overwrite = (bool) ($data['overwrite'] ?? false);

        $episodes = $content->episodes()->get()->values();
        if ($episodes->isEmpty()) {
            return back()->withInput()->withErrors(['links' => 'เรื่องนี้ยังไม่มีตอน — เพิ่มตอนก่อน แล้วค่อยวางลิงก์']);
        }

        [$pairs, $errors] = $this->parseBulk((string) $data['links'], $episodes);
        if ($errors) {
            return back()->withInput()->withErrors(['links' => $errors]);
        }

        $set = [];
        $keptExisting = [];
        $keptMirrored = [];
        DB::transaction(function () use ($pairs, $overwrite, &$set, &$keptExisting, &$keptMirrored) {
            foreach ($pairs as [$episode, $url]) {
                if ($episode->is_mirrored) {
                    $keptMirrored[] = $episode->number;
                } elseif ($episode->video_url && $episode->video_url !== $url && ! $overwrite) {
                    $keptExisting[] = $episode->number;
                } else {
                    $this->applyLink($episode, $url);
                    $set[] = $episode->number;
                }
            }
        });

        $msg = 'ใส่ลิงก์แล้ว '.count($set).' ตอน';
        if ($keptExisting) {
            $msg .= ' · ข้าม '.count($keptExisting).' ตอนที่มีลิงก์อยู่แล้ว (EP '.implode(', ', $keptExisting).') — ติ๊ก "แทนที่ลิงก์เดิม" ถ้าต้องการเปลี่ยน';
        }
        if ($keptMirrored) {
            $msg .= ' · ข้าม '.count($keptMirrored).' ตอนที่มีไฟล์เก็บในเซิร์ฟเวอร์ (EP '.implode(', ', $keptMirrored).')';
        }

        return back()->with('status', $msg);
    }

    private function applyLink(Episode $episode, string $url): void
    {
        $episode->update([
            'video_url' => $url,
            'manual_link_at' => now(),
            // A pending "please mirror this" from a viewer is moot now, and failed mirror attempts must
            // not keep flagging the episode is_unavailable to the app while a working link is in place.
            'mirror_requested_at' => null,
            'mirror_attempts' => 0,
            'mirror_failed_at' => null,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Episode>  $episodes  in list order
     * @return array{0: list<array{0: Episode, 1: string}>, 1: list<string>}
     */
    private function parseBulk(string $text, $episodes): array
    {
        $numbered = '/^(?:ep\.?|ตอนที่|ตอน)?\s*(\d{1,4})\s*[|,:\s]\s*(\S+)$/iu';
        $lines = preg_split('/\R/u', $text) ?: [];

        $mode = null;   // 'numbered' | 'ordered', decided by the first line that carries a link
        $cursor = 0;
        $pairs = [];
        $errors = [];
        $seen = [];

        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            $at = 'บรรทัด '.($i + 1);
            if ($line === '') {
                continue;
            }

            $isSkip = $line === '-';
            $lineMode = $isSkip ? 'ordered' : (preg_match($numbered, $line) ? 'numbered' : 'ordered');
            $mode ??= $lineMode;
            if ($lineMode !== $mode) {
                $errors[] = "{$at}: ใช้รูปแบบปนกัน — ให้ทุกบรรทัดมีเลขตอนนำหน้า หรือไม่มีเลยทั้งหมด";

                continue;
            }

            if ($mode === 'numbered') {
                preg_match($numbered, $line, $m);
                $number = (int) $m[1];
                $url = $m[2];
                $matches = $episodes->where('number', $number);
                if ($matches->count() > 1) {
                    $errors[] = "{$at}: เรื่องนี้มีตอนที่ {$number} มากกว่า 1 ตอน (หลายซีซั่น) — ใช้แบบวางลิงก์เรียงตามลำดับแทน";

                    continue;
                }
                $episode = $matches->first();
                if (! $episode) {
                    $errors[] = "{$at}: ไม่มีตอนที่ {$number} ในเรื่องนี้";

                    continue;
                }
            } else {
                $episode = $episodes->get($cursor++);
                if (! $episode) {
                    $errors[] = "{$at}: ลิงก์เกินจำนวนตอน (เรื่องนี้มี {$episodes->count()} ตอน)";

                    continue;
                }
                if ($isSkip) {
                    continue;
                }
                $url = $line;
            }

            if (isset($seen[$episode->id])) {
                $errors[] = "{$at}: ตอนที่ {$episode->number} ถูกใส่ซ้ำ (บรรทัด {$seen[$episode->id]})";

                continue;
            }
            $seen[$episode->id] = $i + 1;

            $v = Validator::make(['video_url' => $url], ['video_url' => self::LINK_RULES], self::LINK_MESSAGES);
            if ($v->fails()) {
                $errors[] = "{$at}: ".$v->errors()->first('video_url');

                continue;
            }

            $pairs[] = [$episode, $url];
        }

        if (! $errors && ! $pairs) {
            $errors[] = 'ไม่พบลิงก์ในข้อความที่วาง';
        }

        return [$pairs, array_slice($errors, 0, 20)];
    }
}
