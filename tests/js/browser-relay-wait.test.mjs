import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

for (const [name, file] of [['watchPlayer', 'watch'], ['verticalPlayer', 'vertical-player']]) {
    function setup() {
        let now = 1000;
        let stopped = 0;
        const source = readFileSync(new URL(`../../resources/views/frontend/${file}.blade.php`, import.meta.url), 'utf8');
        const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
        const context = vm.createContext({ nxEpPicker: () => ({}), Date: { now: () => now }, clearTimeout() {} });
        vm.runInContext(script, context);
        const player = context[name]({ episodes: [], start: 0 });
        player.stopPoll = () => { stopped++; };
        player.resume = () => {};
        return { player, advance: ms => { now += ms; }, stopped: () => stopped };
    }

    test(`${name}: relay polling stops after two minutes, including a stalled request`, () => {
        const state = setup();
        assert.equal(state.player.relayTimedOut({ relay: { state: 'pending' } }), false);
        state.advance(119999);
        assert.equal(state.player.relayTimedOut(null), false);
        state.advance(1);
        assert.equal(state.player.relayTimedOut(null), true);
        assert.equal(state.player.clientBlocked, true);
        assert.equal(state.stopped(), 1);
    });

    test(`${name}: a cooldown stops immediately and unrelated sources are unaffected`, () => {
        const state = setup();
        assert.equal(state.player.relayTimedOut({ ready: false }), false);
        state.advance(1000000);
        assert.equal(state.player.relayTimedOut(null), false);
        assert.equal(state.player.relayTimedOut({ relay: { state: 'retry_later' } }), true);
        assert.equal(state.stopped(), 1);
    });
}
