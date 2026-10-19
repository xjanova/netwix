import test from 'node:test';
import assert from 'node:assert/strict';
import { urbanIsConnected } from '../urban.mjs';

test('relay requires an actual Urban-controlled proxy, not merely an installed extension', () => {
    assert.equal(urbanIsConnected({ levelOfControl: 'controlled_by_this_extension', value: { mode: 'fixed_servers' } }), true);
    assert.equal(urbanIsConnected({ levelOfControl: 'controlled_by_this_extension', value: { mode: 'pac_script' } }), true);
    for (const settings of [null, {}, { levelOfControl: 'controllable_by_this_extension', value: { mode: 'fixed_servers' } },
        { levelOfControl: 'controlled_by_this_extension', value: { mode: 'direct' } },
        { levelOfControl: 'controlled_by_this_extension', value: { mode: 'system' } }]) {
        assert.equal(urbanIsConnected(settings), false);
    }
});
