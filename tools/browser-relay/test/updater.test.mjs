import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Updater, expectedHash, isNewer, pickRelease, sameDependencies, updatablePath } from '../updater.mjs';

const asset = (name) => ({ name, browser_download_url: `https://github.com/xjanova/netwix/releases/download/x/${name}` });
const release = (version, extra = {}) => ({
    tag_name: `relay-v${version}`, draft: false, prerelease: false,
    assets: [asset(`netwix-relay-${version}.zip`), asset('SHA256SUMS')], ...extra,
});

test('versions compare numerically, not as text', () => {
    assert.equal(isNewer('2.10.0', '2.9.9'), true);
    assert.equal(isNewer('2.1.0', '2.1.0'), false);
    assert.equal(isNewer('2.0.9', '2.1.0'), false);
    assert.equal(isNewer('v2.2.0', '2.1.0'), false);
});

test('only complete relay releases newer than the running one are picked; the app releases are ignored', () => {
    const list = [
        { tag_name: 'v0.3.0', draft: false, prerelease: false, assets: [asset('netwix.apk')] },
        release('2.1.0'), release('2.3.0', { draft: true }), release('2.4.0', { prerelease: true }),
        release('2.2.0'), release('2.5.0', { assets: [asset('netwix-relay-2.5.0.zip')] }), // no SHA256SUMS
    ];
    assert.equal(pickRelease(list, '2.1.0').version, '2.2.0');
    assert.equal(pickRelease(list, '2.2.0'), null);
    assert.equal(pickRelease(list, '2.1.0', v => v === '2.2.0'), null, 'a rolled-back version is skipped');
});

test('the checksum is read for the exact archive name', () => {
    const sums = `${'a'.repeat(64)}  netwix-relay-2.2.0.zip\n${'b'.repeat(64)}  other.zip\n`;
    assert.equal(expectedHash(sums, 'netwix-relay-2.2.0.zip'), 'a'.repeat(64));
    assert.equal(expectedHash(sums, 'netwix-relay-2.2.1.zip'), null);
});

test('an archive can never overwrite machine-local state or escape the folder', () => {
    for (const ok of ['worker.mjs', 'test/urban.test.mjs', 'deploy/deploy.sh']) assert.equal(updatablePath(ok), true, ok);
    for (const bad of ['.env', '.urban-profile/Default/Cookies', 'urban-extension/manifest.json', 'node_modules/x.js',
        '.browsec-profile/Default/Cookies', 'browsec-extension/manifest.json', 'vpn-state.json', 'vpn-state.json.tmp',
        'relay.db', '../evil.mjs', 'a/../../evil.mjs', 'C:/Windows/x', '/etc/passwd', '.update/rollback.json']) {
        assert.equal(updatablePath(bad), false, bad);
    }
});

test('a new root version alone does not count as a dependency change', () => {
    const dir = mkdtempSync(join(tmpdir(), 'relay-lock-'));
    const lock = (version, pw = '1.64.1') => JSON.stringify({ name: 'r', version, lockfileVersion: 3,
        packages: { '': { version, dependencies: { playwright: pw } }, 'node_modules/playwright': { version: pw } } });
    writeFileSync(join(dir, 'a.json'), lock('2.1.0'));
    writeFileSync(join(dir, 'b.json'), lock('2.2.0'));
    writeFileSync(join(dir, 'c.json'), lock('2.2.0', '1.65.0'));
    assert.equal(sameDependencies(join(dir, 'a.json'), join(dir, 'b.json')), true);
    assert.equal(sameDependencies(join(dir, 'a.json'), join(dir, 'c.json')), false);
});

test('checks are conditional: an unchanged list (304) reuses the last answer', async () => {
    const root = mkdtempSync(join(tmpdir(), 'relay-root-'));
    const seen = [];
    const fetcher = async (url, options) => {
        seen.push(options.headers['If-None-Match'] ?? null);
        if (seen.length === 1) return new Response(JSON.stringify([release('2.2.0')]), { status: 200, headers: { etag: '"abc"' } });
        return new Response(null, { status: 304 });
    };
    const updater = new Updater({ root, current: '2.1.0', fetcher });
    assert.equal((await updater.check()).version, '2.2.0');
    assert.equal((await updater.check()).version, '2.2.0');
    assert.deepEqual(seen, [null, '"abc"']);
    // run-worker.ps1 marks a version that crashed after install; it is not offered again.
    mkdirSync(join(root, '.update'));
    writeFileSync(join(root, '.update', 'bad-2.2.0'), 'x');
    assert.equal(await updater.check(), null);
});
