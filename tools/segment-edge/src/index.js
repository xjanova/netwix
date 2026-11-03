/**
 * NetWix segment edge — fetches HLS segments from Cloudflare's network instead of our origin.
 *
 * Some source CDNs (24hdx's wave26092026.online / thairedone.com, anime108's site26092026.online)
 * throttle datacenter IPs to a few KB/s per IP. Our origin and the relay box both sit in such a
 * range, so a 600 KB segment could not finish inside the origin proxy's 20 s budget and the player
 * stuttered (measured 2026-11-03: ~2-10 KB/s from 123.253.62.x vs ~0.5-3.5 MB/s from a home line).
 *
 * The manifest (StreamController::rewrite) points those sources' segments here with the same sealed
 * handle format it uses for its own /segment route, but under a separate key pair
 * (SEAL_ENC / SEAL_MAC, derived from APP_KEY with their own labels), so this worker can open a
 * handle and nothing else: it can't forge a handle the origin accepts.
 *
 *   GET /{episodeId}/segment?c=<sealed exp|base|referer>&p=<sealed rest>
 */

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
const ATTEMPT_MS = 25000;
const CACHE_SECONDS = 86400;
const OWN_REFERER = /^https?:\/\/(www\.)?netwix\.online(?::\d+)?(?:[/?#]|$)/i;
const HANDLE = /^[A-Za-z0-9_-]+$/;

const enc = new TextEncoder();
const dec = new TextDecoder();

export default {
  async fetch(request, env, ctx) {
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: cors() });
    }
    if (request.method !== 'GET' && request.method !== 'HEAD') {
      return text(405, 'method not allowed');
    }
    const url = new URL(request.url);
    const m = url.pathname.match(/^\/(\d+)\/segment$/);
    if (!m) return text(404, 'not found');

    // Same rule as the origin: a foreign page embedding our player must not get the segments.
    const ref = request.headers.get('referer') || '';
    if (ref !== '' && !OWN_REFERER.test(ref)) return text(403, 'forbidden');

    const opened = await openHandle(env, m[1], url.searchParams.get('c') || '', url.searchParams.get('p') || '');
    if (!opened) return text(403, 'forbidden');
    const [target, referer] = opened;

    // Keyed on the upstream URL, not on ours: our URL changes every seal bucket (3 h), the segment
    // itself never does, so every viewer of the episode shares one cached copy for a day.
    const cacheKey = new Request('https://segment-edge.cache/' + (await sha256Hex(target)));
    const cache = caches.default;
    const hit = await cache.match(cacheKey);
    if (hit) return withCors(hit, 'HIT');

    const body = await fetchUpstream(target, referer);
    if (!body) return text(502, 'upstream unavailable');

    const res = new Response(stripToTsSync(body), {
      headers: { 'Content-Type': 'video/mp2t', 'Cache-Control': `public, max-age=${CACHE_SECONDS}` },
    });
    ctx.waitUntil(cache.put(cacheKey, res.clone()));
    return withCors(res, 'MISS');
  },
};

async function fetchUpstream(target, referer) {
  const headers = { 'User-Agent': UA, Accept: '*/*' };
  if (referer) headers.Referer = referer;
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const r = await fetch(target, { headers, signal: AbortSignal.timeout(ATTEMPT_MS) });
      if (r.ok) return new Uint8Array(await r.arrayBuffer());
    } catch (_) {
      // timeout / reset: one more try
    }
  }
  return null;
}

/**
 * Port of App\Support\HlsSegment::stripToTsSync — drop a fake-image wrapper in front of the MPEG-TS
 * payload by anchoring on the first 0x47 that starts a 188-byte-aligned run of sync bytes.
 */
export function stripToTsSync(data) {
  if (data[0] === 0x47) return data;
  const limit = Math.min(data.length - 3 * 188, 4096);
  for (let i = 0; i < limit; i++) {
    if (data[i] === 0x47 && data[i + 188] === 0x47 && data[i + 376] === 0x47 && data[i + 564] === 0x47) {
      return data.subarray(i);
    }
  }
  const pos = data.indexOf(0x47);
  return pos > 0 && pos < 512 ? data.subarray(pos) : data;
}

/** Mirror of StreamController::openHandle, under this worker's own key pair. */
export async function openHandle(env, episodeId, c, p, now = Date.now() / 1000) {
  if (c === '' || p === '') return null;
  const ctx = await sivOpen(env, 'c|' + episodeId, c);
  if (ctx === null) return null;
  const first = ctx.indexOf('|');
  const second = ctx.indexOf('|', first + 1);
  if (first < 0 || second < 0) return null;
  const exp = Number(ctx.slice(0, first));
  const base = ctx.slice(first + 1, second);
  const referer = ctx.slice(second + 1);
  if (!Number.isFinite(exp) || exp < now) return null;

  const rest = await sivOpen(env, 'p|' + episodeId + '|' + c, p);
  if (rest === null) return null;
  const target = rest[0] === 'r' ? base + rest.slice(1) : rest[0] === 'a' ? rest.slice(1) : '';
  if (!target.startsWith('https://')) return null;
  return [target, referer !== '' ? referer : null];
}

/** Mirror of StreamController::sivOpen: AES-256-CTR, IV = first 16 bytes of HMAC-SHA256(ctx \0 plain). */
async function sivOpen(env, context, handle) {
  if (!HANDLE.test(handle)) return null;
  const bin = b64urlDecode(handle);
  if (!bin || bin.length < 17) return null;
  const [encKey, macKey] = await keys(env);
  const iv = bin.slice(0, 16);
  const plain = new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-CTR', counter: iv, length: 128 }, encKey, bin.slice(16)));
  const ctxBytes = enc.encode(context + '\0');
  const msg = new Uint8Array(ctxBytes.length + plain.length);
  msg.set(ctxBytes);
  msg.set(plain, ctxBytes.length);
  const mac = new Uint8Array(await crypto.subtle.sign('HMAC', macKey, msg)).slice(0, 16);
  let diff = 0;
  for (let i = 0; i < 16; i++) diff |= mac[i] ^ iv[i];
  return diff === 0 ? dec.decode(plain) : null;
}

let keyCache = null;
async function keys(env) {
  if (keyCache && keyCache.src === env.SEAL_ENC + env.SEAL_MAC) return keyCache.keys;
  const encKey = await crypto.subtle.importKey('raw', b64Decode(env.SEAL_ENC), 'AES-CTR', false, ['decrypt']);
  const macKey = await crypto.subtle.importKey('raw', b64Decode(env.SEAL_MAC), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  keyCache = { src: env.SEAL_ENC + env.SEAL_MAC, keys: [encKey, macKey] };
  return keyCache.keys;
}

function b64Decode(s) {
  return Uint8Array.from(atob(s), (ch) => ch.charCodeAt(0));
}

function b64urlDecode(s) {
  try {
    const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
    return b64Decode(s.replace(/-/g, '+').replace(/_/g, '/') + pad);
  } catch (_) {
    return null;
  }
}

async function sha256Hex(s) {
  const d = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(s)));
  return Array.from(d, (b) => b.toString(16).padStart(2, '0')).join('');
}

function cors() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, HEAD, OPTIONS',
    'Access-Control-Max-Age': '86400',
  };
}

function withCors(res, status) {
  const out = new Response(res.body, res);
  for (const [k, v] of Object.entries(cors())) out.headers.set(k, v);
  out.headers.set('X-Edge-Cache', status);
  return out;
}

function text(status, body) {
  return new Response(body, { status, headers: { 'Content-Type': 'text/plain', ...cors() } });
}
