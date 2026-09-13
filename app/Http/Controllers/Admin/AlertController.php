<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\AlertCard;
use App\Support\LineNotifier;
use App\Support\SourceHealth;
use App\Support\TelegramNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "แจ้งเตือนปัญหา" — where the owner points problem alerts at Telegram and/or LINE, and proves each
 * one works before an outage has to.
 *
 * Both channel tokens are SECRET settings (encrypted at rest) and therefore WRITE-ONLY here: the form
 * shows whether one is set, never the value. A token that can be read back off an admin page is a
 * token that leaks through a screenshot, a shoulder, or a stale browser cache. (They are also kept
 * out of the session's flashed old-input — see dontFlash in bootstrap/app.php.)
 */
class AlertController extends Controller
{
    private const CHAT_ID_RULE = 'regex:/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{3,31})$/';

    public function index(): View
    {
        return view('admin.alerts.index', [
            'tg' => [
                'enabled' => Setting::flag('telegram_alerts_enabled', false),
                'hasToken' => trim((string) Setting::get('telegram_bot_token', '')) !== '',
                'chat' => (string) Setting::get('telegram_chat_id', ''),
                'bot' => (string) Setting::get('telegram_bot_username', ''),
                'ready' => TelegramNotifier::enabled(),
            ],
            'line' => [
                'enabled' => Setting::flag('line_alerts_enabled', false),
                'hasToken' => trim((string) Setting::get('line_oa_token', '')) !== '',
                'to' => (string) Setting::get('line_oa_to', ''),
                'ready' => LineNotifier::enabled(),
            ],
            'categories' => AdminAlerts::CATEGORIES,
            'routes' => AdminAlerts::routes(),
            'canDraw' => AlertCard::available(),
            'sourcesDown' => array_keys(SourceHealth::down()),
            // What we have actually sent. The owner asked "an alert came through — what was it?" and
            // nothing on the server could answer: the throttle key is hashed in the cache and a
            // successful push logged nothing at all.
            'recent' => AdminAlerts::recent(30),
            'chats' => (array) session('tg_chats', []),
        ]);
    }

    // ------------------------------------------------------------------------------ Telegram

    public function updateTelegram(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'telegram_alerts_enabled' => ['sometimes', 'boolean'],
            // Blank = keep the stored token, so the chat can be changed without pasting it again.
            'telegram_bot_token' => ['nullable', 'string', 'max:100', 'regex:/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64', self::CHAT_ID_RULE],
        ], [
            'telegram_bot_token.regex' => 'Bot Token ไม่ถูกรูปแบบ — ต้องหน้าตาแบบ 123456789:AAH… (คัดลอกจาก @BotFather มาทั้งบรรทัด)',
            'telegram_chat_id.regex' => 'Chat ID ต้องเป็นตัวเลข (กลุ่มจะขึ้นต้นด้วย -100) หรือ @ชื่อช่อง',
        ]);

        $status = 'บันทึกการตั้งค่า Telegram แล้ว';
        $token = trim((string) ($data['telegram_bot_token'] ?? ''));
        if ($token !== '') {
            // Ask Telegram before storing it: a typo found now is not a silent outage later.
            $who = TelegramNotifier::identify($token);
            if (! $who['ok'] && $who['reachable']) {
                return back()->withErrors(['telegram_bot_token' => $who['error']]);
            }
            Setting::write('telegram_bot_token', $token);
            Setting::write('telegram_bot_username', (string) ($who['username'] ?? ''));
            $status = $who['ok']
                ? "เชื่อมบอท @{$who['username']} แล้ว — ต่อไปเปิดแชทกับบอทแล้วกด Start จากนั้นกด \"ค้นหา Chat ID อัตโนมัติ\""
                : $status.' (ตอนนี้ยังตรวจ Token กับ Telegram ไม่ได้ — ลองกดทดสอบส่งภายหลัง)';
        }
        Setting::write('telegram_chat_id', trim((string) ($data['telegram_chat_id'] ?? '')));
        Setting::write('telegram_alerts_enabled', $request->boolean('telegram_alerts_enabled') ? '1' : '0');

        return back()->with('status', $status);
    }

    /** List the chats that have talked to the bot, so the owner picks one instead of hunting an id. */
    public function detectTelegram(): RedirectResponse
    {
        $found = TelegramNotifier::discoverChats();
        if ($found['error'] !== null) {
            return back()->withErrors(['telegram' => $found['error']]);
        }

        return back()
            ->with('tg_chats', $found['chats'])
            ->with('status', 'พบ '.count($found['chats']).' แชท — เลือกแชทที่จะรับแจ้งเตือนด้านล่าง');
    }

    public function useTelegramChat(Request $request): RedirectResponse
    {
        $data = $request->validate(['chat_id' => ['required', 'string', 'max:64', self::CHAT_ID_RULE]]);

        Setting::write('telegram_chat_id', $data['chat_id']);
        // Picking the chat to receive alerts in IS choosing to receive them.
        Setting::write('telegram_alerts_enabled', '1');

        return back()->with('status', 'ตั้งแชทปลายทางและเปิดแจ้งเตือน Telegram แล้ว — กด "ทดสอบส่ง" เพื่อดูการ์ดจริงได้เลย');
    }

    public function testTelegram(): RedirectResponse
    {
        return $this->test('telegram', 'Telegram');
    }

    /** Clear the stored token (e.g. after revoking it in @BotFather). */
    public function forgetTelegram(): RedirectResponse
    {
        Setting::write('telegram_bot_token', '');
        Setting::write('telegram_bot_username', '');
        Setting::write('telegram_alerts_enabled', '0');

        return back()->with('status', 'ลบ Bot Token แล้ว และปิดการแจ้งเตือน Telegram');
    }

    // ------------------------------------------------------------------------------ LINE

    public function updateLine(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'line_alerts_enabled' => ['sometimes', 'boolean'],
            // Blank = keep the stored token. That's what makes "write-only" workable: the admin can
            // change the recipient without having to paste the token again every time.
            'line_oa_token' => ['nullable', 'string', 'max:300'],
            'line_oa_to' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]*$/'],
        ], [
            'line_oa_to.regex' => 'ID ผู้รับต้องเป็นตัวอักษร/ตัวเลขเท่านั้น (เช่น Uxxxxxxxx หรือ Cxxxxxxxx)',
        ]);

        if (filled($data['line_oa_token'] ?? null)) {
            Setting::write('line_oa_token', trim($data['line_oa_token']));
        }
        Setting::write('line_oa_to', trim((string) ($data['line_oa_to'] ?? '')));
        Setting::write('line_alerts_enabled', $request->boolean('line_alerts_enabled') ? '1' : '0');

        return back()->with('status', 'บันทึกการตั้งค่า LINE แล้ว');
    }

    public function testLine(): RedirectResponse
    {
        return $this->test('line', 'LINE');
    }

    /** Clear the stored token (e.g. after rotating it in the LINE console). */
    public function forgetLine(): RedirectResponse
    {
        Setting::write('line_oa_token', '');
        Setting::write('line_alerts_enabled', '0');

        return back()->with('status', 'ลบ Token LINE แล้ว และปิดการแจ้งเตือน LINE');
    }

    /** Which categories go to which channel — the matrix under the two channel cards. */
    public function updateRoutes(Request $request): RedirectResponse
    {
        $request->validate([
            'routes' => ['nullable', 'array'],
            'routes.*' => ['array'],
            'routes.*.*' => ['string', Rule::in(array_keys(AdminAlerts::CATEGORIES))],
        ]);
        AdminAlerts::saveRoutes((array) $request->input('routes', []));

        return back()->with('status', 'บันทึกแล้วว่าเรื่องไหนส่งเข้าช่องทางไหน');
    }

    private function test(string $channel, string $name): RedirectResponse
    {
        [$ok, $error] = AdminAlerts::test($channel);

        return $ok
            ? back()->with('status', "ส่งข้อความทดสอบแล้ว — ลองเช็คใน {$name}")
            : back()->withErrors([$channel => $error ?? 'ส่งไม่สำเร็จ']);
    }

    // ------------------------------------------------------------------------------ preview

    /** The card design drawn from sample data — what arrives on the phone, before anything is set up. */
    public function preview(string $level): Response
    {
        $png = AlertCard::png($this->sample($level));
        abort_if($png === null, 404);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=600']);
    }

    private function sample(string $level): Alert
    {
        // Real source ids and their real last verdicts, so the example looks like this site.
        $chips = array_map(fn ($v) => empty($v['down']), SourceHealth::all());

        return match ($level) {
            Alert::CRITICAL => new Alert(
                key: 'source-down:ตัวอย่าง',
                level: Alert::CRITICAL,
                title: 'ดึงลิ้งค์จากแหล่ง "ตัวอย่าง" ไม่ได้เลย',
                body: "อาจเป็นเว็บต้นทางล่ม หรือเขาเปลี่ยนรูปแบบ URL/เพลเยอร์\nระบบพักการหยุดเผยแพร่อัตโนมัติของแหล่งนี้ไว้แล้ว หนังจะไม่ถูกปิดทิ้ง",
                facts: ['หนังที่กระทบ' => '1,234 เรื่อง', 'ผลตรวจล่าสุด' => '0/6 เล่นได้', 'แจ้งซ้ำ' => 'ทุก 6 ชม.'],
                chips: $chips,
                url: url('/admin'),
            ),
            Alert::WARNING => new Alert(
                key: 'digest-suspended',
                level: Alert::WARNING,
                title: 'หนังเล่นไม่ได้ถูกหยุดเผยแพร่อัตโนมัติ 18 เรื่อง',
                body: "• แหล่ง ตัวอย่าง-ก — 12 เรื่อง\n   - ชื่อเรื่องตัวอย่างที่หนึ่ง\n   - ชื่อเรื่องตัวอย่างที่สอง\n• แหล่ง ตัวอย่าง-ข — 6 เรื่อง",
                facts: ['ทั้งหมด' => '18 เรื่อง', 'จาก' => '2 แหล่ง'],
                bars: ['ตัวอย่าง-ก' => 12, 'ตัวอย่าง-ข' => 6],
                url: url('/admin/contents?filter=suspended'),
            ),
            Alert::INFO => new Alert(
                key: 'scrape:203.0.113.7',
                level: Alert::INFO,
                title: 'บล็อกบอทที่มาสแกนหาช่องโหว่',
                body: "พฤติกรรม: สแกนหาไฟล์ระบบ\nจำนวน: 42 ครั้ง ใน 3 นาที\nขออะไรบ้าง:\n• /wp-login.php ×18\n• /.env ×12",
                facts: ['IP' => '203.0.113.7', 'บทลงโทษ' => 'แบน 6 ชม.'],
                url: url('/admin/security'),
            ),
            default => new Alert(
                key: 'test',
                level: Alert::OK,
                title: 'ทดสอบการแจ้งเตือนจาก NetWix',
                body: 'ถ้าคุณเห็นข้อความนี้ แปลว่าระบบแจ้งเตือนปัญหาพร้อมใช้งานแล้ว',
                chips: $chips,
                url: url('/admin/alerts'),
            ),
        };
    }
}
