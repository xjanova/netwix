import test from 'node:test';
import assert from 'node:assert/strict';
import { watchRewards, campaignPage } from '../../resources/js/engagement-rewards.js';

test('web playback sends one active heartbeat stream and ignores paused, hidden and seeking media', async t => {
    let now = 100000;
    t.mock.method(Date, 'now', () => now);
    globalThis.document = { visibilityState: 'visible' };
    const calls = [];
    const post = async (url, body) => { calls.push({ url, body }); return { success: true, data: url === '/start' ? { token: 'server-token' } : { awards: [] } }; };
    const video = { paused: true, ended: false, seeking: false, currentTime: 0 };
    const p = { ...watchRewards({ content: 7, start: '/start', beat: '/beat' }, post), $refs: { video }, episodes: [{ id: 20 }, { id: 21 }], index: 0 };
    await p.rewardTick(); assert.equal(calls.length, 0);
    video.paused = false;
    await p.rewardTick(); assert.equal(calls[0].url, '/start'); assert.equal(calls[0].body.episode_id, 20);
    await p.rewardTick(); assert.equal(calls.length, 1);
    now += 15000; video.currentTime = 15;
    await p.rewardTick(); assert.equal(calls[1].url, '/beat'); assert.equal(calls[1].body.token, 'server-token');
    now += 15000; document.visibilityState = 'hidden';
    await p.rewardTick(); assert.equal(calls.length, 2);
    document.visibilityState = 'visible'; video.seeking = true;
    await p.rewardTick(); assert.equal(calls.length, 2);
    video.seeking = false; p.index = 1;
    await p.rewardTick(); assert.equal(calls[2].url, '/start'); assert.equal(calls[2].body.episode_id, 21);
});

test('guest players never request campaign credit', async () => {
    let calls = 0;
    const p = { ...watchRewards(null, async () => { calls++; }), $refs: { video: { paused: false, currentTime: 10 } } };
    await p.rewardTick(); assert.equal(calls, 0);
});

test('returning from hidden playback starts a new baseline and does not credit the hidden gap', async t => {
    let now = 100000;
    t.mock.method(Date, 'now', () => now);
    globalThis.document = { visibilityState: 'visible' };
    const calls = [];
    const p = { ...watchRewards({ content: 7, start: '/start', beat: '/beat' }, async url => {
        calls.push(url); return { success: true, data: { token: 'token' } };
    }), $refs: { video: { paused: false, ended: false, seeking: false, currentTime: 0 } }, episodes: [{id:1}], index:0 };
    await p.rewardTick(); document.visibilityState = 'hidden'; now += 10000;
    await p.rewardTick(); document.visibilityState = 'visible'; p.$refs.video.currentTime = 10;
    await p.rewardTick(); assert.deepEqual(calls, ['/start','/start']);
});

test('cancelled native sharing is not rewarded', async () => {
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { share: async () => { const e = new Error('cancel'); e.name = 'AbortError'; throw e; } } });
    let paid = false;
    globalThis.window = { nxPostSoft: async () => { paid = true; } };
    const page = campaignPage({ code: 'ABC', invite: 'https://netwix.online/register?ref=ABC' });
    await page.share(); assert.equal(paid, false); assert.equal(page.busy, false);
});
