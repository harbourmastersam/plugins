import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as bridgeModule from '../resources/js/bridge.mjs';

const { createBridge } = bridgeModule;

const result = { headers: [{ name: '1', displayName: '1', originalType: 'INT', type: 2 }], rows: [{ '1': 1 }], stat: { rowsAffected: 0, rowsRead: 1, rowsWritten: null, queryDurationMs: 1 } };
function setup(broker = async () => result) {
    const sent = [];
    const frame = { contentWindow: { postMessage: (...args) => sent.push(args) } };
    let calls = 0;
    const bridge = createBridge({ iframe: frame, origin: 'https://studio.greyharbour.net', channel: 'a'.repeat(32), broker: async (...args) => { calls++; return broker(...args); } });
    const event = { origin: 'https://studio.greyharbour.net', source: frame.contentWindow, data: { type: 'query', id: 1, channel: 'a'.repeat(32), statement: 'SELECT 1' } };
    return { bridge, event, frame, sent, calls: () => calls };
}
test('viewer adds the probe mode when a hot-loaded controller omits it', () => {
    assert.equal(typeof bridgeModule.withProbeMode, 'function');
    assert.equal(
        bridgeModule.withProbeMode('https://studio.greyharbour.net/embed/mysql?channel=abc'),
        'https://studio.greyharbour.net/embed/mysql?channel=abc&mode=probe',
    );
});
test('valid query returns exact identity and exact target origin', async () => {
    const s = setup(); await s.bridge.handle(s.event);
    assert.equal(s.calls(), 1);
    assert.deepEqual(s.sent, [[{ type: 'query', id: 1, channel: 'a'.repeat(32), data: result }, 'https://studio.greyharbour.net']]);
});
for (const [name, mutate] of [
    ['origin', e => e.origin = 'https://evil.test'],
    ['window', e => e.source = {}],
    ['channel', e => e.data.channel = 'wrong'],
    ['null', e => e.data = null],
    ['array', e => e.data = []],
    ['unsafe ID', e => e.data.id = Number.MAX_SAFE_INTEGER + 1],
    ['fraction ID', e => e.data.id = 1.5],
    ['statement', e => e.data.statement = {}],
    ['unknown type', e => e.data.type = 'export'],
]) test(`ignores wrong/malformed ${name}`, async () => {
    const s = setup(); mutate(s.event); await s.bridge.handle(s.event);
    assert.equal(s.calls(), 0); assert.equal(s.sent.length, 0);
});
test('arbitrary SQL and transactions settle with explicit errors without fetching', async () => {
    const s = setup(); s.event.data.statement = 'SELECT password FROM users'; await s.bridge.handle(s.event);
    assert.equal(s.sent[0][0].error, 'Query not permitted in MVP mode.');
    s.event.data = { type: 'transaction', id: 2, channel: 'a'.repeat(32), statements: ['SELECT 1'] };
    await s.bridge.handle(s.event);
    assert.equal(s.sent[1][0].type, 'transaction'); assert.equal(s.sent[1][0].error, 'Transactions are not supported in MVP mode.'); assert.equal(s.calls(), 0);
});
test('backend exceptions and malformed results never leak details', async () => {
    for (const broker of [async () => { throw new Error('secret password'); }, async () => ({ password: 'secret' })]) {
        const s = setup(broker); await s.bridge.handle(s.event);
        assert.equal(s.sent[0][0].error, 'Database query failed.'); assert.ok(!JSON.stringify(s.sent).includes('secret'));
    }
});
test('disposal and iframe replacement drop pending replies', async () => {
    for (const dispose of [true, false]) {
        let resolve; const s = setup(() => new Promise(r => resolve = r));
        const pending = s.bridge.handle(s.event);
        if (dispose) s.bridge.dispose(); else s.frame.contentWindow = {};
        resolve(result); await pending; assert.equal(s.sent.length, 0);
    }
});
test('duplicate inflight IDs do not run another request', async () => {
    let resolve; const s = setup(() => new Promise(r => resolve = r));
    const pending = s.bridge.handle(s.event); await s.bridge.handle(s.event);
    assert.equal(s.calls(), 1); resolve(result); await pending;
});
