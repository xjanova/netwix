@php
    $ratingAvg = round((float) $content->ratings()->avg('stars'), 1);
    $ratingCount = $content->ratings()->count();
    $myRating = isset($currentProfile) ? (int) $content->ratings()->where('profile_id', $currentProfile->id)->value('stars') : 0;
    $comments = $content->comments()->with('profile')->latest()->limit(30)->get();
    $commentCount = $content->comments()->count();
    $shareUrl = route('title.show', $content);
    $commentsJs = $comments->map(fn ($c) => [
        'author' => $c->profile?->name ?? 'สมาชิก',
        'color' => $c->profile?->avatar_color ?? '#8b2ff0',
        'initial' => $c->profile?->initial ?? 'N',
        'text' => $c->body,
        'ago' => optional($c->created_at)->diffForHumans(),
    ])->values();
@endphp

<div class="mt-8 border-t border-white/10 pt-6">
    {{-- rating + share --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div x-data="{ my: {{ $myRating }}, avg: {{ $ratingAvg }}, count: {{ $ratingCount }}, hover: 0, busy: false,
                async rate(n) { if (this.busy) return; this.busy = true;
                    try { const d = await nxPost('{{ route('content.rate', $content) }}', { stars: n });
                        this.my = d.my_rating; this.avg = d.avg; this.count = d.count; } catch (e) {} finally { this.busy = false; } } }">
            <div class="flex items-center gap-1">
                <template x-for="n in 5" :key="n">
                    <button type="button" @click="rate(n)" @mouseenter="hover = n" @mouseleave="hover = 0"
                            class="text-2xl leading-none transition" :class="(hover || my) >= n ? 'text-gold' : 'text-cream/25'">★</button>
                </template>
                <span class="ml-2 text-sm text-cream/70"><span x-text="avg || '-'"></span> <span class="text-cream/45">(<span x-text="count"></span>)</span></span>
            </div>
            <p class="mt-1 text-[12px] text-brand-2" x-show="my > 0" x-cloak>ให้คะแนน <span x-text="my"></span> ดาว · แตะเพื่อแก้</p>
        </div>

        <div class="flex items-center gap-2" x-data="{ copied: false }">
            <span class="text-[13px] text-cream/45">แชร์</span>
            <a href="https://social-plugins.line.me/lineit/share?url={{ urlencode($shareUrl) }}" target="_blank" rel="noopener"
               class="flex h-8 w-8 items-center justify-center rounded-full text-white" style="background:#06C755" title="LINE">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.48 3 2 6.63 2 11.02c0 3.93 3.32 7.22 7.8 7.85.3.06.72.2.82.46.09.24.06.6.03.85l-.13.79c-.04.24-.19.94.83.51 1.02-.43 5.5-3.24 7.5-5.55C20.5 14.42 22 12.86 22 11.02 22 6.63 17.52 3 12 3z"/></svg>
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}" target="_blank" rel="noopener"
               class="flex h-8 w-8 items-center justify-center rounded-full text-white" style="background:#1877F2" title="Facebook">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>
            </a>
            <button type="button" @click="navigator.clipboard.writeText('{{ $shareUrl }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                    class="flex h-8 items-center rounded-full border border-white/15 px-3 text-xs hover:bg-white/5">
                <span x-text="copied ? 'คัดลอกแล้ว ✓' : 'คัดลอกลิงก์'"></span>
            </button>
        </div>
    </div>

    {{-- comments --}}
    <div class="mt-6" x-data="{ list: {{ \Illuminate\Support\Js::from($commentsJs) }}, count: {{ $commentCount }}, body: '', busy: false,
            async post() { if (this.busy || !this.body.trim()) return; this.busy = true;
                const ts = window.turnstile ? window.turnstile.getResponse() : '';
                try { const d = await nxPost('{{ route('content.comment', $content) }}', { body: this.body, 'cf-turnstile-response': ts });
                    this.list.unshift(d.comment); this.count = d.count; this.body = ''; window.turnstile && window.turnstile.reset(); } catch (e) {} finally { this.busy = false; } } }">
        <h3 class="mb-3 text-base font-semibold">ความคิดเห็น <span class="text-cream/45">(<span x-text="count"></span>)</span></h3>
        <form @submit.prevent="post()" class="mb-4">
            <div class="flex gap-2">
                <input x-model="body" maxlength="500" placeholder="ร่วมพูดคุยเกี่ยวกับเรื่องนี้…"
                       class="flex-1 rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2.5 text-sm outline-none focus:border-brand">
                <button type="submit" :disabled="busy || !body.trim()" class="btn-brand px-5 text-sm disabled:opacity-40">ส่ง</button>
            </div>
            @include('partials.turnstile')
        </form>
        <div class="flex flex-col gap-3.5">
            <template x-for="(c, i) in list" :key="i">
                <div class="flex gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-black/60"
                          :style="'background:' + (c.color || '#8b2ff0')" x-text="c.initial || (c.author || '?').charAt(0)"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-[13px]"><span class="font-semibold" x-text="c.author"></span><span class="text-cream/40" x-text="c.ago"></span></div>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-cream/80" x-text="c.text"></p>
                    </div>
                </div>
            </template>
            <p x-show="list.length === 0" class="py-6 text-center text-sm text-cream/40">ยังไม่มีความคิดเห็น — เป็นคนแรกเลย!</p>
        </div>
    </div>
</div>
