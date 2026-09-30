<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Database Viewer — {{ $databaseName }}</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; }
        header { padding: 1rem; border-bottom: 1px solid #8885; }
        h1 { font-size: 1.2rem; margin: .6rem 0; }
        p { margin: .4rem 0; font-size: .9rem; }
        .viewer-controls { display: flex; align-items: center; flex-wrap: wrap; gap: .6rem; margin: .65rem 0; }
        .viewer-controls button { padding: .35rem .7rem; }
        #viewer-countdown { font-variant-numeric: tabular-nums; font-weight: 600; }
        iframe { display: block; width: 100%; height: calc(100dvh - 205px); min-height: 420px; border: 0; }
    </style>
    <script type="module" src="{{ route('database-viewer.asset', ['asset' => 'viewer.mjs']) }}"></script>
</head>
<body>
    <header>
        <a href="{{ $backUrl }}">Back to databases</a>
        <h1>Database Viewer — {{ $databaseName }}</h1>
        <p id="sql-access-mode">{{ $sqlAccessLabel }}</p>
        <div class="viewer-controls" aria-label="Temporary viewer session">
            <span>Session: <span id="viewer-countdown">--:--</span></span>
            <button id="extend-viewer" type="button">Extend 15 minutes</button>
            <button id="close-viewer" type="button">Close viewer</button>
        </div>
        <p>SQL is executed through Pelican using the access level above. Permissions are checked again for every request.</p>
        <p id="viewer-status" role="status">Loading Studio…</p>
    </header>
    <iframe id="database-viewer" title="Database Viewer Studio" sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer" data-src="{{ $iframeUrl }}" data-origin="{{ $origin }}"
        data-channel="{{ $channel }}" data-query-url="{{ $queryUrl }}" data-ai-url="{{ $aiUrl }}"
        data-extend-url="{{ $extendUrl }}" data-close-url="{{ $closeUrl }}" data-back-url="{{ $backUrl }}"
        data-expires-at="{{ $expiresAt }}" data-max-expires-at="{{ $maxExpiresAt }}"
        data-server-now="{{ $serverNow }}"></iframe>
</body>
</html>
