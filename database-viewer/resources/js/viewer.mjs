import { createBridge } from './bridge.mjs';

const iframe = document.getElementById('database-viewer');
const status = document.getElementById('viewer-status');
const requests = new Set();
const bridge = createBridge({
    iframe, origin: iframe.dataset.origin, channel: iframe.dataset.channel,
    broker: async payload => {
        const abort = new AbortController();
        requests.add(abort);
        const timeoutMs = payload.type === 'ai' ? 25000 : 35000;
        const timer = setTimeout(() => abort.abort(), timeoutMs);
        try {
            const endpoint = payload.type === 'ai' ? iframe.dataset.aiUrl : iframe.dataset.queryUrl;
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(payload),
            });
            return await response.json();
        } finally {
            clearTimeout(timer); requests.delete(abort);
        }
    },
});
const listener = event => { void bridge.handle(event); };
window.addEventListener('message', listener);
iframe.addEventListener('load', () => {
    status.textContent = 'Schema metadata access only. Table rows and writes are unavailable.';
});
window.addEventListener('pagehide', () => {
    bridge.dispose(); window.removeEventListener('message', listener);
    for (const request of requests) request.abort();
}, { once: true });
window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
iframe.src = iframe.dataset.src;
