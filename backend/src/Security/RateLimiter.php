<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $db) {}

    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        return $this->attempt($key, $limit, $windowSeconds)->allowed;
    }

    public function attempt(string $key, int $limit, int $windowSeconds, int $cleanupProbability = 100): RateLimitResult
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $cleanupProbability = max(0, min(100, $cleanupProbability));

        $now = time();
        $windowStart = gmdate('Y-m-d H:i:s', $now - $windowSeconds);
        $rateKey = hash('sha256', $key);

        return $this->db->transaction(function (Database $db) use ($rateKey, $limit, $windowSeconds, $cleanupProbability, $now, $windowStart): RateLimitResult {
            if ($cleanupProbability >= 100 || ($cleanupProbability > 0 && random_int(1, 100) <= $cleanupProbability)) {
                $db->run('DELETE FROM iam_rate_limits WHERE created_at < :window_start', ['window_start' => $windowStart]);
            }

            $row = $db->one(
                'SELECT COUNT(*) AS attempts, MIN(created_at) AS oldest_hit FROM iam_rate_limits WHERE rate_key = :rate_key AND created_at >= :window_start',
                ['rate_key' => $rateKey, 'window_start' => $windowStart]
            ) ?? [];

            $attempts = (int) ($row['attempts'] ?? 0);
            $oldest = is_string($row['oldest_hit'] ?? null) ? strtotime((string) $row['oldest_hit']) : false;
            $resetAt = ($oldest !== false ? $oldest : $now) + $windowSeconds;

            if ($attempts >= $limit) {
                $retryAfter = max(1, $resetAt - $now);
                return new RateLimitResult(false, $limit, $windowSeconds, 0, $retryAfter, $now + $retryAfter);
            }

            $db->run('INSERT INTO iam_rate_limits(rate_key, created_at) VALUES(:rate_key, :created_at)', [
                'rate_key' => $rateKey,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
            ]);

            return new RateLimitResult(true, $limit, $windowSeconds, max(0, $limit - $attempts - 1), 0, max($now + 1, $resetAt));
        });
    }

    public function clear(string $key): void
    {
        $this->db->run('DELETE FROM iam_rate_limits WHERE rate_key = :rate_key', ['rate_key' => hash('sha256', $key)]);
    }
}
