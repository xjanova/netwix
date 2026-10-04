import test from 'node:test';
import assert from 'node:assert/strict';
import { resolveRongYokClient, validRongYokVideoUrl, resolveEpisodeResponse } from '../../resources/js/rongyok-client.js';

const descriptor = {source: 'rongyok', series_id: '8207', episode: '2', endpoint: 'playseries.php'};
const video = 'https://cdn.discordapp.com/attachments/1/2/2.mp4?ex=' + (Math.floor(Date.now()/1000)+86400).toString(16) + '&is=abc&hm=def';
test('browser request is fixed-host and credential-free', async () => {
    const result = await resolveRongYokClient(descriptor, async (url, options) => {
        assert.equal(new URL(url).hostname, 'rongyok.com');
        assert.equal(options.credentials, 'omit');
        assert.equal(options.redirect, 'error');
        assert.deepEqual(Object.keys(options.headers), ['Accept']);
        return {ok: true, json: async () => ({ok: true, video_url: video})};
    });
    assert.equal(result, video);
});
test('CORS failure is handled without an open-proxy workaround', async () => {
    assert.equal(await resolveRongYokClient(descriptor, async () => { throw new TypeError('CORS'); }), null);
});
test('untrusted paths never fetch and unsafe video URLs never play', async () => {
    assert.equal(await resolveRongYokClient({...descriptor, endpoint: '../../secret.php'}, () => { throw new Error('must not fetch'); }), null);
    assert.equal(validRongYokVideoUrl(video.replace('cdn.discordapp.com', 'cdn.discordapp.com.evil.test')), false);
    assert.equal(validRongYokVideoUrl('https://127.0.0.1/video.mp4'), false);
});

test('web playback uses the device response and never opens the source site', async () => {
    const response = {status: 202, json: async () => ({ready: false, client_resolve: descriptor})};
    assert.deepEqual(await resolveEpisodeResponse(response, async () => ({ok: true, json: async () => ({ok: true, video_url: video})})), {ready: true, kind: 'mp4', url: video});
    const blocked = await resolveEpisodeResponse(response, async () => { throw new TypeError('CORS'); });
    assert.equal(blocked.client_blocked, true);
    assert.equal(blocked.source_watch, undefined);
});

test('web fallback cannot bypass a server access rejection', async () => {
    const denied = {ready: false, client_resolve: descriptor, error: 'pro_required'};
    assert.deepEqual(await resolveEpisodeResponse({status: 403, json: async () => denied}, () => {throw new Error('must not fetch');}), denied);
});
