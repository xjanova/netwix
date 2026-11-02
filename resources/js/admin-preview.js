import { resolveEpisodeResponse } from './rongyok-client';

// One request at a time; closing/replacing the modal cancels the wait and ignores late replies.
export async function adminPreview(url, {isCurrent = () => true, fetcher = fetch,
    sleep = ms => new Promise(resolve => setTimeout(resolve, ms)), now = Date.now} = {}) {
    const until = now() + 120000;
    while (isCurrent() && now() < until) {
        const response = await fetcher(url, {headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'},
            signal: AbortSignal.timeout(15000)});
        if (!isCurrent()) return null;
        if (!response.ok) return null;
        const result = await resolveEpisodeResponse(response, fetcher);
        if (!isCurrent()) return null;
        if (result?.ready || result?.relay?.state !== 'pending') return result;
        await sleep(3000);
    }
    return null;
}
