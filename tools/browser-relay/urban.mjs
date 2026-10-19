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

export async function waitForUrban(context, timeoutMs = 20000) {
    // Urban initializes its saved connection when its normal control panel opens.
    // Opening the panel does not click consent dialogs or accept provider terms.
    await openUrbanPanel(context);
    const until = Date.now() + timeoutMs;
    while (Date.now() < until) {
        try {
            const status = await urbanStatus(context);
            if (status.connected) return status;
        } catch { /* Extension startup may still be in progress. */ }
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('urban_not_connected');
}
