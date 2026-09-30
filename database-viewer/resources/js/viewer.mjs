import { createBridge } from './bridge.mjs';

const exactKeys = (value, expected) => {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
    const actual = Object.keys(value).sort();
    const wanted = [...expected].sort();
    return actual.length === wanted.length && actual.every((key, index) => key === wanted[index]);
};

const LIFECYCLE_ERRORS = new Map([
    ['SESSION_EXPIRED', { error: 'Database Viewer session expired.', terminal: true }],
    ['SESSION_CLOSED', { error: 'Database Viewer session closed.', terminal: true }],
    ['TRANSACTION_NOT_ATOMIC', { error: 'This operation cannot be executed atomically.', terminal: false }],
]);

export function classifyLifecycleEnvelope(envelope) {
    if (!exactKeys(envelope, ['error', 'code']) || typeof envelope.code !== 'string') return null;
    const approved = LIFECYCLE_ERRORS.get(envelope.code);
    if (approved === undefined || envelope.error !== approved.error) return null;
    return { ...approved };
}

function duration(expiresAt, serverNow) {
    const expires = Date.parse(expiresAt);
    const server = Date.parse(serverNow);
    if (!Number.isFinite(expires) || !Number.isFinite(server)) throw new Error('Invalid lifecycle timestamp');
    return Math.max(0, expires - server);
}

export function createLifecycleClock({ expiresAt, serverNow, monotonicNow = () => performance.now() }) {
    let remainingAtAnchor = duration(expiresAt, serverNow);
    let anchor = monotonicNow();
    return {
        remainingMs() {
            return Math.max(0, remainingAtAnchor - Math.max(0, monotonicNow() - anchor));
        },
        reset(next) {
            remainingAtAnchor = duration(next.expiresAt, next.serverNow);
            anchor = monotonicNow();
        },
    };
}

export function formatRemaining(milliseconds) {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

function lifecycleData(envelope) {
    if (!exactKeys(envelope, ['data']) || !exactKeys(envelope.data, ['expiresAt', 'maxExpiresAt', 'serverNow'])) return null;
    const { expiresAt, maxExpiresAt, serverNow } = envelope.data;
    if (![expiresAt, maxExpiresAt, serverNow].every(value => typeof value === 'string' && Number.isFinite(Date.parse(value)))) return null;
    return { expiresAt, maxExpiresAt, serverNow };
}

function closedData(envelope) {
    return exactKeys(envelope, ['data']) && exactKeys(envelope.data, ['closed']) && envelope.data.closed === true;
}

export function initializeViewer({ documentRef = document, windowRef = window, fetchRef = fetch } = {}) {
    const iframe = documentRef.getElementById('database-viewer');
    const status = documentRef.getElementById('viewer-status');
    const countdown = documentRef.getElementById('viewer-countdown');
    const extendButton = documentRef.getElementById('extend-viewer');
    const closeButton = documentRef.getElementById('close-viewer');
    const csrf = documentRef.querySelector('meta[name="csrf-token"]').content;
    const requests = new Set();
    let bridge;
    let unavailable = false;
    let terminalPending = false;
    let clock = null;
    let maxExpiresAt = iframe.dataset.maxExpiresAt;

    if (iframe.dataset.expiresAt && iframe.dataset.serverNow) {
        try {
            clock = createLifecycleClock({ expiresAt: iframe.dataset.expiresAt, serverNow: iframe.dataset.serverNow });
        } catch {
            clock = null;
        }
    }
    const renderCountdown = () => {
        if (countdown && clock) countdown.textContent = formatRemaining(clock.remainingMs());
    };
    renderCountdown();
    const countdownTimer = clock ? setInterval(renderCountdown, 1000) : null;
    countdownTimer?.unref?.();

    const setControlsDisabled = disabled => {
        if (extendButton) extendButton.disabled = disabled;
        if (closeButton) closeButton.disabled = disabled;
    };
    const abortRequests = () => {
        for (const request of requests) request.abort();
    };
    const makeUnavailable = message => {
        if (unavailable) return;
        unavailable = true;
        terminalPending = true;
        bridge?.dispose();
        abortRequests();
        setControlsDisabled(true);
        if (status) status.textContent = message;
    };
    const post = async (url, body, timeoutMs) => {
        const abort = new AbortController();
        requests.add(abort);
        const timer = setTimeout(() => abort.abort(), timeoutMs);
        try {
            const response = await fetchRef(url, {
                method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body),
            });
            return await response.json();
        } finally {
            clearTimeout(timer);
            requests.delete(abort);
        }
    };

    bridge = createBridge({
        iframe, origin: iframe.dataset.origin, channel: iframe.dataset.channel,
        broker: async payload => {
            if (unavailable || terminalPending) throw new Error('Viewer unavailable');
            const endpoint = payload.type === 'ai' ? iframe.dataset.aiUrl : iframe.dataset.queryUrl;
            const envelope = await post(endpoint, payload, payload.type === 'ai' ? 25000 : 35000);
            const lifecycle = classifyLifecycleEnvelope(envelope);
            if (lifecycle !== null) {
                if (lifecycle.terminal) {
                    terminalPending = true;
                    setTimeout(() => makeUnavailable(lifecycle.error), 0);
                }
                return { error: lifecycle.error };
            }
            return envelope;
        },
    });

    const listener = event => { void bridge.handle(event); };
    windowRef.addEventListener('message', listener);
    iframe.addEventListener('load', () => {
        if (!unavailable && status) status.textContent = 'Studio is connected through the Pelican database broker.';
    });

    extendButton?.addEventListener?.('click', async () => {
        if (unavailable || extendButton.disabled) return;
        extendButton.disabled = true;
        if (status) status.textContent = 'Extending viewer session…';
        try {
            const envelope = await post(iframe.dataset.extendUrl, { channel: iframe.dataset.channel }, 15000);
            const lifecycle = classifyLifecycleEnvelope(envelope);
            if (lifecycle?.terminal) {
                makeUnavailable(lifecycle.error);
                return;
            }
            const data = lifecycleData(envelope);
            if (data === null) throw new Error('Invalid lifecycle response');
            if (clock) clock.reset(data);
            else clock = createLifecycleClock(data);
            maxExpiresAt = data.maxExpiresAt;
            renderCountdown();
            if (status) status.textContent = 'Viewer session extended.';
            extendButton.disabled = Date.parse(data.expiresAt) >= Date.parse(maxExpiresAt);
        } catch {
            if (status) status.textContent = 'Unable to extend the viewer session.';
            extendButton.disabled = false;
        }
    });

    closeButton?.addEventListener?.('click', async () => {
        if (unavailable || closeButton.disabled) return;
        closeButton.disabled = true;
        if (status) status.textContent = 'Closing viewer…';
        try {
            const envelope = await post(iframe.dataset.closeUrl, { channel: iframe.dataset.channel }, 15000);
            if (!closedData(envelope)) throw new Error('Invalid close response');
            makeUnavailable('Database Viewer session closed.');
            windowRef.location.assign(iframe.dataset.backUrl);
        } catch {
            if (status) status.textContent = 'Unable to close the viewer.';
            closeButton.disabled = false;
        }
    });

    windowRef.addEventListener('pagehide', () => {
        if (countdownTimer !== null) clearInterval(countdownTimer);
        bridge.dispose();
        windowRef.removeEventListener('message', listener);
        abortRequests();
    }, { once: true });
    windowRef.addEventListener('pageshow', event => { if (event.persisted) windowRef.location.reload(); });
    iframe.src = iframe.dataset.src;

    return { bridge, makeUnavailable };
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') initializeViewer();
