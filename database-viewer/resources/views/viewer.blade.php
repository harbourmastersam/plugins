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
        iframe { display: block; width: 100%; height: calc(100dvh - 155px); min-height: 420px; border: 0; }
    </style>
    <script type="module" src="{{ route('database-viewer.asset', ['asset' => 'viewer.mjs']) }}"></script>
</head>
<body>
    <header>
        <a href="{{ $backUrl }}">Back to databases</a>
        <h1>Database Viewer — {{ $databaseName }}</h1>
        <p>MVP transport supports only <code>SELECT 1</code>. Schema discovery and transactions are unavailable.</p>
        <p>Use “Run SELECT 1” in Studio below to test the selected database connection.</p>
        <p id="viewer-status" role="status">Loading Studio…</p>
    </header>
    <iframe id="database-viewer" title="Database Viewer Studio" sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer" data-src="{{ $iframeUrl }}" data-origin="{{ $origin }}"
        data-channel="{{ $channel }}" data-query-url="{{ $queryUrl }}"></iframe>
</body>
</html>
