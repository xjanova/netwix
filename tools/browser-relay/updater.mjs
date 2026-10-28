// Self-update from GitHub releases tagged `relay-v<semver>`.
//
// A release carries netwix-relay-<version>.zip and SHA256SUMS. The worker polls the releases API
// (conditional requests, so an unchanged list costs no rate limit), and when a newer version
// appears it drains its tabs, verifies and unpacks the archive, copies the program files over the
// current ones (never .env, profiles, the extension, relay.db or logs), keeps a backup for
// run-worker.ps1 to roll back to, and exits with UPDATE_EXIT_CODE so the launcher restarts it.

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';

export const UPDATE_EXIT_CODE = 75;
export const DEFAULT_REPO = 'xjanova/netwix';
const TAG = /^relay-v(\d+\.\d+\.\d+)$/;
const DOWNLOAD_HOSTS = ['github.com', 'api.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];

// Machine-local state that an update must never touch.
const PROTECTED = new Set(['.env', '.urban-profile', '.chrome-profile', '.browsec-profile', 'browsec-extension',
    'vpn-state.json', 'vpn-state.json.tmp', 'urban-extension', 'node_modules', '.update',
    'relay.db', 'relay.db-wal', 'relay.db-shm', 'status.json', 'worker.log', 'worker-error.log', 'worker.stop',
    'launcher.pid', 'probe-result.json', 'app-preview.png']);

export function parseVersion(value) {
    const m = /^(\d+)\.(\d+)\.(\d+)$/.exec(String(value ?? ''));
    return m ? m.slice(1).map(Number) : null;
}

export function isNewer(candidate, current) {
    const a = parseVersion(candidate);
    const b = parseVersion(current);
    if (!a || !b) return false;
    for (let i = 0; i < 3; i++) if (a[i] !== b[i]) return a[i] > b[i];
    return false;
}

/** The newest usable relay release in a releases-API page, or null. */
export function pickRelease(releases, current, skip = () => false) {
    let best = null;
    for (const release of Array.isArray(releases) ? releases : []) {
        const version = TAG.exec(release?.tag_name ?? '')?.[1];
        if (!version || release.draft || release.prerelease || !isNewer(version, current) || skip(version)) continue;
        const zip = release.assets?.find(a => a.name === `netwix-relay-${version}.zip`);
        const sums = release.assets?.find(a => a.name === 'SHA256SUMS');
        if (!zip || !sums) continue;
        if (!best || isNewer(version, best.version)) best = { version, zip: zip.browser_download_url, sums: sums.browser_download_url };
    }
    return best;
}

/** Expected hash of `name` from a SHA256SUMS body (`<hex>  <name>` lines). */
export function expectedHash(sums, name) {
    for (const line of String(sums).split(/\r?\n/)) {
        const m = /^([a-f0-9]{64})\s+\*?(.+)$/i.exec(line.trim());
        if (m && m[2].trim() === name) return m[1].toLowerCase();
    }
    return null;
}

/** Relative paths an archive may write: inside the program folder and not machine-local state. */
export function updatablePath(rel) {
    if (!rel || rel.startsWith('..') || /^[a-z]:/i.test(rel) || rel.startsWith('/') || rel.startsWith('\\')) return false;
    const parts = rel.split(/[\\/]/);
    return !parts.includes('..') && !PROTECTED.has(parts[0]);
}

async function download(url, { accept = 'application/octet-stream', binary = true } = {}) {
    // Follow GitHub's redirect by hand so the bytes only ever come from GitHub hosts.
    for (let hop = 0; hop < 5; hop++) {
        const u = new URL(url);
        if (u.protocol !== 'https:' || !DOWNLOAD_HOSTS.includes(u.hostname)) throw new Error('update_bad_host');
        const response = await fetch(u, { redirect: 'manual', headers: { Accept: accept, 'User-Agent': 'netwix-relay-updater' },
            signal: AbortSignal.timeout(120000) });
        if ([301, 302, 303, 307, 308].includes(response.status)) { url = new URL(response.headers.get('location'), u).href; continue; }
        if (!response.ok) throw new Error('update_download_' + response.status);
        return binary ? Buffer.from(await response.arrayBuffer()) : response.text();
    }
    throw new Error('update_redirects');
}

export class Updater {
    constructor({ root, current, repo = DEFAULT_REPO, fetcher = fetch }) {
        this.root = root;
        this.current = current;
        this.repo = repo;
        this.fetcher = fetcher;
        this.etag = null;
        this.cached = [];
    }

    /** Newest relay release above the running version, or null. A 304 reuses the last list. */
    async check() {
        const response = await this.fetcher(`https://api.github.com/repos/${this.repo}/releases?per_page=30`, {
            headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'netwix-relay-updater', ...(this.etag ? { 'If-None-Match': this.etag } : {}) },
            signal: AbortSignal.timeout(20000),
        });
        // A version run-worker.ps1 rolled back (it crashed on start) is never installed again.
        const bad = version => existsSync(join(this.root, '.update', 'bad-' + version));
        if (response.status === 304) return pickRelease(this.cached, this.current, bad);
        if (!response.ok) throw new Error('update_check_' + response.status);
        this.cached = await response.json();
        this.etag = response.headers.get('etag');
        return pickRelease(this.cached, this.current, bad);
    }

    /** Download, verify, unpack and install. Returns the files written. Throws without touching the program on any doubt. */
    async apply(release) {
        const name = `netwix-relay-${release.version}.zip`;
        const want = expectedHash(await download(release.sums, { binary: false }), name);
        if (!want) throw new Error('update_no_checksum');
        const archive = await download(release.zip);
        if (createHash('sha256').update(archive).digest('hex') !== want) throw new Error('update_checksum_mismatch');

        const work = join(this.root, '.update');
        const stage = join(work, 'stage-' + release.version);
        rmSync(stage, { recursive: true, force: true });
        mkdirSync(stage, { recursive: true });
        const zipPath = join(work, name);
        writeFileSync(zipPath, archive);
        const tar = process.platform === 'win32' ? join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe') : 'tar';
        execFileSync(tar, ['-xf', zipPath, '-C', stage], { stdio: 'ignore', timeout: 60000 });

        const pkg = JSON.parse(readFileSync(join(stage, 'package.json'), 'utf8'));
        if (pkg.version !== release.version || !existsSync(join(stage, 'worker.mjs'))) throw new Error('update_bad_archive');
        const files = walk(stage).map(file => relative(stage, file)).filter(updatablePath);

        // Back up what will be replaced; run-worker.ps1 restores it if the new version will not start.
        const backup = join(work, 'backup-' + this.current);
        rmSync(backup, { recursive: true, force: true });
        for (const rel of files) {
            const from = join(this.root, rel);
            if (!existsSync(from)) continue;
            mkdirSync(join(backup, rel, '..'), { recursive: true });
            cpSync(from, join(backup, rel));
        }
        const lockChanged = !sameDependencies(join(stage, 'package-lock.json'), join(this.root, 'package-lock.json'));
        for (const rel of files) {
            mkdirSync(join(this.root, rel, '..'), { recursive: true });
            cpSync(join(stage, rel), join(this.root, rel));
        }
        if (lockChanged) {
            const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
            execFileSync(npm, ['ci', '--omit=dev', '--registry=https://registry.npmjs.org'], { cwd: this.root, stdio: 'ignore', timeout: 600000, shell: process.platform === 'win32' });
            execFileSync(process.platform === 'win32' ? 'npx.cmd' : 'npx', ['playwright', 'install', 'chromium'], { cwd: this.root, stdio: 'ignore', timeout: 900000, shell: process.platform === 'win32' });
        }
        writeFileSync(join(work, 'rollback.json'), JSON.stringify({ from: this.current, to: release.version, backup, at: new Date().toISOString() }));
        rmSync(stage, { recursive: true, force: true });
        rmSync(zipPath, { force: true });
        return { files: files.length, lockChanged };
    }

    /** The new version is working: drop the rollback marker. */
    confirmHealthy() {
        const marker = join(this.root, '.update', 'rollback.json');
        if (existsSync(marker)) rmSync(marker, { force: true });
    }
}

function walk(dir) {
    return readdirSync(dir).flatMap(name => {
        const full = join(dir, name);
        return statSync(full).isDirectory() ? walk(full) : [full];
    });
}

/** Same installed packages? The root version changes every release and must not force a reinstall. */
export function sameDependencies(a, b) {
    const normal = file => {
        const lock = JSON.parse(readFileSync(file, 'utf8'));
        delete lock.version;
        if (lock.packages?.['']) delete lock.packages[''].version;
        return JSON.stringify(lock);
    };
    try { return normal(a) === normal(b); } catch { return false; }
}
