<?php
declare(strict_types=1);
require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}

use App\Application\Iam\IamAdminRepository;
use App\Core\Database;
use App\Repository\AuthRepository;
use App\Security\TotpService;

$h = new TestHarness();
$secret = TotpService::generateSecret();
$code = TotpService::currentCode($secret, 60);
$h->assertTrue((bool) preg_match('/^[A-Z2-7]{32}$/', $secret), 'secret is robust Base32');
$h->assertSame('otpauth://totp/', substr(TotpService::otpauthUri('DEC CMS', 'admin@example.test', $secret), 0, 15), 'otpauth URI has totp scheme');
$h->assertTrue(TotpService::verifyCode($secret, $code, 1, 60), 'current TOTP code accepted');
$h->assertTrue(!TotpService::verifyCode($secret, '000000', 0, 60), 'wrong TOTP code refused');
$h->assertTrue(!TotpService::verifyCode($secret, '', 1, 60), 'empty TOTP code refused');

[$dir, $path] = test_temp_db(base_path('database/iam.sql'));
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $hash = password_hash('correct-password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES(1,'totp@example.test','totp@example.test',?,1,'password')")->execute([$hash]);

    $iam = new IamAdminRepository($db);
    $setup = $iam->prepareTotp(1, 'DEC CMS');
    $preparedSecret = (string) $setup['secret'];
    $preparedCode = TotpService::currentCode($preparedSecret);
    $h->expectException(fn() => $iam->enableTotp(1, $preparedSecret, '000000', true, 'test-key'), InvalidArgumentException::class, 'invalid confirmation code rejected');
    $enabled = $iam->enableTotp(1, $preparedSecret, $preparedCode, true, 'test-key');
    $h->assertSame('totp', $enabled['user']['login_mode'], 'totp mode enabled after valid code');
    $stored = (string) $pdo->query("SELECT totp_secret_protected FROM iam_users WHERE id=1")->fetchColumn();
    $h->assertTrue($stored !== '' && $stored !== $preparedSecret && !str_contains($stored, $preparedSecret), 'TOTP secret not stored in clear text');
    $h->assertTrue(!array_key_exists('secret', $enabled), 'activation response does not return secret');

    $auth = new AuthRepository($db);
    putenv('CMS_TOTP_KEY=test-key');
    $h->assertSame('totp_required', $auth->attemptWithTotp('totp@example.test', 'correct-password', '')['status'], 'totp login requires code');
    $h->assertSame('invalid_totp', $auth->attemptWithTotp('totp@example.test', 'correct-password', '000000')['status'], 'totp login rejects wrong code');
    $h->assertSame('ok', $auth->attemptWithTotp('totp@example.test', 'correct-password', TotpService::currentCode($preparedSecret))['status'], 'totp login accepts current code');
} finally {
    putenv('CMS_TOTP_KEY');
    unset($pdo, $auth, $iam);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('UNIT TOTP service'));
