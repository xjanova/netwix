/** Position advancement + server time are checked together. Hidden/paused players never send beats. */
export function watchRewards(cfg, post = (...args) => window.nxPostSoft(...args)) {
    let token = null, episode = undefined, busy = false, last = 0;
    return {
        rewardNotice: '',
        async rewardTick() {
            const v = this.$refs.video;
            if (!cfg || !v || v.paused || v.ended || v.seeking || document.visibilityState !== 'visible' || !Number.isFinite(v.currentTime)) return;
            const ep = this.episodes[this.index]?.id ?? null;
            if (busy || Date.now() - last < 15000) return;
            busy = true; last = Date.now();
            try {
                if (ep !== episode) { token = null; episode = ep; }
                const result = token
                    ? await post(cfg.beat, { token, position: v.currentTime })
                    : await post(cfg.start, { content_id: cfg.content, episode_id: ep, position: v.currentTime });
                if (this.episodes[this.index]?.id !== ep && ep !== null) { token = null; return; }
                if (!result?.success) { token = null; return; }
                if (result.data?.token) token = result.data.token;
                const awards = result.data?.awards || [];
                if (awards.length) {
                    this.rewardNotice = '🪙 รับโบนัส ' + awards.reduce((sum, a) => sum + a.coins, 0) + ' เหรียญ · ' + awards.map(a => a.title).join(', ');
                    setTimeout(() => { this.rewardNotice = ''; }, 7000);
                }
            } finally { busy = false; }
        },
    };
}

export function campaignPage(cfg) {
    return {
        items: [], message: '', busy: false,
        async init() { await this.reload(); },
        async reload() {
            try { const r = await fetch(cfg.list, { headers: { Accept: 'application/json' } }); const d = await r.json(); if (d.success) this.items = d.data.items; }
            catch (_) { this.message = 'โหลดภารกิจไม่สำเร็จ ลองรีเฟรชอีกครั้ง'; }
        },
        async checkin() {
            if (this.busy) return;
            this.busy = true;
            try {
                const r = await window.nxPostSoft(cfg.earn, { kind: 'daily' });
                this.message = r.success ? 'เช็กอินและรับเหรียญแล้ว ✓' : (r.error || 'ลองอีกครั้ง');
                await this.reload();
            } finally { this.busy = false; }
        },
        async share() {
            if (this.busy) return;
            this.busy = true;
            try {
                if (navigator.share) await navigator.share({ title: 'มาดูหนังกับ NetWix', text: 'สมัครฟรีด้วยโค้ด ' + cfg.code, url: cfg.invite });
                else await navigator.clipboard.writeText(cfg.invite);
                const r = await window.nxPostSoft(cfg.earn, { kind: 'share' });
                this.message = r.success ? 'แชร์ / คัดลอกลิงก์แล้ว รับเหรียญเรียบร้อย ✓' : (r.error || 'แชร์ลิงก์แล้ว');
                await this.reload();
            } catch (e) { if (e.name !== 'AbortError') this.message = 'แชร์ไม่สำเร็จ กรุณาลองอีกครั้ง'; }
            finally { this.busy = false; }
        },
    };
}
