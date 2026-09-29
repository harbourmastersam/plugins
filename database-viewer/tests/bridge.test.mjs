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

function setup(broker = async () => ({ data: result() })) {
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
        data: { type: 'query', id: 1, channel, document: 'a'.repeat(32), statement: 'SELECT DATABASE() AS db' },
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
    const s = setup(async () => ({ data: backend }));

    await s.bridge.handle(s.event);

    assert.deepEqual(s.calls, [{ type: 'query', statement: 'SELECT DATABASE() AS db', channel }]);
    assert.deepEqual(s.sent, [[{
        type: 'query', id: 1, channel, document: 'a'.repeat(32),
        data: result('s2_test'),
    }, origin]]);
    assert.ok(!JSON.stringify(s.sent).includes('secret'));
});

test('valid six-result transaction preserves request and result order', async () => {
    const statements = Array.from({ length: 6 }, (_, index) => `SELECT schema_${index}`);
    const results = Array.from({ length: 6 }, (_, index) => result(index));
    const s = setup(async () => ({ data: results }));
    s.event.data = { type: 'transaction', id: 2, channel, document: 'a'.repeat(32), statements };

    await s.bridge.handle(s.event);

    assert.deepEqual(s.calls, [{ type: 'transaction', statements, channel }]);
    assert.deepEqual(s.sent, [[{ type: 'transaction', id: 2, channel, document: 'a'.repeat(32), data: results }, origin]]);
});

test('valid AI request forwards bounded messages and returns only the response string', async () => {
    const messages = [
        { role: 'system', content: 'Only return SQL' },
        { role: 'user', content: 'Count users' },
    ];
    const s = setup(async () => ({ data: { response: '```sql\nSELECT COUNT(*) FROM users\n```' } }));
    s.event.data = { type: 'ai', id: 3, channel, document: 'a'.repeat(32), messages };

    await s.bridge.handle(s.event);

    assert.deepEqual(s.calls, [{ type: 'ai', messages, channel }]);
    assert.deepEqual(s.sent, [[{
        type: 'ai', id: 3, channel, document: 'a'.repeat(32),
        data: { response: '```sql\nSELECT COUNT(*) FROM users\n```' },
    }, origin]]);
});

test('rejects malformed and oversized AI envelopes before fetching', async () => {
    const valid = { role: 'user', content: 'Count users' };
    const cases = [
        'not-an-array',
        [],
        Array(13).fill(valid),
        [{ role: 'tool', content: 'bad role' }],
        [{ role: 'user', content: 1 }],
        [{ role: 'user', content: 'ok', extra: true }],
        [{ role: 'user', content: 'x'.repeat(24 * 1024 + 1) }],
    ];
    for (const messages of cases) {
        const s = setup();
        s.event.data = { type: 'ai', id: 3, channel, document: 'a'.repeat(32), messages };
        await s.bridge.handle(s.event);
        assert.equal(s.calls.length, 0);
        assert.equal(s.sent.length, 0);
    }
});

test('malformed AI broker results fail generically', async () => {
    for (const data of [null, {}, { response: 1 }, { response: 'ok', extra: true }, { response: 'x'.repeat(16 * 1024 + 1) }]) {
        const s = setup(async () => ({ data }));
        s.event.data = { type: 'ai', id: 3, channel, document: 'a'.repeat(32), messages: [{ role: 'user', content: 'Count users' }] };
        await s.bridge.handle(s.event);
        assert.equal(s.sent[0][0].error, 'AI request failed.');
        assert.equal('data' in s.sent[0][0], false);
    }
});

test('transaction response length mismatch fails generically', async () => {
    const s = setup(async () => ({ data: Array.from({ length: 5 }, (_, index) => result(index)) }));
    s.event.data = { type: 'transaction', id: 2, channel, document: 'a'.repeat(32), statements: Array(6).fill('SELECT schema') };

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

test('accepts statement and batch boundaries before fetching', async () => {
    const exactStatement = 'x'.repeat(64 * 1024);
    const query = setup(async () => ({ data: result() }));
    query.event.data.statement = exactStatement;
    await query.bridge.handle(query.event);
    assert.equal(query.calls.length, 1);

    const oversizedQuery = setup();
    oversizedQuery.event.data.statement = `${exactStatement}x`;
    await oversizedQuery.bridge.handle(oversizedQuery.event);
    assert.equal(oversizedQuery.calls.length, 0);
    assert.equal(oversizedQuery.sent.length, 0);

    for (const count of [1, 6, 100]) {
        const results = Array.from({ length: count }, (_, index) => result(index));
        const s = setup(async () => ({ data: results }));
        s.event.data = { type: 'transaction', id: count, channel, document: 'a'.repeat(32), statements: Array(count).fill('SELECT 1') };
        await s.bridge.handle(s.event);
        assert.equal(s.calls.length, 1);
        assert.equal(s.sent[0][0].data.length, count);
    }
});

test('rejects malformed transaction shape and byte bounds before fetching', async () => {
    const sparse = Array(2);
    sparse[1] = 'SELECT 1';
    const cases = [
        ['not an array', 'bad'],
        ['zero statements', []],
        ['101 statements', Array(101).fill('SELECT schema')],
        ['sparse statements', sparse],
        ['nested statement', [{}]],
        ['oversized statement', ['x'.repeat(64 * 1024 + 1)]],
    ];
    for (const [, statements] of cases) {
        const s = setup();
        s.event.data = { type: 'transaction', id: 2, channel, document: 'a'.repeat(32), statements };
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
        const s = setup(async () => ({ data: invalid }));
        await s.bridge.handle(s.event);
        assert.equal(s.sent[0][0].error, 'Database query failed.');
        assert.equal('data' in s.sent[0][0], false);
    }
});

test('bounded safe broker diagnostics are copied without browser SQL classification', async () => {
    const s = setup(async () => ({ error: 'SQLSTATE 42000 / MariaDB 1064: Syntax error' }));
    s.event.data.statement = 'SELECT password FROM users';

    await s.bridge.handle(s.event);

    assert.equal(s.calls.length, 1);
    assert.equal(s.calls[0].statement, 'SELECT password FROM users');
    assert.equal(s.sent[0][0].error, 'SQLSTATE 42000 / MariaDB 1064: Syntax error');
});

test('malformed, oversized, or control-bearing broker errors fail generically', async () => {
    for (const envelope of [
        { error: '' },
        { error: 'x'.repeat(2049) },
        { error: 'line one\nline two' },
        { error: 'failed', extra: true },
        { data: result(), error: 'failed' },
    ]) {
        const s = setup(async () => envelope);
        await s.bridge.handle(s.event);
        assert.equal(s.sent[0][0].error, 'Database query failed.');
    }
});

test('reset, disposal, and iframe replacement drop pending transaction replies', async () => {
    for (const action of ['reset', 'dispose', 'replace']) {
        let resolve;
        const s = setup(() => new Promise(done => { resolve = done; }));
        s.event.data = { type: 'transaction', id: 2, channel, document: 'a'.repeat(32), statements: Array(6).fill('SELECT schema') };
        const pending = s.bridge.handle(s.event);
        if (action === 'replace') s.frame.contentWindow = {};
        else s.bridge[action]();
        resolve({ data: Array.from({ length: 6 }, (_, index) => result(index)) });
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
    resolve({ data: result() });
    await pending;
});

test('stable iframe window keeps reloaded documents and colliding IDs isolated', async () => {
    const resolvers = [];
    const s = setup(() => new Promise(resolve => resolvers.push(resolve)));
    const first = {
        ...s.event,
        data: { ...s.event.data, document: 'a'.repeat(32) },
    };
    const second = {
        ...s.event,
        data: { ...s.event.data, document: 'b'.repeat(32) },
    };

    const oldPending = s.bridge.handle(first);
    const newPending = s.bridge.handle(second);
    assert.equal(s.calls.length, 2);

    resolvers[1]({ data: result('new') });
    await newPending;
    resolvers[0]({ data: result('old') });
    await oldPending;

    assert.equal(s.sent[0][0].document, 'b'.repeat(32));
    assert.equal(s.sent[0][0].data.rows[0].value, 'new');
    assert.equal(s.sent[1][0].document, 'a'.repeat(32));
    assert.equal(s.sent[1][0].data.rows[0].value, 'old');
});
