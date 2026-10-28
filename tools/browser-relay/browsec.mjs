export const BROWSEC_ID = 'omghfjlpggmjjaagoclmmobgdodcjboh';
const panels = new WeakMap();

export async function openBrowsecPanel(context) {
    let page = panels.get(context);
    if (!page || page.isClosed()) {
        page = await context.newPage();
        await page.goto(`chrome-extension://${BROWSEC_ID}/popup/popup.html`, { timeout: 15000 });
        panels.set(context, page);
    }
    return page;
}

export async function browsecStatus(context) {
    const prefix = `chrome-extension://${BROWSEC_ID}/`;
    const worker = context.serviceWorkers().find(w => w.url().startsWith(prefix)) ||
        await context.waitForEvent('serviceworker', { predicate: w => w.url().startsWith(prefix), timeout: 5000 });
    // Never return the PAC script, proxy credentials, or extension storage.
    return worker.evaluate(() => new Promise(resolve => chrome.proxy.settings.get({ incognito: false }, settings => {
        resolve({ connected: settings.levelOfControl === 'controlled_by_this_extension' &&
            ['fixed_servers', 'pac_script'].includes(settings.value?.mode), mode: settings.value?.mode });
    })));
}

export async function waitForBrowsec(context, timeoutMs = 20000) {
    const page = await openBrowsecPanel(context);
    const until = Date.now() + timeoutMs;
    let clicked = false;
    while (Date.now() < until) {
        if (await page.locator('first-start-agree-terms-conditions').isVisible()) throw new Error('browsec_setup_required');
        if ((await browsecStatus(context).catch(() => null))?.connected) return;
        for (const name of ['first-start-tips-start-vpn', 'first-start-tips-country-select']) {
            const close = page.locator(name + ' .Close');
            if (await close.isVisible()) await close.click({ timeout: 2000 });
        }
        // Only the ordinary VPN switch. First-run consent, login and upgrades require setup.
        const toggle = page.getByRole('button', { name: 'Start VPN', exact: true });
        if (!clicked && await toggle.count()) { await toggle.click({ timeout: 2000 }); clicked = true; }
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('browsec_not_connected');
}

export async function switchBrowsecLocation(context) {
    await waitForBrowsec(context);
    const page = await openBrowsecPanel(context);
    await page.getByRole('button', { name: 'Home', exact: true }).click({ timeout: 3000 });
    await page.getByText('Change', { exact: true }).first().click({ timeout: 5000 });
    const options = page.locator('index-locations-element');
    await options.first().waitFor({ timeout: 10000 });
    // Read only public country metadata from Browsec's location rows. Never select a Premium row.
    const choices = await options.evaluateAll(es => es.map(e => ({ code: e.data?.code, mode: e.mode })));
    const order = ['us', 'sg', 'nl', 'de', 'uk', 'lt', 'lv'];
    const from = choices.find(c => c.mode === 'current')?.code;
    const start = order.indexOf(from);
    const candidates = [...order.slice(start + 1), ...order.slice(0, start + 1)];
    const to = candidates.find(code => choices.some(c => c.code === code && c.mode === 'change'));
    const index = choices.findIndex(c => c.code === to && c.mode === 'change');
    if (!to || index < 0) throw new Error('browsec_switch_failed');
    await options.nth(index).click({ timeout: 5000 });
    await page.locator('.Active_Country_Name').first().waitFor({ timeout: 10000 });
    await waitForBrowsec(context);
    return { from, to };
}
