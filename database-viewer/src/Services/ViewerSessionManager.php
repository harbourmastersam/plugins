<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use GreyHarbour\DatabaseViewer\Exceptions\ViewerSessionException;
use GreyHarbour\DatabaseViewer\Models\ViewerSession;
use GreyHarbour\DatabaseViewer\ValueObjects\ViewerSessionHandle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ViewerSessionManager
{
    public const LIFETIME_MINUTES = 15;

    public const MAX_LIFETIME_MINUTES = 120;

    public const MAX_ACTIVE_PER_USER = 20;

    public function create(User $user, Server $server, Database $database): ViewerSessionHandle
    {
        try {
            $this->pruneBatch();
        } catch (Throwable) {
            Log::warning('Database Viewer historical session pruning failed.');
        }
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($user, $server, $database, $now): ViewerSessionHandle {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $active = ViewerSession::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->count();
            if ($active >= self::MAX_ACTIVE_PER_USER) {
                throw ViewerSessionException::limitReached();
            }
            $channel = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expiresAt = $now->addMinutes(self::LIFETIME_MINUTES);
            ViewerSession::query()->create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'database_id' => $database->id,
                'channel_hash' => hash('sha256', $channel),
                'created_at' => $now,
                'expires_at' => $expiresAt,
                'last_activity_at' => $now,
                'revoked_at' => null,
            ]);

            return new ViewerSessionHandle($channel, $expiresAt, $now->addMinutes(self::MAX_LIFETIME_MINUTES), $now);
        });
    }

    public function validate(string $channel, int $userId, int $serverId, int $databaseId): ViewerSession
    {
        $session = $this->find($channel, $userId, $serverId, $databaseId);
        $now = CarbonImmutable::now();
        $this->assertActive($session, $now);
        $session->forceFill(['last_activity_at' => $now])->save();

        return $session;
    }

    public function extend(string $channel, int $userId, int $serverId, int $databaseId): ViewerSessionHandle
    {
        return DB::transaction(function () use ($channel, $userId, $serverId, $databaseId): ViewerSessionHandle {
            $session = $this->query($channel, $userId, $serverId, $databaseId)->lockForUpdate()->first();
            if (!$session instanceof ViewerSession) {
                throw ViewerSessionException::mismatch();
            }
            $now = CarbonImmutable::now();
            $this->assertActive($session, $now);
            $maxExpiresAt = CarbonImmutable::instance($session->created_at)->addMinutes(self::MAX_LIFETIME_MINUTES);
            $expiresAt = $now->addMinutes(self::LIFETIME_MINUTES);
            if ($expiresAt->greaterThan($maxExpiresAt)) {
                $expiresAt = $maxExpiresAt;
            }
            $session->forceFill(['expires_at' => $expiresAt, 'last_activity_at' => $now])->save();

            return new ViewerSessionHandle($channel, $expiresAt, $maxExpiresAt, $now);
        });
    }

    public function close(string $channel, int $userId, int $serverId, int $databaseId): ViewerSession
    {
        return DB::transaction(function () use ($channel, $userId, $serverId, $databaseId): ViewerSession {
            $session = $this->query($channel, $userId, $serverId, $databaseId)->lockForUpdate()->first();
            if (!$session instanceof ViewerSession) {
                throw ViewerSessionException::mismatch();
            }
            if ($session->revoked_at === null) {
                $session->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
            }

            return $session;
        });
    }

    public function pruneBatch(int $limit = 500): int
    {
        if ($limit < 1) {
            return 0;
        }
        $cutoff = CarbonImmutable::now()->subDay();
        $ids = ViewerSession::query()
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $remaining = $limit - count($ids);
        if ($remaining > 0) {
            $revoked = ViewerSession::query()
                ->whereNotNull('revoked_at')
                ->where('revoked_at', '<=', $cutoff)
                ->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))
                ->orderBy('revoked_at')
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, $revoked);
        }

        return $ids === [] ? 0 : ViewerSession::query()->whereKey($ids)->delete();
    }

    private function find(string $channel, int $userId, int $serverId, int $databaseId): ViewerSession
    {
        $session = $this->query($channel, $userId, $serverId, $databaseId)->first();
        if (!$session instanceof ViewerSession) {
            throw ViewerSessionException::mismatch();
        }

        return $session;
    }

    private function query(string $channel, int $userId, int $serverId, int $databaseId)
    {
        return ViewerSession::query()
            ->where('channel_hash', hash('sha256', $channel))
            ->where('user_id', $userId)
            ->where('server_id', $serverId)
            ->where('database_id', $databaseId);
    }

    private function assertActive(ViewerSession $session, CarbonImmutable $now): void
    {
        if ($session->revoked_at !== null) {
            throw ViewerSessionException::closed();
        }
        if ($now->greaterThanOrEqualTo(CarbonImmutable::instance($session->expires_at))) {
            throw ViewerSessionException::expired();
        }
    }
}
