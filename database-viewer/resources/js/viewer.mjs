import { createBridge } from './bridge.mjs';

const iframe = document.getElementById('database-viewer');
const status = document.getElementById('viewer-status');
const requests = new Set();
const bridge = createBridge({
    iframe, origin: iframe.dataset.origin, channel: iframe.dataset.channel,
    broker: async payload => {
        const abort = new AbortController();
        requests.add(abort);
        const timer = setTimeout(() => abort.abort(), 8000);
        try {
            const response = await fetch(iframe.dataset.queryUrl, {
                method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(payload),
            });
            if (!response.ok) throw new Error('Query failed');
            return (await response.json()).data;
        } finally {
            clearTimeout(timer); requests.delete(abort);
        }
    },
});
const listener = event => { void bridge.handle(event); };
window.addEventListener('message', listener);
let loaded = false;
iframe.addEventListener('load', () => {
    if (loaded) { bridge.reset(); for (const request of requests) request.abort(); }
    loaded = true;
    status.textContent = 'Schema metadata access only. Table rows and writes are unavailable.';
});
window.addEventListener('pagehide', () => {
    bridge.dispose(); window.removeEventListener('message', listener);
    for (const request of requests) request.abort();
}, { once: true });
window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
iframe.src = iframe.dataset.src;
