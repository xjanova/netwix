export const URBAN_ID = 'eppiocemhmnlbhjplcgkofciiegomcon';
const panels = new WeakMap();

export async function openUrbanPanel(context) {
    let page = panels.get(context);
    if (!page || page.isClosed()) {
        page = await context.newPage();
        await page.goto(`chrome-extension://${URBAN_ID}/popup/index.html`);
        panels.set(context, page);
    }
    return page;
}

export function urbanIsConnected(settings) {
    return settings?.levelOfControl === 'controlled_by_this_extension' &&
        ['fixed_servers', 'pac_script'].includes(settings?.value?.mode);
}

export async function urbanStatus(context) {
    const prefix = `chrome-extension://${URBAN_ID}/`;
    let worker = context.serviceWorkers().find(item => item.url().startsWith(prefix));
    if (!worker) {
        worker = await context.waitForEvent('serviceworker', {
            predicate: item => item.url().startsWith(prefix), timeout: 15000,
        });
    }
    const state = await worker.evaluate(() => new Promise(resolve => {
        chrome.proxy.settings.get({ incognito: false }, settings => {
            resolve({ levelOfControl: settings.levelOfControl, value: { mode: settings.value?.mode } });
        });
    }));
    return { installed: true, connected: urbanIsConnected(state), mode: state.value.mode };
}

// Locations tried first when RongYok blocks the current exit, then every other listed location.
export const DEFAULT_LOCATIONS = ['United States (USA)', 'Germany', 'United Kingdom (UK)', 'Singapore', 'Japan',
    'Netherlands', 'France', 'Canada', 'Hong Kong', 'Australia'];

/** The next location to try: preferred order first, never the current one or one cooling down. */
export function pickLocation(available, { current = null, cooling = new Set(), preferred = DEFAULT_LOCATIONS } = {}) {
    const usable = available.filter(name => name && name !== current && !cooling.has(name));
    return preferred.find(name => usable.includes(name)) ?? usable[0] ?? null;
}

/** Urban's own connection record: state, country code and exit host. No credentials. */
export async function urbanLocation(context) {
    const prefix = `chrome-extension://${URBAN_ID}/`;
    const worker = context.serviceWorkers().find(item => item.url().startsWith(prefix));
    if (!worker) return null;
    return worker.evaluate(() => new Promise(resolve => chrome.storage.local.get(['LOCATION_PROCESSOR_STATE'], all => {
        try {
            const s = JSON.parse(all.LOCATION_PROCESSOR_STATE);
            resolve({ connection: s.connection, code: s.location?.code ?? null, exit: s.proxy?.server?.host ?? null });
        } catch { resolve(null); }
    })));
}

/**
 * Pick another location in Urban's own panel, exactly as a person would, and wait until Urban
 * reports the new connection and controls the browser proxy again. Never clicks upsell, consent
 * or rating buttons: only the location selector and one listed location.
 *
 * @returns {Promise<{from: string|null, to: string, code: string|null}>}
 */
export async function switchUrbanLocation(context, { cooling = new Set(), preferred = DEFAULT_LOCATIONS, target = null, timeoutMs = 30000 } = {}) {
    const page = await openUrbanPanel(context);
    const view = page.locator('.location-view').first();
    if (!await view.count()) {
        await page.reload();
        await view.waitFor({ timeout: 10000 }).catch(() => { throw new Error('urban_switch_failed'); });
    }
    const from = (await page.locator('.location-view__name').first().innerText().catch(() => '')).trim() || null;
    const before = await urbanLocation(context).catch(() => null);
    await view.click();
    const options = page.locator('.selector-option');
    await options.first().waitFor({ timeout: 10000 }).catch(() => { throw new Error('urban_switch_failed'); });
    const names = (await options.allInnerTexts()).map(text => text.trim().split('\n')[0].trim());
    const to = target && names.includes(target) ? target : pickLocation(names, { current: from, cooling, preferred });
    if (!to) {
        await page.keyboard.press('Escape').catch(() => {});
        throw new Error('urban_no_location');
    }
    await options.filter({ hasText: to }).first().click();
    const until = Date.now() + timeoutMs;
    while (Date.now() < until) {
        await new Promise(resolve => setTimeout(resolve, 750));
        const now = await urbanLocation(context).catch(() => null);
        const moved = now && (now.code !== before?.code || now.exit !== before?.exit);
        if (now?.connection === 'CONNECTED' && moved) {
            const proxy = await urbanStatus(context).catch(() => null);
            if (proxy?.connected) return { from, to, code: now.code };
        }
    }
    const error = new Error('urban_switch_failed');
    error.location = to; // so the caller can cool this location down
    throw error;
}

export async function waitForUrban(context, timeoutMs = 20000) {
    // Urban initializes its saved connection when its normal control panel opens.
    // Opening the panel does not click consent dialogs or accept provider terms.
    await openUrbanPanel(context);
    const until = Date.now() + timeoutMs;
    while (Date.now() < until) {
        const panel = panels.get(context);
        if (panel && await panel.getByText('Registration error', { exact: true }).isVisible().catch(() => false)) {
            throw new Error('urban_registration_failed');
        }
        try {
            const status = await urbanStatus(context);
            if (status.connected) return status;
        } catch { /* Extension startup may still be in progress. */ }
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('urban_not_connected');
}
