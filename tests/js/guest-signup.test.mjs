import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { guestSignup } from '../../resources/js/guest-signup.js';

for (const [name, file] of [['watchPlayer', 'watch'], ['verticalPlayer', 'vertical-player']]) {
    function setup() {
        const calls = [];
        const context = vm.createContext({
            nxEpPicker: () => ({}), nxGuestSignup: guestSignup, nxEngagementWatch: () => ({}),
            clearTimeout() {}, clearInterval() {}, setTimeout() {},
            window: { nxAttachVideo: () => calls.push('attach') },
        });
        const source = readFileSync(new URL(`../../resources/views/frontend/${file}.blade.php`, import.meta.url), 'utf8');
        vm.runInContext(source.match(/<script>([\s\S]*?)<\/script>/)[1], context);
        const episodes = [1, 2, 3, 4, 5].map(n => ({ n, id: n, url: n <= 3 ? `ep${n}.mp4` : null,
            signupUrl: n > 3 ? `/register?next=%2Fwatch%2F1%2F${n}` : null }));
        const player = context[name]({ episodes, start: 2 });
        const dialog = { open: false, showModal() { this.open = true; calls.push('dialog'); }, close() { this.open = false; } };
        player.$refs = { signupDialog: dialog, video: {
            pause() { calls.push('pause'); }, removeAttribute() {}, load() {},
            _nxHls: { destroy() { calls.push('destroy'); } },
        } };
        player.$nextTick = fn => fn();
        player.stopPoll = () => calls.push('stopPoll');
        player.attach = url => calls.push(url);
        player.tryResolve = async () => { calls.push('resolve'); return null; };
        return { player, calls, dialog };
    }

    test(`${name}: finishing preview episode 3 opens signup without requesting episode 4`, () => {
        const { player, calls, dialog } = setup();
        player.onEnded();
        assert.equal(player.index, 3);
        assert.equal(player.signupOpen, true);
        assert.equal(dialog.open, true);
        assert.equal(player.signupUrl, '/register?next=%2Fwatch%2F1%2F4');
        assert.ok(calls.includes('pause'));
        assert.ok(calls.includes('destroy'));
        assert.ok(!calls.includes('resolve'));
        assert.ok(!calls.includes('attach'));
    });

    test(`${name}: picking a locked episode works again after dismissing, and free episodes still play`, () => {
        const { player, calls, dialog } = setup();
        player.go(4);
        assert.equal(dialog.open, true);
        player.closeSignup();
        assert.equal(dialog.open, false);
        assert.equal(player.epMenu, true);
        player.go(4);
        assert.equal(dialog.open, true);
        player.closeSignup();
        player.go(1);
        assert.ok(calls.includes('ep2.mp4'));
        assert.ok(!calls.includes('resolve'));
    });

    test(`${name}: a direct start on a locked episode opens signup`, async () => {
        const { player, dialog } = setup();
        player.index = 3;
        await player.load();
        assert.equal(dialog.open, true);
    });

    test(`${name}: a quota changed since page load stops polling and opens signup`, () => {
        const { player, dialog } = setup();
        player.waitForSource(player.episodes[2], { error: 'signup_required', signupUrl: '/register?next=%2Fwatch%2F1%2F3' });
        assert.equal(dialog.open, true);
        assert.equal(player.signupUrl, '/register?next=%2Fwatch%2F1%2F3');
    });
}
