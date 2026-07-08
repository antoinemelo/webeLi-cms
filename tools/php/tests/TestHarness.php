<?php
declare(strict_types=1);

final class TestHarness
{
    private int $assertions = 0;
    private array $failures = [];

    public function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            $this->failures[] = $message;
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->failures[] = $message
                . ' expected=' . var_export($expected, true)
                . ' actual=' . var_export($actual, true);
        }
    }

    public function expectException(callable $callback, string $class, string $message): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            $this->failures[] = $message . ' wrong exception=' . $e::class;
            return;
        }
        $this->failures[] = $message . ' no exception';
    }

    public function finish(string $suite): int
    {
        if ($this->failures !== []) {
            fwrite(
                STDERR,
                "[FAILED] {$suite} ({$this->assertions} assertions)\n - "
                . implode("\n - ", $this->failures)
                . "\n"
            );
            return 1;
        }
        echo "[OK] {$suite} ({$this->assertions} assertions)\n";
        return 0;
    }
}

/**
 * Build a temporary SQLite database and close the bootstrap connection before
 * the CMS Database class opens it. Keeping both connections alive while the
 * CMS enables WAL can wait on a SQLite journal lock until the test timeout.
 *
 * @return array{0:string,1:string}
 */
function test_temp_db(string $schemaFile): array
{
    $dir = sys_get_temp_dir() . '/amcms-test-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create temporary test directory.');
    }

    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        throw new RuntimeException('Unable to read schema: ' . $schemaFile);
    }

    $path = $dir . '/test.sqlite';
    $bootstrap = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $bootstrap->exec('PRAGMA synchronous = OFF; PRAGMA temp_store = MEMORY;');
    $bootstrap->beginTransaction();
    try {
        $bootstrap->exec($schema);
        $bootstrap->commit();
    } catch (Throwable $e) {
        if ($bootstrap->inTransaction()) {
            $bootstrap->rollBack();
        }
        throw $e;
    }
    $bootstrap = null;

    return [$dir, $path];
}

/**
 * Build a temporary SQLite database through the CMS Database wrapper from the
 * start. This avoids journal-mode waits when large module schemas are loaded.
 *
 * @return array{0:string,1:string,2:object}
 */
function test_temp_cms_db(string $schemaFile): array
{
    $dir = sys_get_temp_dir() . '/amcms-test-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create temporary test directory.');
    }

    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        throw new RuntimeException('Unable to read schema: ' . $schemaFile);
    }

    $path = $dir . '/test.sqlite';
    $database = new \App\Core\Database($path);
    $pdo = $database->pdo();
    $pdo->exec('PRAGMA synchronous = OFF; PRAGMA temp_store = MEMORY;');
    $pdo->beginTransaction();
    try {
        $pdo->exec($schema);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [$dir, $path, $database];
}

function test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
