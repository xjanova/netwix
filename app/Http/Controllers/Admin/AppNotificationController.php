<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Setting;
use App\Services\FcmSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin CRUD for mobile-app broadcast notifications ("แจ้งเตือนในแอป").
 * Mirrors AnnouncementController's shape; the app inbox reads these via
 * GET /api/app/notifications.
 */
class AppNotificationController extends Controller
{
    public function index(): View
    {
        return view('admin.app-notifications.index', [
            'notifications' => AppNotification::orderByDesc('id')->limit(200)->get(),
            'categories' => AppNotification::CATEGORIES,
            'fcmConfigured' => FcmSender::configured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['published_at'] ??= now();
        $n = AppNotification::create($data);

        // Real push to the category topic (best-effort — inbox always works).
        $pushed = $n->is_active && $n->published_at <= now() && FcmSender::sendNotification($n);

        return back()->with('status', $pushed
            ? 'ส่งแจ้งเตือนแล้ว — push ถึงเครื่องผู้ใช้ + กล่องแจ้งเตือนในแอป'
            : 'ส่งแจ้งเตือนแล้ว — แสดงในกล่องแจ้งเตือนของแอป'.(FcmSender::configured() ? ' (ส่ง push ไม่สำเร็จ)' : ' (ยังไม่ได้ตั้งค่า FCM push)'));
    }

    /** Save/replace the FCM service-account JSON (encrypted at rest). */
    public function saveFcm(Request $request): RedirectResponse
    {
        $data = $request->validate(['fcm_service_account' => ['nullable', 'string', 'max:20000']]);

        $raw = trim((string) ($data['fcm_service_account'] ?? ''));
        if ($raw !== '') {
            $j = json_decode($raw, true);
            if (! is_array($j) || ! isset($j['client_email'], $j['private_key'], $j['project_id'])) {
                return back()->withErrors(['fcm_service_account' => 'ไฟล์ service account ไม่ถูกต้อง — ต้องเป็น JSON ที่มี project_id, client_email, private_key']);
            }
        }

        Setting::write('fcm_service_account', $raw !== '' ? $raw : null);

        return back()->with('status', $raw !== '' ? 'บันทึกคีย์ FCM แล้ว — เปิดส่ง push ได้ทันที' : 'ลบคีย์ FCM แล้ว (ปิด push)');
    }

    public function update(Request $request, AppNotification $notification): RedirectResponse
    {
        $data = $this->validated($request);
        // The edit form has no published_at field — keep the original publish
        // date on a plain edit instead of silently re-dating it to "now".
        if (! $request->filled('published_at')) {
            unset($data['published_at']);
        }
        $notification->update($data);

        return back()->with('status', 'บันทึกแจ้งเตือนแล้ว');
    }

    public function destroy(AppNotification $notification): RedirectResponse
    {
        $notification->delete();

        return back()->with('status', 'ลบแจ้งเตือนแล้ว');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(AppNotification::CATEGORIES))],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'published_at' => ['nullable', 'date'],
        ], [
            'title.required' => 'กรุณากรอกหัวข้อแจ้งเตือน',
            'body.required' => 'กรุณากรอกข้อความแจ้งเตือน',
            'image_url.url' => 'ลิงก์รูปต้องเป็น URL ที่ถูกต้อง',
            'link_url.url' => 'ลิงก์ต้องเป็น URL ที่ถูกต้อง',
        ]);

        return [
            'category' => $data['category'],
            'title' => $data['title'],
            'body' => $data['body'],
            'image_url' => $data['image_url'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
