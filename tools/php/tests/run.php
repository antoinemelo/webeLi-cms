<?php
declare(strict_types=1);

$tests = [
    'unit/bootstrap_autoload_test.php',
    'unit/health_check_test.php',
    'unit/editorial_status_test.php',
    'integration/auth_permissions_test.php',
    'integration/webhook_repository_test.php',
    'integration/multisite_locale_test.php',
];

/**
 * Execute one PHP test with progressive output and a hard per-test timeout.
 * This prevents the outer Python suite from waiting silently for 60 seconds.
 */
function runTest(string $relativePath, int $timeoutSeconds = 15): int
{
    $absolutePath = __DIR__ . '/' . $relativePath;
    $command = [PHP_BINARY, $absolutePath];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 3));
    if (!is_resource($process)) {
        fwrite(STDERR, "[FAILED] {$relativePath}: unable to start PHP process\n");
        return 1;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $startedAt = microtime(true);
    $timedOut = false;
    $lastStatus = null;

    echo "[RUN] {$relativePath}\n";
    while (true) {
        foreach ([1 => STDOUT, 2 => STDERR] as $index => $target) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false && $chunk !== '') {
                fwrite($target, $chunk);
            }
        }

        $status = proc_get_status($process);
        $lastStatus = $status;
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 15);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(20000);
    }

    foreach ([1 => STDOUT, 2 => STDERR] as $index => $target) {
        stream_set_blocking($pipes[$index], true);
        $chunk = stream_get_contents($pipes[$index]);
        if ($chunk !== false && $chunk !== '') {
            fwrite($target, $chunk);
        }
        fclose($pipes[$index]);
    }

    $closeCode = proc_close($process);
    if ($timedOut) {
        fwrite(STDERR, "[FAILED] {$relativePath}: timeout after {$timeoutSeconds}s\n");
        return 124;
    }

    // proc_close() may return -1 after proc_get_status() has observed process
    // termination. Preserve the reliable exit code reported by get_status().
    $exitCode = is_array($lastStatus) ? (int) ($lastStatus['exitcode'] ?? -1) : -1;
    if ($exitCode < 0) {
        $exitCode = $closeCode;
    }
    return $exitCode < 0 ? 1 : $exitCode;
}

$failed = 0;
foreach ($tests as $test) {
    if (runTest($test) !== 0) {
        $failed++;
    }
}

echo $failed === 0
    ? "PHP functional suites: OK\n"
    : "PHP functional suites: {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
