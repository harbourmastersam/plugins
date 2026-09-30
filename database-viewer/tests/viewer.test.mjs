import { test } from 'node:test';
import assert from 'node:assert/strict';

test('lifecycle countdown uses server duration and monotonic elapsed time', async () => {
    const { createLifecycleClock, formatRemaining } = await import(`../resources/js/viewer.mjs?clock=${Date.now()}`);
    let monotonic = 1000;
    const clock = createLifecycleClock({
        expiresAt: '2026-09-30T12:15:00.000Z',
        serverNow: '2026-09-30T12:00:00.000Z',
        monotonicNow: () => monotonic,
    });

    assert.equal(formatRemaining(clock.remainingMs()), '15:00');
    monotonic += 60_500;
    assert.equal(formatRemaining(clock.remainingMs()), '14:00');
    monotonic += 20 * 60 * 1000;
    assert.equal(formatRemaining(clock.remainingMs()), '00:00');

    clock.reset({
        expiresAt: '2026-09-30T13:00:00.000Z',
        serverNow: '2026-09-30T12:45:00.000Z',
    });
    assert.equal(formatRemaining(clock.remainingMs()), '15:00');
});

test('lifecycle warning fires once per expiry and expiry is immediate', async () => {
    const { createLifecycleController, EXPIRY_WARNING_MS } = await import(`../resources/js/viewer.mjs?lifecycle=${Date.now()}`);
    let monotonic = 0;
    const updates = [];
    const warnings = [];
    let expired = 0;
    const lifecycle = createLifecycleController({
        expiresAt: '2026-09-30T12:15:00.000Z', serverNow: '2026-09-30T12:00:00.000Z',
        monotonicNow: () => monotonic,
        onUpdate: remaining => updates.push(remaining),
        onWarning: remaining => warnings.push(remaining),
        onExpired: () => { expired++; },
    });

    monotonic = 15 * 60 * 1000 - EXPIRY_WARNING_MS - 1;
    lifecycle.evaluate();
    assert.equal(warnings.length, 0);
    monotonic++;
    lifecycle.evaluate();
    lifecycle.evaluate();
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0], EXPIRY_WARNING_MS);

    monotonic = 15 * 60 * 1000;
    lifecycle.evaluate();
    lifecycle.evaluate();
    assert.equal(expired, 1);
    assert.equal(updates.at(-1), 0);
});

test('successful extension creates a new warning period', async () => {
    const { createLifecycleController } = await import(`../resources/js/viewer.mjs?reset-warning=${Date.now()}`);
    let monotonic = 0;
    let warnings = 0;
    const lifecycle = createLifecycleController({
        expiresAt: '2026-09-30T12:02:00.000Z', serverNow: '2026-09-30T12:00:00.000Z',
        monotonicNow: () => monotonic, onUpdate() {}, onWarning: () => { warnings++; }, onExpired() {},
    });
    lifecycle.evaluate();
    lifecycle.evaluate();
    assert.equal(warnings, 1);

    lifecycle.reset({ expiresAt: '2026-09-30T12:17:00.000Z', serverNow: '2026-09-30T12:02:00.000Z' });
    lifecycle.evaluate();
    assert.equal(warnings, 1);
    monotonic += 13 * 60 * 1000;
    lifecycle.evaluate();
    assert.equal(warnings, 2);
});

test('only exact lifecycle and transaction policy envelopes are trusted', async () => {
    const { classifyLifecycleEnvelope } = await import(`../resources/js/viewer.mjs?codes=${Date.now()}`);
    assert.deepEqual(classifyLifecycleEnvelope({
        error: 'Database Viewer session expired.', code: 'SESSION_EXPIRED',
    }), { error: 'Database Viewer session expired.', terminal: true });
    assert.deepEqual(classifyLifecycleEnvelope({
        error: 'Database Viewer session closed.', code: 'SESSION_CLOSED',
    }), { error: 'Database Viewer session closed.', terminal: true });
    assert.deepEqual(classifyLifecycleEnvelope({
        error: 'This operation cannot be executed atomically.', code: 'TRANSACTION_NOT_ATOMIC',
    }), { error: 'This operation cannot be executed atomically.', terminal: false });
    assert.equal(classifyLifecycleEnvelope({ error: 'changed', code: 'SESSION_EXPIRED' }), null);
    assert.equal(classifyLifecycleEnvelope({
        error: 'Database Viewer session expired.', code: 'SESSION_EXPIRED', extra: true,
    }), null);
});

test('viewer loads the controller-scoped Studio URL unchanged', async () => {
    const handlers = {};
    const src = 'https://studio.greyharbour.net/embed/mysql?channel=abc&database=s2_test';
    const iframe = {
        dataset: {
            origin: 'https://studio.greyharbour.net',
            channel: 'a'.repeat(43),
            queryUrl: '/query',
            src,
        },
        contentWindow: { postMessage() {} },
        addEventListener(type, handler) { handlers[`iframe:${type}`] = handler; },
    };
    const status = { textContent: '' };
    globalThis.document = {
        getElementById: id => id === 'database-viewer' ? iframe : status,
        querySelector: () => ({ content: 'csrf-token' }),
    };
    globalThis.window = {
        addEventListener(type, handler) { handlers[`window:${type}`] = handler; },
        removeEventListener() {},
        location: { reload() {} },
    };

    try {
        await import(`../resources/js/viewer.mjs?test=${Date.now()}`);
        assert.equal(iframe.src, src);
        assert.equal(new URL(iframe.src).searchParams.getAll('database').length, 1);
        assert.equal(new URL(iframe.src).searchParams.has('mode'), false);
        handlers['iframe:load']();
        assert.equal(status.textContent, '');
    } finally {
        delete globalThis.document;
        delete globalThis.window;
    }
});

test('viewer sends AI envelopes only to the dedicated authenticated endpoint', async () => {
    const handlers = {};
    const calls = [];
    const delays = [];
    const originalSetTimeout = globalThis.setTimeout;
    const originalClearTimeout = globalThis.clearTimeout;
    const frameWindow = { postMessage() {} };
    const iframe = {
        dataset: {
            origin: 'https://studio.greyharbour.net', channel: 'a'.repeat(43),
            queryUrl: '/query', aiUrl: '/ai', src: 'https://studio.greyharbour.net/embed/mysql?channel=x&database=y',
        },
        contentWindow: frameWindow,
        addEventListener(type, handler) { handlers[`iframe:${type}`] = handler; },
    };
    globalThis.document = {
        getElementById: id => id === 'database-viewer' ? iframe : { textContent: '' },
        querySelector: () => ({ content: 'csrf-token' }),
    };
    globalThis.window = {
        addEventListener(type, handler) { handlers[`window:${type}`] = handler; },
        removeEventListener() {}, location: { reload() {} },
    };
    globalThis.fetch = async (url, options) => {
        calls.push([url, JSON.parse(options.body)]);
        return { ok: true, json: async () => ({ data: { response: '```sql\nSELECT 1\n```' } }) };
    };
    globalThis.setTimeout = (callback, delay) => {
        delays.push(delay);
        return originalSetTimeout(callback, 60_000);
    };
    globalThis.clearTimeout = timer => originalClearTimeout(timer);

    try {
        await import(`../resources/js/viewer.mjs?ai-test=${Date.now()}`);
        handlers['window:message']({
            origin: iframe.dataset.origin, source: frameWindow,
            data: { type: 'ai', id: 1, channel: iframe.dataset.channel, document: 'a'.repeat(32), messages: [{ role: 'user', content: 'test' }] },
        });
        await new Promise(resolve => originalSetTimeout(resolve, 0));
        assert.equal(calls[0][0], '/ai');
        assert.equal(calls[0][1].type, 'ai');
        assert.equal(delays[0], 25_000);
        handlers['window:message']({
            origin: iframe.dataset.origin, source: frameWindow,
            data: { type: 'query', id: 2, channel: iframe.dataset.channel, document: 'a'.repeat(32), statement: 'SELECT 1' },
        });
        await new Promise(resolve => originalSetTimeout(resolve, 0));
        assert.equal(calls[1][0], '/query');
        assert.equal(delays[1], 35_000);
    } finally {
        globalThis.setTimeout = originalSetTimeout;
        globalThis.clearTimeout = originalClearTimeout;
        delete globalThis.fetch;
        delete globalThis.document;
        delete globalThis.window;
    }
});

function studioResult(value = 1) {
    return {
        headers: [{ name: 'value', displayName: 'value', originalType: 'LONG', type: 2 }],
        rows: [{ value }],
        stat: { rowsAffected: 0, rowsRead: 1, rowsWritten: null, queryDurationMs: 1 },
    };
}

function lifecycleHarness(module, responses = []) {
    const handlers = {};
    const calls = [];
    const sent = [];
    const navigations = [];
    let reloads = 0;
    let monotonic = 0;
    let documentRef;
    const element = id => ({
        id, textContent: '', disabled: false, hidden: false,
        attributes: new Map(),
        classList: {
            values: new Set(),
            add(value) { this.values.add(value); },
            remove(value) { this.values.delete(value); },
            contains(value) { return this.values.has(value); },
        },
        addEventListener(type, handler) { handlers[`${id}:${type}`] = handler; },
        setAttribute(name, value) { this.attributes.set(name, value); },
        removeAttribute(name) { this.attributes.delete(name); },
        focus() { documentRef.activeElement = this; },
    });
    const status = element('viewer-status');
    const countdown = element('viewer-countdown');
    const extend = element('extend-viewer');
    const close = element('close-viewer');
    const stage = element('viewer-stage');
    const backdrop = element('lifecycle-backdrop');
    backdrop.hidden = true;
    const dialog = element('lifecycle-dialog');
    const heading = element('lifecycle-heading');
    const message = element('lifecycle-message');
    const modalCountdown = element('lifecycle-modal-countdown');
    const warningActions = element('warning-actions');
    const terminalActions = element('terminal-actions');
    terminalActions.hidden = true;
    const warningDismiss = element('warning-dismiss');
    const warningExtend = element('warning-extend');
    const startNew = element('start-new-session');
    const back = element('back-to-databases');
    const iframe = element('database-viewer');
    iframe.dataset = {
        origin: 'https://studio.greyharbour.net', channel: 'a'.repeat(43),
        queryUrl: '/query', aiUrl: '/ai', extendUrl: '/extend', closeUrl: '/close', backUrl: '/databases',
        expiresAt: '2026-09-30T12:15:00.000Z', maxExpiresAt: '2026-09-30T14:00:00.000Z',
        serverNow: '2026-09-30T12:00:00.000Z', src: 'https://studio.greyharbour.net/embed/mysql?channel=x&database=y',
    };
    iframe.contentWindow = { postMessage: (...args) => sent.push(args) };
    const elements = {
        'database-viewer': iframe, 'viewer-stage': stage, 'viewer-status': status, 'viewer-countdown': countdown,
        'extend-viewer': extend, 'close-viewer': close, 'lifecycle-backdrop': backdrop, 'lifecycle-dialog': dialog,
        'lifecycle-heading': heading, 'lifecycle-message': message, 'lifecycle-modal-countdown': modalCountdown,
        'warning-actions': warningActions, 'terminal-actions': terminalActions, 'warning-dismiss': warningDismiss,
        'warning-extend': warningExtend, 'start-new-session': startNew, 'back-to-databases': back,
    };
    documentRef = {
        hidden: false, activeElement: extend,
        getElementById: id => elements[id],
        querySelector: () => ({ content: 'csrf-token' }),
        addEventListener(type, handler) { handlers[`document:${type}`] = handler; },
        removeEventListener() {},
    };
    const windowRef = {
        addEventListener(type, handler) { handlers[`window:${type}`] = handler; },
        removeEventListener() {},
        location: { reload: () => { reloads++; }, assign: url => navigations.push(url) },
    };
    const fetchRef = async (url, options) => {
        calls.push([url, JSON.parse(options.body), options]);
        const response = responses.shift();
        return { json: async () => await response };
    };
    const viewer = module.initializeViewer({
        documentRef, windowRef, fetchRef, monotonicNow: () => monotonic,
        setIntervalRef(callback) { handlers.interval = callback; return { unref() {} }; },
        clearIntervalRef() {},
    });
    return {
        ...elements, viewer, handlers, calls, sent, navigations, documentRef,
        setMonotonic(value) { monotonic = value; },
        tick() { handlers.interval(); },
        get reloads() { return reloads; },
    };
}

test('Extend sends one exact request and applies authoritative timestamps', async () => {
    const module = await import(`../resources/js/viewer.mjs?extend=${Date.now()}`);
    let resolve;
    const response = new Promise(done => { resolve = done; });
    const h = lifecycleHarness(module, [response]);

    const pending = h.handlers['extend-viewer:click']();
    await h.handlers['extend-viewer:click']();
    assert.deepEqual(h.calls.map(([url, body]) => [url, body]), [['/extend', { channel: 'a'.repeat(43) }]]);
    resolve({ data: {
        expiresAt: '2026-09-30T12:20:00.000Z', maxExpiresAt: '2026-09-30T14:00:00.000Z', serverNow: '2026-09-30T12:05:00.000Z',
    } });
    await pending;
    assert.equal(h['viewer-countdown'].textContent, '15:00');
    assert.equal(h['viewer-status'].textContent, 'Session extended');
    assert.equal(h['extend-viewer'].disabled, false);
});

test('Close disposes only after a successful exact response and navigates back', async () => {
    const module = await import(`../resources/js/viewer.mjs?close=${Date.now()}`);
    const h = lifecycleHarness(module, [{ data: { closed: true } }]);
    await h.handlers['close-viewer:click']();

    assert.deepEqual(h.calls.map(([url, body]) => [url, body]), [['/close', { channel: 'a'.repeat(43) }]]);
    assert.deepEqual(h.navigations, ['/databases']);
    assert.equal(h['extend-viewer'].disabled, true);
    assert.equal(h['close-viewer'].disabled, true);
});

test('transaction policy rejection is non-terminal and a later query succeeds', async () => {
    const module = await import(`../resources/js/viewer.mjs?transaction=${Date.now()}`);
    const h = lifecycleHarness(module, [
        { error: 'This operation cannot be executed atomically.', code: 'TRANSACTION_NOT_ATOMIC' },
        { data: studioResult(2) },
    ]);
    const base = { channel: 'a'.repeat(43), document: 'b'.repeat(32) };
    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { ...base, type: 'transaction', id: 1, statements: ['CREATE TABLE x (id INT)'] } });
    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { ...base, type: 'query', id: 2, statement: 'SELECT 2' } });

    assert.equal(h.sent[0][0].error, 'This operation cannot be executed atomically.');
    assert.equal(h.sent[1][0].data.rows[0].value, 2);
    assert.equal(h['extend-viewer'].disabled, false);
});

test('terminal session code replies once, disables the viewer, and stops forwarding', async () => {
    const module = await import(`../resources/js/viewer.mjs?terminal=${Date.now()}`);
    const h = lifecycleHarness(module, [
        { error: 'Database Viewer session expired.', code: 'SESSION_EXPIRED' },
        { data: studioResult(2) },
    ]);
    const event = { origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { channel: 'a'.repeat(43), document: 'b'.repeat(32), type: 'query', id: 1, statement: 'SELECT 1' } };
    await h.viewer.bridge.handle(event);
    await new Promise(resolve => setTimeout(resolve, 0));
    await h.viewer.bridge.handle({ ...event, data: { ...event.data, id: 2 } });

    assert.equal(h.sent[0][0].error, 'Database Viewer session expired.');
    assert.equal(h.calls.length, 1);
    assert.equal(h['viewer-status'].textContent, 'Database Viewer session expired.');
    assert.equal(h['lifecycle-heading'].textContent, 'Session expired');
    assert.equal(h['viewer-stage'].classList.contains('is-unavailable'), true);
    assert.equal(h['extend-viewer'].disabled, true);
    assert.equal(h['close-viewer'].disabled, true);
});

test('unknown coded envelopes fail generically without disposing the viewer', async () => {
    const module = await import(`../resources/js/viewer.mjs?unknown=${Date.now()}`);
    const h = lifecycleHarness(module, [
        { error: 'Database Viewer session expired.', code: 'SESSION_EXPIRED', extra: true },
        { data: studioResult(3) },
    ]);
    const base = { channel: 'a'.repeat(43), document: 'c'.repeat(32), type: 'query' };
    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { ...base, id: 1, statement: 'SELECT 1' } });
    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { ...base, id: 2, statement: 'SELECT 3' } });

    assert.equal(h.sent[0][0].error, 'Database query failed.');
    assert.equal(h.sent[1][0].data.rows[0].value, 3);
    assert.equal(h['extend-viewer'].disabled, false);
});

test('lifecycle control failures recover without navigation or disposal', async () => {
    const module = await import(`../resources/js/viewer.mjs?recovery=${Date.now()}`);
    const h = lifecycleHarness(module, [{ error: 'nope' }, { data: { closed: false } }, { data: studioResult(4) }]);

    await h.handlers['extend-viewer:click']();
    assert.equal(h['extend-viewer'].disabled, false);
    assert.equal(h['viewer-status'].textContent, 'Unable to extend session');
    await h.handlers['close-viewer:click']();
    assert.equal(h['close-viewer'].disabled, false);
    assert.deepEqual(h.navigations, []);

    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { channel: 'a'.repeat(43), document: 'd'.repeat(32), type: 'query', id: 1, statement: 'SELECT 4' } });
    assert.equal(h.sent[0][0].data.rows[0].value, 4);
});

test('warning opens once at two minutes and Not now restores focus without extending', async () => {
    const module = await import(`../resources/js/viewer.mjs?warning-ui=${Date.now()}`);
    const h = lifecycleHarness(module, [{ data: {
        expiresAt: '2026-09-30T12:28:30.000Z', maxExpiresAt: '2026-09-30T14:00:00.000Z', serverNow: '2026-09-30T12:13:30.000Z',
    } }]);
    h.setMonotonic(12 * 60 * 1000 + 59 * 1000);
    h.tick();
    assert.equal(h['lifecycle-backdrop'].hidden, true);

    h.setMonotonic(13 * 60 * 1000);
    h.tick();
    assert.equal(h['lifecycle-backdrop'].hidden, false);
    assert.equal(h['lifecycle-heading'].textContent, 'Session expiring soon');
    assert.equal(h['lifecycle-modal-countdown'].textContent, '02:00');
    assert.equal(h.documentRef.activeElement, h['warning-extend']);

    h.setMonotonic(13 * 60 * 1000 + 30 * 1000);
    h.tick();
    assert.equal(h['lifecycle-modal-countdown'].textContent, '01:30');

    h.handlers['warning-dismiss:click']();
    assert.equal(h['lifecycle-backdrop'].hidden, true);
    assert.equal(h.calls.length, 0);
    assert.equal(h.documentRef.activeElement, h['extend-viewer']);
    assert.equal(h['viewer-countdown'].textContent, '01:30');
    assert.equal(h['lifecycle-backdrop'].hidden, true);
    await h.handlers['extend-viewer:click']();
    assert.equal(h.calls[0][0], '/extend');
    assert.equal(h['viewer-countdown'].textContent, '15:00');
});

test('Escape dismisses only the optional warning and restores focus', async () => {
    const module = await import(`../resources/js/viewer.mjs?warning-escape=${Date.now()}`);
    const h = lifecycleHarness(module);
    h.setMonotonic(13 * 60 * 1000);
    h.tick();
    let prevented = false;
    h.handlers['document:keydown']({ key: 'Escape', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(h['lifecycle-backdrop'].hidden, true);
    assert.equal(h.documentRef.activeElement, h['extend-viewer']);
});

test('warning Extend closes the modal and permits warning for the new expiry', async () => {
    const module = await import(`../resources/js/viewer.mjs?warning-extend=${Date.now()}`);
    const h = lifecycleHarness(module, [{ data: {
        expiresAt: '2026-09-30T12:28:00.000Z', maxExpiresAt: '2026-09-30T14:00:00.000Z', serverNow: '2026-09-30T12:13:00.000Z',
    } }]);
    h.setMonotonic(13 * 60 * 1000);
    h.tick();
    await h.handlers['warning-extend:click']();
    assert.equal(h['lifecycle-backdrop'].hidden, true);
    assert.equal(h['viewer-countdown'].textContent, '15:00');

    h.setMonotonic(26 * 60 * 1000);
    h.tick();
    assert.equal(h['lifecycle-backdrop'].hidden, false);
    assert.equal(h['lifecycle-modal-countdown'].textContent, '02:00');
});

test('local expiry blocks Studio immediately and exposes only navigation actions', async () => {
    const module = await import(`../resources/js/viewer.mjs?local-expiry=${Date.now()}`);
    const h = lifecycleHarness(module, [{ data: studioResult(1) }]);
    h.setMonotonic(15 * 60 * 1000);
    h.tick();

    assert.equal(h['viewer-stage'].classList.contains('is-unavailable'), true);
    assert.equal(h['database-viewer'].attributes.has('inert'), true);
    assert.equal(h['lifecycle-backdrop'].hidden, false);
    assert.equal(h['lifecycle-heading'].textContent, 'Session expired');
    assert.equal(h['warning-actions'].hidden, true);
    assert.equal(h['terminal-actions'].hidden, false);
    assert.equal(h['extend-viewer'].disabled, true);
    assert.equal(h['close-viewer'].disabled, true);

    h.handlers['document:keydown']({ key: 'Escape', preventDefault() {} });
    assert.equal(h['lifecycle-backdrop'].hidden, false);
    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { channel: 'a'.repeat(43), document: 'e'.repeat(32), type: 'query', id: 1, statement: 'SELECT 1' } });
    assert.equal(h.calls.length, 0);
    h.handlers['start-new-session:click']();
    assert.equal(h.reloads, 1);
    h.handlers['back-to-databases:click']();
    assert.deepEqual(h.navigations, ['/databases']);
});

test('local expiry aborts an outstanding broker fetch and suppresses its reply', async () => {
    const module = await import(`../resources/js/viewer.mjs?abort-expiry=${Date.now()}`);
    let resolve;
    const response = new Promise(done => { resolve = done; });
    const h = lifecycleHarness(module, [response]);
    const pending = h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { channel: 'a'.repeat(43), document: 'f'.repeat(32), type: 'query', id: 1, statement: 'SELECT 1' } });
    await Promise.resolve();
    h.setMonotonic(15 * 60 * 1000);
    h.tick();
    assert.equal(h.calls[0][2].signal.aborted, true);
    resolve({ data: studioResult(1) });
    await pending;
    assert.equal(h.sent.length, 0);
});

test('server SESSION_CLOSED uses the closed terminal overlay', async () => {
    const module = await import(`../resources/js/viewer.mjs?server-closed=${Date.now()}`);
    const h = lifecycleHarness(module, [{ error: 'Database Viewer session closed.', code: 'SESSION_CLOSED' }]);
    await h.viewer.bridge.handle({ origin: h['database-viewer'].dataset.origin, source: h['database-viewer'].contentWindow,
        data: { channel: 'a'.repeat(43), document: '1'.repeat(32), type: 'query', id: 1, statement: 'SELECT 1' } });
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(h['lifecycle-heading'].textContent, 'Viewer closed');
    assert.equal(h['lifecycle-backdrop'].hidden, false);
    assert.equal(h['viewer-stage'].classList.contains('is-unavailable'), true);
});

test('visibility, focus, and pageshow immediately reevaluate suspended lifecycle time', async () => {
    const module = await import(`../resources/js/viewer.mjs?resume=${Date.now()}`);
    const warning = lifecycleHarness(module);
    warning.setMonotonic(13 * 60 * 1000);
    warning.documentRef.hidden = false;
    warning.handlers['document:visibilitychange']();
    assert.equal(warning['lifecycle-backdrop'].hidden, false);

    const expiredOnFocus = lifecycleHarness(module);
    expiredOnFocus.setMonotonic(15 * 60 * 1000);
    expiredOnFocus.handlers['window:focus']();
    assert.equal(expiredOnFocus['lifecycle-heading'].textContent, 'Session expired');

    const expiredOnShow = lifecycleHarness(module);
    expiredOnShow.setMonotonic(15 * 60 * 1000);
    expiredOnShow.handlers['window:pageshow']({ persisted: true });
    assert.equal(expiredOnShow['lifecycle-heading'].textContent, 'Session expired');
    assert.equal(expiredOnShow.reloads, 0);
});
