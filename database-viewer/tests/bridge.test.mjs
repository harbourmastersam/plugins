import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as bridgeModule from '../resources/js/bridge.mjs';

const { createBridge } = bridgeModule;
const channel = 'a'.repeat(43);
const origin = 'https://studio.greyharbour.net';

function result(value = 1) {
    return {
        headers: [{ name: 'value', displayName: 'value', originalType: 'LONG', type: 2 }],
        rows: [{ value }],
        stat: { rowsAffected: 0, rowsRead: 1, rowsWritten: null, queryDurationMs: 1 },
    };
}

function setup(broker = async () => result()) {
    const sent = [];
    const calls = [];
    const frame = { contentWindow: { postMessage: (...args) => sent.push(args) } };
    const bridge = createBridge({
        iframe: frame,
        origin,
        channel,
        broker: async payload => { calls.push(payload); return broker(payload); },
    });
    const event = {
        origin,
        source: frame.contentWindow,
        data: { type: 'query', id: 1, channel, statement: 'SELECT DATABASE() AS db' },
    };
    return { bridge, event, frame, sent, calls };
}

test('probe URL mutation helper is removed', () => {
    assert.equal('withProbeMode' in bridgeModule, false);
});

test('valid query forwards explicit type and returns a copied result', async () => {
    const backend = result('s2_test');
    backend.secret = 'not-forwarded';
    backend.headers[0].secret = 'not-forwarded';
    const s = setup(async () => backend);

    await s.bridge.handle(s.event);

    assert.deepEqual(s.calls, [{ type: 'query', statement: 'SELECT DATABASE() AS db', channel }]);
    assert.deepEqual(s.sent, [[{
        type: 'query', id: 1, channel,
        data: result('s2_test'),
    }, origin]]);
    assert.ok(!JSON.stringify(s.sent).includes('secret'));
});

test('valid six-result transaction preserves request and result order', async () => {
    const statements = Array.from({ length: 6 }, (_, index) => `SELECT schema_${index}`);
    const results = Array.from({ length: 6 }, (_, index) => result(index));
    const s = setup(async () => results);
    s.event.data = { type: 'transaction', id: 2, channel, statements };

    await s.bridge.handle(s.event);

    assert.deepEqual(s.calls, [{ type: 'transaction', statements, channel }]);
    assert.deepEqual(s.sent, [[{ type: 'transaction', id: 2, channel, data: results }, origin]]);
});

test('transaction response length mismatch fails generically', async () => {
    const s = setup(async () => Array.from({ length: 5 }, (_, index) => result(index)));
    s.event.data = { type: 'transaction', id: 2, channel, statements: Array(6).fill('SELECT schema') };

    await s.bridge.handle(s.event);

    assert.equal(s.sent[0][0].error, 'Database query failed.');
    assert.equal('data' in s.sent[0][0], false);
});

for (const [name, mutate] of [
    ['origin', event => event.origin = 'https://evil.test'],
    ['window', event => event.source = {}],
    ['channel', event => event.data.channel = 'wrong'],
    ['null payload', event => event.data = null],
    ['array payload', event => event.data = []],
    ['unsafe ID', event => event.data.id = Number.MAX_SAFE_INTEGER + 1],
    ['fraction ID', event => event.data.id = 1.5],
    ['non-string statement', event => event.data.statement = {}],
    ['unknown type', event => event.data.type = 'export'],
    ['mixed shape', event => event.data.statements = []],
]) test(`ignores malformed request ${name}`, async () => {
    const s = setup();
    mutate(s.event);
    await s.bridge.handle(s.event);
    assert.equal(s.calls.length, 0);
    assert.equal(s.sent.length, 0);
});

test('rejects malformed transaction shape and byte bounds before fetching', async () => {
    const cases = [
        ['not an array', 'bad'],
        ['five statements', Array(5).fill('SELECT schema')],
        ['nested statement', ['a', 'b', 'c', 'd', 'e', {}]],
        ['oversized statement', ['a', 'b', 'c', 'd', 'e', 'x'.repeat(2049)]],
    ];
    for (const [, statements] of cases) {
        const s = setup();
        s.event.data = { type: 'transaction', id: 2, channel, statements };
        await s.bridge.handle(s.event);
        assert.equal(s.calls.length, 0);
        assert.equal(s.sent.length, 0);
    }
});

test('malformed result structures and unsafe row values fail generically', async () => {
    const invalidResults = [
        null,
        { ...result(), headers: 'bad' },
        { ...result(), headers: [{ name: 'value', displayName: 'value', originalType: {}, type: 2 }] },
        { ...result(), rows: [['not-an-object']] },
        { ...result(), rows: [{ value: { nested: true } }] },
        { ...result(), rows: [{ value: [1] }] },
        { ...result(), rows: [{ value: Number.POSITIVE_INFINITY }] },
        { ...result(), stat: { ...result().stat, queryDurationMs: -1 } },
    ];
    for (const invalid of invalidResults) {
        const s = setup(async () => invalid);
        await s.bridge.handle(s.event);
        assert.equal(s.sent[0][0].error, 'Database query failed.');
        assert.equal('data' in s.sent[0][0], false);
    }
});

test('backend policy rejection is returned generically without browser SQL classification', async () => {
    const s = setup(async () => { throw new Error('Query not permitted. secret detail'); });
    s.event.data.statement = 'SELECT password FROM users';

    await s.bridge.handle(s.event);

    assert.equal(s.calls.length, 1);
    assert.equal(s.calls[0].statement, 'SELECT password FROM users');
    assert.equal(s.sent[0][0].error, 'Database query failed.');
    assert.ok(!JSON.stringify(s.sent).includes('secret'));
});

test('reset, disposal, and iframe replacement drop pending transaction replies', async () => {
    for (const action of ['reset', 'dispose', 'replace']) {
        let resolve;
        const s = setup(() => new Promise(done => { resolve = done; }));
        s.event.data = { type: 'transaction', id: 2, channel, statements: Array(6).fill('SELECT schema') };
        const pending = s.bridge.handle(s.event);
        if (action === 'replace') s.frame.contentWindow = {};
        else s.bridge[action]();
        resolve(Array.from({ length: 6 }, (_, index) => result(index)));
        await pending;
        assert.equal(s.sent.length, 0);
    }
});

test('duplicate inflight IDs do not run another request', async () => {
    let resolve;
    const s = setup(() => new Promise(done => { resolve = done; }));
    const pending = s.bridge.handle(s.event);
    await s.bridge.handle(s.event);
    assert.equal(s.calls.length, 1);
    resolve(result());
    await pending;
});
