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

export const EXPIRY_WARNING_MS = 2 * 60 * 1000;

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

export function createLifecycleController({
    expiresAt,
    serverNow,
    monotonicNow = () => performance.now(),
    onUpdate,
    onWarning,
    onExpired,
}) {
    const clock = createLifecycleClock({ expiresAt, serverNow, monotonicNow });
    let expiryIdentity = expiresAt;
    let warningShown = false;
    let expired = false;

    const controller = {
        evaluate() {
            const remaining = clock.remainingMs();
            onUpdate(remaining);
            if (remaining <= 0) {
                if (!expired) {
                    expired = true;
                    onExpired();
                }
            } else if (remaining <= EXPIRY_WARNING_MS && !warningShown) {
                warningShown = true;
                onWarning(remaining);
            }
            return remaining;
        },
        reset(next) {
            clock.reset(next);
            if (next.expiresAt !== expiryIdentity) {
                expiryIdentity = next.expiresAt;
                warningShown = false;
            }
            expired = false;
            return controller.evaluate();
        },
        remainingMs() {
            return clock.remainingMs();
        },
    };

    return controller;
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

export function initializeViewer({
    documentRef = document,
    windowRef = window,
    fetchRef = fetch,
    monotonicNow = () => performance.now(),
    setIntervalRef = setInterval,
    clearIntervalRef = clearInterval,
    setTimeoutRef = setTimeout,
    clearTimeoutRef = clearTimeout,
} = {}) {
    const iframe = documentRef.getElementById('database-viewer');
    const stage = documentRef.getElementById('viewer-stage');
    const status = documentRef.getElementById('viewer-status');
    const countdown = documentRef.getElementById('viewer-countdown');
    const extendButton = documentRef.getElementById('extend-viewer');
    const closeButton = documentRef.getElementById('close-viewer');
    const backdrop = documentRef.getElementById('lifecycle-backdrop');
    const dialog = documentRef.getElementById('lifecycle-dialog');
    const heading = documentRef.getElementById('lifecycle-heading');
    const message = documentRef.getElementById('lifecycle-message');
    const modalCountdown = documentRef.getElementById('lifecycle-modal-countdown');
    const warningActions = documentRef.getElementById('warning-actions');
    const terminalActions = documentRef.getElementById('terminal-actions');
    const warningDismiss = documentRef.getElementById('warning-dismiss');
    const warningExtend = documentRef.getElementById('warning-extend');
    const startNewSession = documentRef.getElementById('start-new-session');
    const backToDatabases = documentRef.getElementById('back-to-databases');
    const csrf = documentRef.querySelector('meta[name="csrf-token"]').content;
    const requests = new Set();
    let bridge;
    let lifecycle = null;
    let unavailable = false;
    let terminalPending = false;
    let extendInFlight = false;
    let modalMode = null;
    let restoreFocus = null;
    let maxExpiresAt = iframe.dataset.maxExpiresAt;

    const setControlsDisabled = disabled => {
        if (extendButton) extendButton.disabled = disabled;
        if (closeButton) closeButton.disabled = disabled;
        if (warningExtend) warningExtend.disabled = disabled;
    };
    const setStatus = value => { if (status) status.textContent = value; };
    const abortRequests = () => {
        for (const request of requests) request.abort();
    };

    const closeWarning = () => {
        if (modalMode !== 'warning') return;
        if (backdrop) backdrop.hidden = true;
        modalMode = null;
        const target = restoreFocus;
        restoreFocus = null;
        target?.focus?.();
    };
    const showWarning = remaining => {
        if (unavailable) return;
        restoreFocus = documentRef.activeElement ?? extendButton;
        modalMode = 'warning';
        if (heading) heading.textContent = 'Session expiring soon';
        if (modalCountdown) modalCountdown.textContent = formatRemaining(remaining);
        if (warningActions) warningActions.hidden = false;
        if (terminalActions) terminalActions.hidden = true;
        if (backdrop) backdrop.hidden = false;
        warningExtend?.focus?.();
    };
    const showTerminal = kind => {
        restoreFocus = null;
        modalMode = 'terminal';
        if (heading) heading.textContent = kind === 'closed' ? 'Viewer closed' : 'Session expired';
        if (message) message.textContent = kind === 'closed'
            ? 'This Database Viewer session has been closed.'
            : 'This Database Viewer session has expired.';
        if (warningActions) warningActions.hidden = true;
        if (terminalActions) terminalActions.hidden = false;
        if (backdrop) backdrop.hidden = false;
        startNewSession?.focus?.();
    };
    const makeUnavailable = (kind, statusMessage) => {
        if (unavailable) return;
        unavailable = true;
        terminalPending = true;
        bridge?.dispose();
        abortRequests();
        setControlsDisabled(true);
        stage?.classList?.add('is-unavailable');
        iframe.setAttribute?.('inert', '');
        setStatus(statusMessage);
        showTerminal(kind);
    };
    const post = async (url, body, timeoutMs) => {
        const abort = new AbortController();
        requests.add(abort);
        const timer = setTimeoutRef(() => abort.abort(), timeoutMs);
        try {
            const response = await fetchRef(url, {
                method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body),
            });
            return await response.json();
        } finally {
            clearTimeoutRef(timer);
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
                    const kind = envelope.code === 'SESSION_CLOSED' ? 'closed' : 'expired';
                    setTimeoutRef(() => makeUnavailable(kind, lifecycle.error), 0);
                }
                return { error: lifecycle.error };
            }
            return envelope;
        },
    });

    const listener = event => { void bridge.handle(event); };
    windowRef.addEventListener('message', listener);
    iframe.addEventListener('load', () => {
        if (!unavailable) setStatus('');
    });

    const extendSession = async () => {
        if (unavailable || extendInFlight || extendButton?.disabled) return;
        extendInFlight = true;
        if (extendButton) extendButton.disabled = true;
        if (warningExtend) warningExtend.disabled = true;
        setStatus('Extending session…');
        try {
            const envelope = await post(iframe.dataset.extendUrl, { channel: iframe.dataset.channel }, 15000);
            const lifecycleError = classifyLifecycleEnvelope(envelope);
            if (lifecycleError?.terminal) {
                const kind = envelope.code === 'SESSION_CLOSED' ? 'closed' : 'expired';
                makeUnavailable(kind, lifecycleError.error);
                return;
            }
            const data = lifecycleData(envelope);
            if (data === null) throw new Error('Invalid lifecycle response');
            closeWarning();
            maxExpiresAt = data.maxExpiresAt;
            lifecycle?.reset(data);
            setStatus('Session extended');
            const atMaximum = Date.parse(data.expiresAt) >= Date.parse(maxExpiresAt);
            if (extendButton) extendButton.disabled = atMaximum;
            if (warningExtend) warningExtend.disabled = atMaximum;
        } catch {
            setStatus('Unable to extend session');
            if (!unavailable) {
                if (extendButton) extendButton.disabled = false;
                if (warningExtend) warningExtend.disabled = false;
            }
        } finally {
            extendInFlight = false;
        }
    };
    extendButton?.addEventListener?.('click', extendSession);
    warningExtend?.addEventListener?.('click', extendSession);
    warningDismiss?.addEventListener?.('click', closeWarning);

    closeButton?.addEventListener?.('click', async () => {
        if (unavailable || closeButton.disabled) return;
        closeButton.disabled = true;
        setStatus('Closing viewer…');
        try {
            const envelope = await post(iframe.dataset.closeUrl, { channel: iframe.dataset.channel }, 15000);
            if (!closedData(envelope)) throw new Error('Invalid close response');
            makeUnavailable('closed', 'Database Viewer session closed.');
            windowRef.location.assign(iframe.dataset.backUrl);
        } catch {
            setStatus('Unable to close viewer');
            closeButton.disabled = false;
        }
    });

    startNewSession?.addEventListener?.('click', () => windowRef.location.reload());
    backToDatabases?.addEventListener?.('click', () => windowRef.location.assign(iframe.dataset.backUrl));
    const keydown = event => {
        if (event.key === 'Escape' && modalMode === 'warning') {
            event.preventDefault?.();
            closeWarning();
        }
    };
    documentRef.addEventListener?.('keydown', keydown);

    if (iframe.dataset.expiresAt && iframe.dataset.serverNow) {
        try {
            lifecycle = createLifecycleController({
                expiresAt: iframe.dataset.expiresAt,
                serverNow: iframe.dataset.serverNow,
                monotonicNow,
                onUpdate(remaining) {
                    const formatted = formatRemaining(remaining);
                    if (countdown) countdown.textContent = formatted;
                    if (modalMode === 'warning' && modalCountdown) modalCountdown.textContent = formatted;
                },
                onWarning: showWarning,
                onExpired: () => makeUnavailable('expired', 'Database Viewer session expired.'),
            });
            lifecycle.evaluate();
        } catch {
            lifecycle = null;
        }
    }
    const evaluateLifecycle = () => lifecycle?.evaluate();
    const countdownTimer = lifecycle ? setIntervalRef(evaluateLifecycle, 1000) : null;
    countdownTimer?.unref?.();
    const visibilityChange = () => { if (!documentRef.hidden) evaluateLifecycle(); };
    documentRef.addEventListener?.('visibilitychange', visibilityChange);
    windowRef.addEventListener('focus', evaluateLifecycle);

    windowRef.addEventListener('pagehide', () => {
        if (countdownTimer !== null) clearIntervalRef(countdownTimer);
        bridge.dispose();
        windowRef.removeEventListener('message', listener);
        windowRef.removeEventListener('focus', evaluateLifecycle);
        documentRef.removeEventListener?.('keydown', keydown);
        documentRef.removeEventListener?.('visibilitychange', visibilityChange);
        abortRequests();
    }, { once: true });
    windowRef.addEventListener('pageshow', evaluateLifecycle);
    iframe.src = iframe.dataset.src;

    return { bridge, evaluateLifecycle };
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') initializeViewer();
