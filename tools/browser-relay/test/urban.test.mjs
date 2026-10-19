import test from 'node:test';
import assert from 'node:assert/strict';
import { urbanIsConnected, pickLocation } from '../urban.mjs';

test('relay requires an actual Urban-controlled proxy, not merely an installed extension', () => {
    assert.equal(urbanIsConnected({ levelOfControl: 'controlled_by_this_extension', value: { mode: 'fixed_servers' } }), true);
    assert.equal(urbanIsConnected({ levelOfControl: 'controlled_by_this_extension', value: { mode: 'pac_script' } }), true);
    for (const settings of [null, {}, { levelOfControl: 'controllable_by_this_extension', value: { mode: 'fixed_servers' } },
        { levelOfControl: 'controlled_by_this_extension', value: { mode: 'direct' } },
        { levelOfControl: 'controlled_by_this_extension', value: { mode: 'system' } }]) {
        assert.equal(urbanIsConnected(settings), false);
    }
});

test('a blocked location is replaced by the next preferred one that is not resting', () => {
    const listed = ['Germany', 'United Kingdom (UK)', 'United States (USA)', 'Algeria', 'Japan'];
    assert.equal(pickLocation(listed, { current: 'United States (USA)' }), 'Germany');
    assert.equal(pickLocation(listed, { current: 'Germany', cooling: new Set(['United States (USA)']) }), 'United Kingdom (UK)');
    assert.equal(pickLocation(listed, { current: 'Japan', preferred: ['Japan', 'Algeria'] }), 'Algeria');
    // Not in the preferred list: any other listed location still beats staying blocked.
    assert.equal(pickLocation(['Algeria', 'Belgium'], { current: 'Algeria', preferred: ['Germany'] }), 'Belgium');
    assert.equal(pickLocation(['Germany'], { current: 'Germany' }), null);
    assert.equal(pickLocation(listed, { cooling: new Set(listed) }), null);
});
