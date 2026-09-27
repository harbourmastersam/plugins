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
        assert.equal(status.textContent, 'Schema metadata access only. Table rows and writes are unavailable.');
    } finally {
        delete globalThis.document;
        delete globalThis.window;
    }
});
