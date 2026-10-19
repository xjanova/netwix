import test from 'node:test';
import assert from 'node:assert/strict';
import { validVideoUrl, validJob, watchUrl, matchesEpisodeResponse, responseEpisode, jobContext } from '../resolver.mjs';

const job = { id: 8, lease_token: 'a'.repeat(64), source: 'rongyok', series_id: '8457', episode: '51' };
const now = 1792350000;
const video = `https://cdn.discordapp.com/attachments/123/456/ep51.mp4?ex=${(now + 3600).toString(16)}&is=abc&hm=def`;

test('a worker cannot be directed to an arbitrary host, source or path', () => {
    assert.equal(watchUrl(job), 'https://rongyok.com/watch/?series_id=8457&ep=51');
    for (const change of [{ source: 'other' }, { series_id: '../admin' }, { episode: '51&ep=52' }, { id: -1 }, { lease_token: '' }]) {
        assert.equal(validJob({ ...job, ...change }), false);
        assert.throws(() => watchUrl({ ...job, ...change }));
    }
});

test('prefetched next-episode responses cannot become the current episode link', () => {
    assert.equal(matchesEpisodeResponse('https://rongyok.com/watch/playseries.php?series_id=8457&ep=51', job), true);
    for (const url of ['https://rongyok.com/watch/playseries.php?series_id=8457&ep=52',
        'https://rongyok.com/watch/playseries.php?series_id=8458&ep=51',
        'https://rongyok.com.evil.test/watch/playseries.php?series_id=8457&ep=51']) {
        assert.equal(matchesEpisodeResponse(url, job), false);
    }
});

test('only fresh, signed MP4 CDN links can be returned', () => {
    assert.equal(validVideoUrl(video, now), true);
    assert.equal(validVideoUrl(video, now + 3400), false);
    assert.equal(validVideoUrl(video, now - 172800), false);
    for (const url of [video.replace('cdn.discordapp.com', 'cdn.discordapp.com.evil.test'),
        video.replace('https:', 'http:'), video.replace('.mp4', '.html'), video + '#fragment',
        video.replace('https://', 'https://user:password@'), 'https://127.0.0.1/private.mp4', null]) {
        assert.equal(validVideoUrl(url, now), false);
    }
});

test('other episodes of the same series are recognised as extras, other series are not', () => {
    assert.equal(responseEpisode('https://rongyok.com/watch/playseries.php?series_id=8457&ep=52', job), '52');
    assert.equal(responseEpisode('https://rongyok.com/watch/playseries.php?series_id=8458&ep=52', job), null);
    assert.equal(responseEpisode('https://rongyok.com/watch/playseries.php?series_id=8457&ep=0', job), null);
    assert.equal(responseEpisode('https://evil.test/watch/playseries.php?series_id=8457&ep=52', job), null);
});

test('request context from the server is display-only and bounded', () => {
    const ctx = jobContext({ ...job, title: 'เรื่อง\u0007ทดสอบ' + 'x'.repeat(200), episode_number: 52, reason: 'viewer',
        requester: { member: true, name: 'คุณสมใจ', via: 'web', email: 'x@y.z' } });
    assert.equal(ctx.title.length, 120);
    assert.equal(ctx.title.includes('\u0007'), false);
    assert.deepEqual([ctx.episode_number, ctx.reason, ctx.requester, ctx.via], [52, 'viewer', 'คุณสมใจ', 'web']);
    assert.equal(JSON.stringify(ctx).includes('@'), false);
    assert.equal(jobContext({ ...job, requester: { member: false, via: 'app' } }).requester, 'guest');
    assert.deepEqual(jobContext({ ...job, reason: 'drop table', requester: { via: 'ftp' } }),
        { title: null, episode_number: null, reason: null, requester: null, via: null });
});
