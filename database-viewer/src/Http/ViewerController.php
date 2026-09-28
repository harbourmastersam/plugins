<?php

namespace GreyHarbour\DatabaseViewer\Http;

use App\Filament\Server\Resources\Databases\DatabaseResource;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Services\AiBroker;
use GreyHarbour\DatabaseViewer\Services\AiLimits;
use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\QueryExecutor;
use GreyHarbour\DatabaseViewer\Services\SchemaBootstrapPolicy;
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
            'iframeUrl' => $origin.'/embed/mysql?'.http_build_query(
                ['channel' => $channel, 'database' => $database->database],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
            'queryUrl' => route('database-viewer.query', ['server' => $server->uuid_short, 'database' => $database->id]),
            'aiUrl' => route('database-viewer.ai', ['server' => $server->uuid_short, 'database' => $database->id]),
            'backUrl' => DatabaseResource::getUrl('index', panel: 'server', tenant: $server),
            'databaseName' => $database->database,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function ai(Request $request, string $server, string $database, AiBroker $broker)
    {
        [$server, $database] = $this->access->resolve($request->user(), $server, $database);
        $rawBody = $request->getContent();
        if (strlen($rawBody) > AiLimits::MAX_REQUEST_BYTES) {
            return response()->json(['error' => 'AI request is too large.'], 413)->header('Cache-Control', 'no-store');
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->aiPolicyError();
        }
        if (! is_array($payload) || array_is_list($payload)
            || ! $this->hasExactKeys($payload, ['type', 'channel', 'messages'])
            || ($payload['type'] ?? null) !== 'ai') {
            return $this->aiPolicyError();
        }

        $channel = $payload['channel'] ?? null;
        abort_unless(is_string($channel) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $channel)
            && $this->contexts->matches($request->session(), $channel, $request->user()->id, $server->id, $database->id), 403);

        $messages = $payload['messages'] ?? null;
        if (! $this->validAiMessages($messages)) {
            return $this->aiPolicyError();
        }

        $start = hrtime(true);
        $metadata = ['user_id' => $request->user()->id, 'server_id' => $server->id, 'database_id' => $database->id, 'operation' => 'ai'];
        try {
            $result = $broker->complete($messages);
            Log::info('Database Viewer AI request succeeded', $metadata + ['duration_ms' => (hrtime(true) - $start) / 1_000_000]);

            return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            Log::warning('Database Viewer AI request failed', $metadata + ['duration_ms' => (hrtime(true) - $start) / 1_000_000]);

            return response()->json(['error' => 'AI request failed.'], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function query(
        Request $request,
        string $server,
        string $database,
        QueryExecutor $executor,
        SchemaBootstrapPolicy $policy,
    ) {
        [$server, $database] = $this->access->resolve($request->user(), $server, $database);
        $rawBody = $request->getContent();
        if (strlen($rawBody) > BrokerLimits::MAX_REQUEST_BYTES) {
            return response()->json(['error' => 'Request is too large.'], 413)->header('Cache-Control', 'no-store');
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->policyError();
        }
        if (! is_array($payload) || array_is_list($payload)) {
            return $this->policyError();
        }

        $channel = $payload['channel'] ?? null;
        abort_unless(is_string($channel) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $channel)
            && $this->contexts->matches($request->session(), $channel, $request->user()->id, $server->id, $database->id), 403);

        $type = $payload['type'] ?? null;
        $operation = null;
        $operations = null;
        if ($type === 'query' && $this->hasExactKeys($payload, ['type', 'channel', 'statement'])) {
            $statement = $payload['statement'];
            if (is_string($statement)) {
                $operation = $policy->classifyQuery($database, $statement);
            }
        } elseif ($type === 'transaction' && $this->hasExactKeys($payload, ['type', 'channel', 'statements'])) {
            $statements = $payload['statements'];
            if (is_array($statements)) {
                $operations = $policy->classifyTransaction($database, $statements);
            }
        }
        if ($operation === null && $operations === null) {
            return $this->policyError();
        }

        $operationClass = $operation?->value ?? 'schema_bootstrap';
        $metadata = ['user_id' => $request->user()->id, 'server_id' => $server->id, 'database_id' => $database->id, 'operation' => $operationClass];
        $start = hrtime(true);
        try {
            if ($operation instanceof AllowedQuery) {
                $result = $executor->execute($database, $operation);
                $resultCount = 1;
            } else {
                $result = $executor->executeBatch($database, $operations);
                $resultCount = count($result);
            }
            $responseBody = json_encode(['data' => $result], JSON_THROW_ON_ERROR);
            if (strlen($responseBody) > BrokerLimits::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('Database response exceeds the permitted size.');
            }
            $duration = (hrtime(true) - $start) / 1_000_000;
            Log::info('Database Viewer query succeeded', $metadata + ['result_count' => $resultCount, 'duration_ms' => $duration]);

            return response($responseBody, 200, ['Content-Type' => 'application/json'])->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            Log::warning('Database Viewer query failed', $metadata + ['result_count' => 0, 'duration_ms' => (hrtime(true) - $start) / 1_000_000]);

            return response()->json(['error' => 'Database query failed.'], 503)->header('Cache-Control', 'no-store');
        }
    }

    private function hasExactKeys(array $payload, array $expected): bool
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    private function policyError()
    {
        return response()->json(['error' => 'Query not permitted.'], 422)->header('Cache-Control', 'no-store');
    }

    private function aiPolicyError()
    {
        return response()->json(['error' => 'AI request not permitted.'], 422)->header('Cache-Control', 'no-store');
    }

    private function validAiMessages(mixed $messages): bool
    {
        if (! is_array($messages) || ! array_is_list($messages)
            || count($messages) < 1 || count($messages) > AiLimits::MAX_MESSAGES) {
            return false;
        }

        $contentBytes = 0;
        foreach ($messages as $message) {
            if (! is_array($message) || array_is_list($message)
                || ! $this->hasExactKeys($message, ['role', 'content'])
                || ! in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || ! is_string($message['content'] ?? null)) {
                return false;
            }
            $contentBytes += strlen($message['content']);
            if ($contentBytes > AiLimits::MAX_CONTENT_BYTES) {
                return false;
            }
        }

        return true;
    }

    public function asset(string $asset)
    {
        abort_unless(in_array($asset, ['bridge.mjs', 'viewer.mjs'], true), 404);

        return response(file_get_contents(__DIR__.'/../../resources/js/'.$asset))
            ->header('Content-Type', 'text/javascript; charset=UTF-8')->header('Cache-Control', 'no-store');
    }
}
