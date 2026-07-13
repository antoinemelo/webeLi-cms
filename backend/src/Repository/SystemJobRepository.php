<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;

final class SystemJobRepository
{
    public function __construct(private readonly Database $db) {}

    public function touch(string $jobKey, string $status, string $message = ''): void
    {
        $existing = $this->db->one('SELECT id FROM system_jobs WHERE job_key = :job_key LIMIT 1', ['job_key' => $jobKey]);
        if ($existing) {
            $this->db->run('UPDATE system_jobs SET last_run_at = :last_run_at, last_heartbeat_at = :last_heartbeat_at, last_status = :last_status, last_message = :last_message, updated_at = :updated_at WHERE id = :id', [
                'last_run_at' => now_utc(),
                'last_heartbeat_at' => now_utc(),
                'last_status' => $status,
                'last_message' => mb_substr($message, 0, 500),
                'updated_at' => now_utc(),
                'id' => $existing['id'],
            ]);
            return;
        }
        $this->db->run('INSERT INTO system_jobs(job_key, last_run_at, last_heartbeat_at, last_status, last_message, updated_at) VALUES(:job_key, :last_run_at, :last_heartbeat_at, :last_status, :last_message, :updated_at)', [
            'job_key' => $jobKey,
            'last_run_at' => now_utc(),
            'last_heartbeat_at' => now_utc(),
            'last_status' => $status,
            'last_message' => mb_substr($message, 0, 500),
            'updated_at' => now_utc(),
        ]);
    }
}
