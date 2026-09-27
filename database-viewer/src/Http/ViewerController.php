<?php

namespace GreyHarbour\DatabaseViewer\Http;

use App\Filament\Server\Resources\Databases\DatabaseResource;
use GreyHarbour\DatabaseViewer\Services\QueryExecutor;
use GreyHarbour\DatabaseViewer\Services\StudioOrigin;
use GreyHarbour\DatabaseViewer\Services\ViewerAccess;
use GreyHarbour\DatabaseViewer\Services\ViewerContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ViewerController
{
    public function __construct(private ViewerAccess $access, private ViewerContext $contexts) {}

    public function show(Request $request, string $server, string $database)
    {
        [$server, $database] = $this->access->resolve($request->user(), $server, $database);
        try {
            $origin = StudioOrigin::validate(config('database-viewer.studio_origin'));
        } catch (\InvalidArgumentException) {
            abort(503, 'Database Viewer configuration is invalid.');
        }
        $channel = $this->contexts->create($request->session(), $request->user()->id, $server->id, $database->id);

        return response()->view('database-viewer::viewer', [
            'channel' => $channel,
            'origin' => $origin,
            'iframeUrl' => $origin.'/embed/mysql?'.http_build_query(['channel' => $channel, 'mode' => 'probe']),
            'queryUrl' => route('database-viewer.query', ['server' => $server->uuid_short, 'database' => $database->id]),
            'backUrl' => DatabaseResource::getUrl('index', panel: 'server', tenant: $server),
            'databaseName' => $database->database,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function query(Request $request, string $server, string $database, QueryExecutor $executor)
    {
        [$server, $database] = $this->access->resolve($request->user(), $server, $database);
        $channel = $request->input('channel');
        abort_unless(is_string($channel) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $channel)
            && $this->contexts->matches($request->session(), $channel, $request->user()->id, $server->id, $database->id), 403);
        $statement = $request->input('statement');
        if (!is_string($statement) || strlen($statement) > 128 || !preg_match('/\A[ \t\r\n]*SELECT[ \t\r\n]+1[ \t\r\n]*;?[ \t\r\n]*\z/iD', $statement)) {
            return response()->json(['error' => 'Query not permitted in MVP mode.'], 422)->header('Cache-Control', 'no-store');
        }

        $metadata = ['user_id' => $request->user()->id, 'server_id' => $server->id, 'database_id' => $database->id, 'operation' => 'query'];
        $start = hrtime(true);
        try {
            $duration = $executor->execute($database);
            if (!is_finite($duration) || $duration < 0) {
                throw new \RuntimeException('Invalid query duration.');
            }
            Log::info('Database Viewer query succeeded', $metadata + ['duration_ms' => $duration]);

            return response()->json(['data' => [
                'headers' => [['name' => '1', 'displayName' => '1', 'originalType' => 'INT', 'type' => 2]],
                'rows' => [(object) ['1' => 1]],
                'stat' => ['rowsAffected' => 0, 'rowsRead' => 1, 'rowsWritten' => null, 'queryDurationMs' => $duration],
            ]])->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            // Do not report the exception: PDO exception messages can contain connection details.
            Log::warning('Database Viewer query failed', $metadata + ['duration_ms' => (hrtime(true) - $start) / 1_000_000]);

            return response()->json(['error' => 'Database query failed.'], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function asset(string $asset)
    {
        abort_unless(in_array($asset, ['bridge.mjs', 'viewer.mjs'], true), 404);

        return response(file_get_contents(__DIR__.'/../../resources/js/'.$asset))
            ->header('Content-Type', 'text/javascript; charset=UTF-8')->header('Cache-Control', 'no-store');
    }
}
