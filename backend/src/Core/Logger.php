<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public function __construct(private readonly string $path) {}

    public function info(string $message, array $context = []): void { $this->write('INFO', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->write('WARNING', $message, $context); }
    public function error(string $message, array $context = []): void { $this->write('ERROR', $message, $context); }

    private function write(string $level, string $message, array $context): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = json_encode([
            'ts' => now_utc(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $fh = fopen($this->path, 'ab');
        if ($fh === false) {
            return;
        }
        try {
            if (flock($fh, LOCK_EX)) {
                fwrite($fh, $line);
                fflush($fh);
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }
}
