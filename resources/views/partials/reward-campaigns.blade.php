@php
    $inviteCode = app(\App\Services\Membership::class)->ensureCode(auth()->user());
    $rewardCfg = ['list' => route('rewards.campaigns'), 'earn' => route('rewards.earn'), 'code' => $inviteCode, 'invite' => route('register', ['ref' => $inviteCode])];
@endphp
<section class="mx-auto max-w-5xl px-4 pt-24" x-data="nxCampaignPage(@js($rewardCfg))">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold">🧭 5 แคมเปญ · ดูเพลิน รับเหรียญฟรี</h1><p class="mt-2 text-sm text-cream/60">เว็บและแอปนับรวมกัน · โบนัสเหรียญเงินเพิ่มจากรางวัลปกติ · รีเซ็ตเวลาไทย</p></div>
        <button type="button" @click="reload()" class="rounded-lg bg-white/10 px-4 py-2 text-sm">อัปเดตความคืบหน้า</button>
    </div>
    <p x-show="message" x-text="message" class="mt-4 rounded-lg bg-white/5 p-3 text-sm" role="status"></p>
    <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <template x-for="c in items" :key="c.key">
            <article class="nx-card flex flex-col p-5">
                <div class="flex items-center justify-between gap-2"><span class="text-3xl" x-text="({daily:'📅',watch:'🎬',categories:'🧭',share:'💌',referral:'👥'})[c.kind]"></span><span class="text-xs text-cream/50" x-text="({daily:'รายวัน',weekly:'รายสัปดาห์',once:'ครั้งเดียว'})[c.repeat]"></span></div>
                <h2 class="mt-3 font-bold" x-text="c.title"></h2><p class="mt-2 flex-1 text-sm leading-relaxed text-cream/65" x-text="c.description"></p>
                <p class="mt-4 text-lg font-bold text-gold" x-text="'โบนัส +' + c.coins + ' เหรียญเงิน'"></p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/10"><div class="nx-gradient h-full" :style="'width:' + Math.min(100,c.progress/c.target*100) + '%'"></div></div>
                <p class="mt-2 text-xs text-cream/60" x-text="c.status === 'earned' ? '✓ รับโบนัสแล้วในรอบนี้' : c.progress + ' / ' + c.target"></p>
                <template x-if="c.status !== 'earned' && c.kind === 'daily'"><button @click="checkin()" :disabled="busy" class="btn-brand mt-4 py-2 text-sm">เช็กอินเลย</button></template>
                <template x-if="c.status !== 'earned' && (c.kind === 'share' || c.kind === 'referral')"><button @click="share()" :disabled="busy" class="btn-brand mt-4 py-2 text-sm" x-text="c.kind === 'referral' ? 'ชวนเพื่อน / คัดลอกลิงก์' : 'แชร์ / คัดลอกลิงก์'"></button></template>
                <template x-if="c.kind === 'watch' || c.kind === 'categories'"><div class="mt-4 flex flex-wrap gap-2 text-sm"><a href="{{ route('browse.movies') }}" class="rounded bg-white/10 px-3 py-2">หนัง</a><a href="{{ route('browse.series') }}" class="rounded bg-white/10 px-3 py-2">ซีรีส์</a><a href="{{ route('browse.vertical') }}" class="rounded bg-white/10 px-3 py-2">แนวตั้ง</a></div></template>
            </article>
        </template>
    </div>
    <p class="mt-4 text-xs leading-relaxed text-cream/45">นับเวลาเฉพาะเปิดวิดีโอเล่นจริงในหน้าที่กำลังใช้งาน เลื่อนข้ามหรือเปิดหลายอุปกรณ์พร้อมกันไม่เพิ่มเวลา · เรื่องเดิมนับได้เพียงครั้งเดียวต่อรอบ · การชวนเพื่อนนับเมื่อเพื่อนใช้โค้ดสำเร็จ</p>
</section>
