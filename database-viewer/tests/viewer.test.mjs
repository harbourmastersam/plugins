import { test } from 'node:test';
import assert from 'node:assert/strict';

test('viewer loads Studio in probe mode when the controller URL omits it', async () => {
    const iframe = {
        dataset: {
            origin: 'https://studio.greyharbour.net',
            channel: 'a'.repeat(32),
            queryUrl: '/query',
            src: 'https://studio.greyharbour.net/embed/mysql?channel=abc',
        },
        contentWindow: { postMessage() {} },
        addEventListener() {},
    };
    const status = { textContent: '' };
    globalThis.document = {
        getElementById: id => id === 'database-viewer' ? iframe : status,
        querySelector: () => ({ content: 'csrf-token' }),
    };
    globalThis.window = {
        addEventListener() {},
        removeEventListener() {},
        location: { reload() {} },
    };

    try {
        await import(`../resources/js/viewer.mjs?test=${Date.now()}`);
        assert.equal(
            iframe.src,
            'https://studio.greyharbour.net/embed/mysql?channel=abc&mode=probe',
        );
    } finally {
        delete globalThis.document;
        delete globalThis.window;
    }
});
