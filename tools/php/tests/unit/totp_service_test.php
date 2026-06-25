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
$sampleRecoveryCodes = TotpService::recoveryCodes();
$h->assertSame(8, count($sampleRecoveryCodes), 'recovery code count');
$h->assertTrue((bool) preg_match('/^[A-F0-9]{5}-[A-F0-9]{5}$/', $sampleRecoveryCodes[0]), 'recovery code format');
$sampleRecoveryHashes = TotpService::hashRecoveryCodes($sampleRecoveryCodes, 'test-key');
$h->assertTrue(!str_contains($sampleRecoveryHashes, $sampleRecoveryCodes[0]), 'recovery codes are not stored in clear text');
$spacedRecovery = substr($sampleRecoveryCodes[0], 0, 5) . ' ' . substr($sampleRecoveryCodes[0], 6);
$recoveryResult = TotpService::verifyRecoveryCode($spacedRecovery, $sampleRecoveryHashes, 'test-key');
$h->assertTrue($recoveryResult['ok'], 'recovery code accepts spaces and hyphens');
$h->assertTrue(!TotpService::verifyRecoveryCode($sampleRecoveryCodes[0], $recoveryResult['hashes'], 'test-key')['ok'], 'recovery code is one-use');

$nativeIamSchema = (string) file_get_contents(base_path('database/iam.sql'));
$h->assertTrue(str_contains($nativeIamSchema, 'totp_recovery_codes_json TEXT'), 'native IAM schema contains recovery code storage');
$iamRepositorySource = (string) file_get_contents(base_path('backend/src/Application/Iam/IamAdminRepository.php'));
$authRepositorySource = (string) file_get_contents(base_path('backend/src/Repository/AuthRepository.php'));
$h->assertTrue(!str_contains($iamRepositorySource, "return ['user' => \$this->findUser(\$userId) ?? [], 'recovery_codes' => []];"), 'TOTP repository does not return empty recovery codes for activation/regeneration');
$h->assertTrue(str_contains($authRepositorySource, 'verifyRecoveryCode') && str_contains($authRepositorySource, 'totp_recovery_codes_json'), 'auth repository verifies persisted recovery code hashes');
foreach ([
    base_path('docs/reference/contracts/admin-api-v1/admin.iam.users.login_mode.totp.confirm.v1.json'),
    base_path('docs/reference/contracts/admin-api-v1/admin.iam.users.totp.enable.v1.json'),
    base_path('docs/reference/contracts/admin-api-v1/admin.iam.users.totp.recovery.v1.json'),
    base_path('docs/user-guide/getting-started/account-security-2fa.md'),
    base_path('docs/evaluation/P1_VALIDATION_20260625.md'),
] as $docPath) {
    $doc = strtolower((string) file_get_contents($docPath));
    $h->assertTrue(!str_contains($doc, 'empty array; p1-02 debt') && !str_contains($doc, 'recovery_codes: []'), basename($docPath) . ' does not document empty recovery codes debt');
}

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
    $h->assertSame(8, count($enabled['recovery_codes']), 'activation returns recovery codes');
    $stored = (string) $pdo->query("SELECT totp_secret_protected FROM iam_users WHERE id=1")->fetchColumn();
    $h->assertTrue($stored !== '' && $stored !== $preparedSecret && !str_contains($stored, $preparedSecret), 'TOTP secret not stored in clear text');
    $storedRecovery = (string) $pdo->query("SELECT totp_recovery_codes_json FROM iam_users WHERE id=1")->fetchColumn();
    $h->assertTrue($storedRecovery !== '' && !str_contains($storedRecovery, $enabled['recovery_codes'][0]), 'recovery codes not stored in clear text');
    $h->assertTrue(!array_key_exists('secret', $enabled), 'activation response does not return secret');

    $auth = new AuthRepository($db);
    putenv('CMS_TOTP_KEY=test-key');
    $h->assertSame('totp_required', $auth->attemptWithTotp('totp@example.test', 'correct-password', '')['status'], 'totp login requires code');
    $h->assertSame('invalid_totp', $auth->attemptWithTotp('totp@example.test', 'correct-password', '000000')['status'], 'totp login rejects wrong code');
    $h->assertSame('invalid_credentials', $auth->attemptWithTotp('totp@example.test', 'wrong-password', $enabled['recovery_codes'][1])['status'], 'totp login rejects recovery code when password is wrong');
    $h->assertSame('ok', $auth->attemptWithTotp('totp@example.test', 'correct-password', $enabled['recovery_codes'][1])['status'], 'wrong password does not consume recovery code');
    $h->assertSame('ok', $auth->attemptWithTotp('totp@example.test', 'correct-password', $enabled['recovery_codes'][0])['status'], 'totp login accepts recovery code');
    $h->assertSame('invalid_totp', $auth->attemptWithTotp('totp@example.test', 'correct-password', $enabled['recovery_codes'][0])['status'], 'totp login rejects reused recovery code');
    $auditContext = (string) $pdo->query("SELECT group_concat(context_json, '\n') FROM iam_audit_logs WHERE action_key='auth.totp_recovery_used'")->fetchColumn();
    $h->assertTrue(!str_contains($auditContext, $enabled['recovery_codes'][0]) && !str_contains($auditContext, str_replace('-', '', $enabled['recovery_codes'][0])), 'recovery code is not written to audit context');
    $h->assertSame('ok', $auth->attemptWithTotp('totp@example.test', 'correct-password', TotpService::currentCode($preparedSecret))['status'], 'totp login accepts current code');
    $regenerated = $iam->regenerateTotpRecoveryCodes(1, 'test-key');
    $h->assertSame(8, count($regenerated['recovery_codes']), 'regeneration returns recovery codes');
    $h->assertTrue($regenerated['recovery_codes'][0] !== $enabled['recovery_codes'][0], 'regeneration replaces recovery codes');
    $h->assertSame('invalid_totp', $auth->attemptWithTotp('totp@example.test', 'correct-password', $enabled['recovery_codes'][2])['status'], 'regeneration invalidates old unused recovery codes');
    $h->assertSame('ok', $auth->attemptWithTotp('totp@example.test', 'correct-password', $regenerated['recovery_codes'][0])['status'], 'regenerated recovery code can be used once');

    $iam->setLoginMode(1, 'password');
    $passwordMode = $pdo->query("SELECT login_mode, totp_secret_protected, totp_recovery_codes_json FROM iam_users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $h->assertSame('password', $passwordMode['login_mode'] ?? null, 'login mode can be changed to password');
    $h->assertSame(null, $passwordMode['totp_secret_protected'] ?? null, 'password mode clears TOTP secret');
    $h->assertSame(null, $passwordMode['totp_recovery_codes_json'] ?? null, 'password mode clears recovery codes');

    $setupAgain = $iam->prepareTotp(1, 'DEC CMS');
    $secretAgain = (string) $setupAgain['secret'];
    $iam->enableTotp(1, $secretAgain, TotpService::currentCode($secretAgain), true, 'test-key');
    $iam->setLoginMode(1, 'email_code');
    $emailCodeMode = $pdo->query("SELECT login_mode, totp_secret_protected, totp_recovery_codes_json FROM iam_users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $h->assertSame('email_code', $emailCodeMode['login_mode'] ?? null, 'login mode can be changed to email_code');
    $h->assertSame(null, $emailCodeMode['totp_secret_protected'] ?? null, 'email_code mode clears TOTP secret');
    $h->assertSame(null, $emailCodeMode['totp_recovery_codes_json'] ?? null, 'email_code mode does not receive recovery codes');
} finally {
    putenv('CMS_TOTP_KEY');
    unset($pdo, $auth, $iam);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('UNIT TOTP service'));
