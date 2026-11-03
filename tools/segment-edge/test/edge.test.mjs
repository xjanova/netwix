import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createCipheriv, createHmac } from 'node:crypto';
import { openHandle, stripToTsSync } from '../src/index.js';

// The worker must open exactly what StreamController::sivSeal mints (AES-256-CTR, IV = HMAC prefix).
const ENC = Buffer.alloc(32, 'e');
const MAC = Buffer.alloc(32, 'm');
const env = { SEAL_ENC: ENC.toString('base64'), SEAL_MAC: MAC.toString('base64') };

function seal(context, plain) {
  const iv = createHmac('sha256', MAC).update(context + '\0' + plain).digest().subarray(0, 16);
  const c = createCipheriv('aes-256-ctr', ENC, iv);
  const out = Buffer.concat([iv, c.update(plain, 'utf8'), c.final()]);
  return out.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

const now = 1_800_000_000;
const handle = (ep, rest, exp = now + 3600, base = 'https://cdn.test/hls/', ref = 'https://player.test/') => {
  const c = seal('c|' + ep, `${exp}|${base}|${ref}`);
  return { c, p: seal('p|' + ep + '|' + c, rest) };
};

test('opens a handle the origin sealed, relative or absolute', async () => {
  const r = handle(7, 'rseg-1.ts');
  assert.deepEqual(await openHandle(env, '7', r.c, r.p, now), ['https://cdn.test/hls/seg-1.ts', 'https://player.test/']);
  const a = handle(7, 'ahttps://other.test/x.ts', now + 60, 'https://cdn.test/', '');
  assert.deepEqual(await openHandle(env, '7', a.c, a.p, now), ['https://other.test/x.ts', null]);
});

test('refuses an expired, tampered, misplaced or non-https handle', async () => {
  const h = handle(7, 'rseg-1.ts');
  assert.equal(await openHandle(env, '7', h.c, h.p, now + 7200), null);
  assert.equal(await openHandle(env, '8', h.c, h.p, now), null);
  const flip = (s) => s.slice(0, 20) + (s[20] === 'A' ? 'B' : 'A') + s.slice(21);
  assert.equal(await openHandle(env, '7', flip(h.c), h.p, now), null);
  assert.equal(await openHandle(env, '7', h.c, flip(h.p), now), null);
  assert.equal(await openHandle(env, '7', h.c, '', now), null);
  assert.equal(await openHandle(env, '7', 'not a handle!', h.p, now), null);
  const plain = handle(7, 'ahttp://cdn.test/seg.ts');
  assert.equal(await openHandle(env, '7', plain.c, plain.p, now), null);
  // A tail sealed under one context can't ride under another.
  const other = handle(7, 'rseg-2.ts', now + 1800);
  assert.equal(await openHandle(env, '7', other.c, h.p, now), null);
});

test('strips a fake-image wrapper down to the TS sync run', () => {
  const ts = new Uint8Array(188 * 5);
  for (let i = 0; i < 5; i++) ts[i * 188] = 0x47;
  assert.equal(stripToTsSync(ts), ts);

  const png = Uint8Array.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, ...new Array(244).fill(0)]);
  const wrapped = new Uint8Array(png.length + ts.length);
  wrapped.set(png);
  wrapped.set(ts, png.length);
  const out = stripToTsSync(wrapped);
  assert.equal(out.length, ts.length);
  assert.equal(out[0], 0x47);
});
