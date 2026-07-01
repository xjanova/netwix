<p align="center"><img src="public/assets/netwix-logo.png" width="220" alt="NetWix"></p>

# NetWix

บริการสตรีมมิ่งภาพยนตร์ ซีรีส์ และ **ซีรีส์แนวตั้ง** สร้างด้วย **Laravel 12** + **Tailwind CSS v4** + **Alpine.js**
มีทั้งหน้าบ้าน (ผู้ชม) และหลังบ้าน (แผงผู้ดูแล) ครบในโปรเจกต์เดียว

Netflix-style streaming platform (Thai UI) — front-of-house viewer experience **and** a custom admin panel,
faithful to the NetWix design system (dark `#07050c`, pink→purple `#ff2d55 → #b026ff`, Kanit).

---

## ฟีเจอร์ / Features

**หน้าบ้าน (Viewer)**
- เข้าสู่ระบบ / สมัครสมาชิก ธีมเฉพาะตัว
- โปรไฟล์หลายคน + โปรไฟล์เด็ก (KIDS) — "ใครกำลังดูอยู่?"
- หน้าเบราส์: Hero วิดีโอเล่นอัตโนมัติ, แถวคอนเทนต์เลื่อนแนวนอน, hover preview
- ซีรี่ส์ / ภาพยนตร์ / **ซีรีส์แนวตั้ง** (เล่นแบบปัดขึ้น-ลง), รายการของฉัน, ค้นหาสด
- หน้ารายละเอียด (ซีซั่น/ตอน), เครื่องเล่นรองรับ **YouTube / MP4 / HLS (.m3u8)**
- บันทึกความคืบหน้าการดู, กดถูกใจ, เพิ่มรายการของฉัน

**หลังบ้าน (Admin — custom Blade)**
- แดชบอร์ด: การ์ดสถิติ, กราฟกิจกรรม 7 วัน, สัดส่วนหมวด, คอนเทนต์ยอดนิยม (คำนวณจากข้อมูลจริง)
- จัดการคอนเทนต์ (สร้าง/แก้ไข/ลบ + จัดการตอน/ซีซั่น), หมวดหมู่, สมาชิก, วิเคราะห์ข้อมูล

## เทคโนโลยี / Stack

| | |
|---|---|
| Backend | Laravel 12 (PHP 8.3) |
| Frontend | Blade + Tailwind CSS v4 + Alpine.js + hls.js |
| Database | MySQL 8 |
| Hosting | DirectAdmin (netwix.online) |

## เริ่มต้นใช้งาน (Local)

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # ต้องตั้ง DB ใน .env ก่อน
npm run dev                     # หรือ npm run build
php artisan serve
```

บัญชีทดลอง (จาก seeder): `demo@netwix.online` / `netwix-demo-2026`
ผู้ดูแล: `admin@netwix.online` / ค่าจาก `SEED_ADMIN_PASSWORD`

## โครงสร้างคอนเทนต์

- **Content** — เรื่อง (series / movie / vertical) พร้อม genres, seasons, episodes
- **Profile** — โปรไฟล์ผู้ชมของแต่ละบัญชี (มี My List, Likes, Watch Progress)
- วิดีโอเก็บเป็น **URL** (YouTube id, ไฟล์ mp4 หรือ HLS `.m3u8`) ต่อ episode/movie

## Deploy (production)

โปรเจกต์ทั้งหมดวางใน `public_html/` ของ DirectAdmin โดยมี `.htaccess` ที่ root เขียน rewrite เข้าสู่ `public/`
(ดู `public/.htaccess-root`). Redeploy รุ่นใหม่:

```bash
./deploy/deploy.sh v0.1.0     # หรือ main
```

CI (GitHub Actions) รัน composer install, build front-end, migrate และ `php artisan test` ทุกครั้งที่ push
