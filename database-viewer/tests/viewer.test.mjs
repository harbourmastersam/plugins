import { test } from 'node:test';
import assert from 'node:assert/strict';

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
        assert.equal(status.textContent, 'Studio is connected through the Pelican database broker.');
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
