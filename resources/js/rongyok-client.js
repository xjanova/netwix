// A browser can use its own connection only when the source permits CORS.
// Fixed host + validated identifiers; never forward NetWix cookies/bearer tokens.
export function validRongYokVideoUrl(value) {
    try {
        const u = new URL(value);
        const now = Math.floor(Date.now() / 1000);
        const ex = u.searchParams.get('ex') || '';
        return value.length <= 2048 && u.protocol === 'https:' &&
            ['cdn.discordapp.com', 'media.discordapp.net'].includes(u.hostname) &&
            !u.username && !u.password && !u.hash && (!u.port || u.port === '443') &&
            /^\/attachments\/\d+\/\d+\/[^/]+\.mp4$/i.test(u.pathname) &&
            /^[a-f0-9]{1,12}$/i.test(ex) && parseInt(ex, 16) > now + 300 && parseInt(ex, 16) <= now + 172800 &&
            !!u.searchParams.get('is') && !!u.searchParams.get('hm');
    } catch (_) { return false; }
}

export async function resolveRongYokClient(d, fetcher = fetch) {
    if (d?.source !== 'rongyok' || !/^\d{1,20}$/.test(String(d.series_id)) ||
        !/^[1-9]\d{0,3}$/.test(String(d.episode)) || !/^[a-z0-9_]{4,64}\.php$/i.test(String(d.endpoint))) return null;
    const url = new URL('https://rongyok.com/watch/' + d.endpoint);
    url.searchParams.set('series_id', d.series_id);
    url.searchParams.set('ep', d.episode);
    try {
        const r = await fetcher(url.href, {
            credentials: 'omit', redirect: 'error', mode: 'cors',
            headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000),
        });
        if (!r.ok) return null;
        const result = await r.json();
        return (result.ok === true || result.ok === 'true') && typeof result.video_url === 'string' &&
            validRongYokVideoUrl(result.video_url) ? result.video_url : null;
    } catch (_) { return null; }
}

// Keep the server's membership/visibility checks ahead of every device request.
export async function resolveEpisodeResponse(response, fetcher = fetch) {
    const payload = await response.json();
    if (response.status !== 202 || payload?.error || !payload?.client_resolve) return payload;
    const url = await resolveRongYokClient(payload.client_resolve, fetcher);
    return url ? {ready: true, url, kind: 'mp4'} : {...payload, client_blocked: true};
}
