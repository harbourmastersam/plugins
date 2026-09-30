<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Database Viewer — {{ $databaseName }}</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #09090b;
            color: #e4e4e7;
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { height: 100dvh; margin: 0; display: flex; flex-direction: column; overflow: hidden; background: #09090b; }
        button, a { font: inherit; }
        .viewer-toolbar {
            flex: none; min-height: 56px; padding: 8px 14px; display: flex; align-items: center;
            justify-content: space-between; gap: 14px; border-bottom: 1px solid #27272a; background: #111113;
        }
        .toolbar-side { min-width: 0; display: flex; align-items: center; gap: 10px; }
        .toolbar-left { overflow: hidden; }
        .back-link { color: #a1a1aa; text-decoration: none; white-space: nowrap; }
        .back-link:hover { color: #f4f4f5; }
        .database-name { margin: 0; overflow: hidden; color: #fafafa; font-size: .95rem; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
        .access-badge {
            flex: none; padding: 3px 8px; border: 1px solid #3f3f46; border-radius: 999px;
            color: #d4d4d8; background: #18181b; font-size: .75rem; white-space: nowrap;
        }
        .session-time { color: #d4d4d8; font-size: .82rem; white-space: nowrap; }
        #viewer-countdown, #lifecycle-modal-countdown { font-variant-numeric: tabular-nums; font-weight: 650; }
        .toolbar-button, .dialog-button {
            border: 1px solid #3f3f46; border-radius: 6px; padding: 6px 10px; color: #e4e4e7;
            background: #27272a; cursor: pointer;
        }
        .toolbar-button:hover, .dialog-button:hover { border-color: #52525b; background: #3f3f46; }
        .toolbar-button.danger { color: #fecaca; border-color: #7f1d1d; background: #450a0a; }
        .toolbar-button.danger:hover { background: #7f1d1d; }
        .primary { color: white; border-color: #7c3aed; background: #6d28d9; }
        .primary:hover { background: #7c3aed; }
        button:disabled { cursor: not-allowed; opacity: .5; }
        button:focus-visible, a:focus-visible { outline: 2px solid #a78bfa; outline-offset: 2px; }
        #viewer-status { max-width: 220px; overflow: hidden; color: #fbbf24; font-size: .78rem; text-overflow: ellipsis; white-space: nowrap; }
        #viewer-status:empty { display: none; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .viewer-stage { position: relative; flex: 1; min-height: 0; }
        .viewer-stage iframe { display: block; width: 100%; height: 100%; border: 0; }
        .viewer-stage.is-unavailable iframe { pointer-events: none; filter: brightness(.55); }
        .lifecycle-backdrop {
            position: absolute; z-index: 20; inset: 0; display: grid; place-items: center; padding: 20px;
            background: rgb(9 9 11 / 78%); backdrop-filter: blur(3px);
        }
        .lifecycle-dialog {
            width: min(430px, 100%); padding: 22px; border: 1px solid #3f3f46; border-radius: 10px;
            background: #18181b; box-shadow: 0 24px 80px rgb(0 0 0 / 55%);
        }
        .lifecycle-dialog h2 { margin: 0 0 8px; color: #fafafa; font-size: 1.15rem; }
        .lifecycle-dialog p { margin: 0; color: #a1a1aa; line-height: 1.5; }
        .dialog-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
        @media (max-width: 720px) {
            .viewer-toolbar { align-items: stretch; flex-direction: column; gap: 7px; }
            .toolbar-side { justify-content: space-between; }
            .toolbar-right { justify-content: flex-end; }
            #viewer-status { display: none; }
        }
    </style>
    <script type="module" src="{{ route('database-viewer.asset', ['asset' => 'viewer.mjs']) }}"></script>
</head>
<body>
    <header class="viewer-toolbar">
        <div class="toolbar-side toolbar-left">
            <a class="back-link" href="{{ $backUrl }}" aria-label="Back to databases">← Databases</a>
            <h1 class="database-name">{{ $databaseName }}</h1>
            <span id="sql-access-mode" class="access-badge">{{ $sqlAccessLabel }}</span>
        </div>
        <div class="toolbar-side toolbar-right">
            <span id="viewer-status" role="status" aria-live="polite"></span>
            <span class="session-time"><span aria-hidden="true">⏱</span> <span class="sr-only">Session time remaining </span><span id="viewer-countdown">--:--</span></span>
            <button id="extend-viewer" class="toolbar-button" type="button">Extend</button>
            <button id="close-viewer" class="toolbar-button danger" type="button">Close</button>
        </div>
    </header>
    <main id="viewer-stage" class="viewer-stage">
        <iframe id="database-viewer" title="Database Viewer Studio" sandbox="allow-scripts allow-same-origin"
            referrerpolicy="no-referrer" data-src="{{ $iframeUrl }}" data-origin="{{ $origin }}"
            data-channel="{{ $channel }}" data-query-url="{{ $queryUrl }}" data-ai-url="{{ $aiUrl }}"
            data-extend-url="{{ $extendUrl }}" data-close-url="{{ $closeUrl }}" data-back-url="{{ $backUrl }}"
            data-expires-at="{{ $expiresAt }}" data-max-expires-at="{{ $maxExpiresAt }}"
            data-server-now="{{ $serverNow }}"></iframe>
        <div id="lifecycle-backdrop" class="lifecycle-backdrop" hidden>
            <section id="lifecycle-dialog" class="lifecycle-dialog" role="dialog" aria-modal="true"
                aria-labelledby="lifecycle-heading" aria-describedby="lifecycle-message" tabindex="-1">
                <h2 id="lifecycle-heading">Session expiring soon</h2>
                <p id="lifecycle-message">Your Database Viewer session expires in <span id="lifecycle-modal-countdown">--:--</span>.</p>
                <div id="warning-actions" class="dialog-actions">
                    <button id="warning-dismiss" class="dialog-button" type="button">Not now</button>
                    <button id="warning-extend" class="dialog-button primary" type="button">Extend 15 minutes</button>
                </div>
                <div id="terminal-actions" class="dialog-actions" hidden>
                    <button id="back-to-databases" class="dialog-button" type="button">Back to databases</button>
                    <button id="start-new-session" class="dialog-button primary" type="button">Start new session</button>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
