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

$h = new TestHarness();
[$dir, $path] = test_temp_db(base_path('database/iam.sql'));
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    $hash = password_hash('correct-password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES(1,'password@example.test','password@example.test',?,1,'password')")->execute([$hash]);
    $pdo->prepare("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode,totp_enabled) VALUES(2,'code@example.test','code@example.test',?,1,'email_code',1)")->execute([$hash]);
    $pdo->prepare("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode,totp_enabled,totp_required,totp_secret_protected) VALUES(3,'totp@example.test','totp@example.test',?,1,'totp',1,1,'protected')")->execute([$hash]);

    $auth = new AuthRepository($db);
    $h->assertSame('password', $auth->loginChallengeForEmail('password@example.test')['challenge'], 'password mode has password challenge');
    $h->assertSame('email_code', $auth->loginChallengeForEmail('code@example.test')['challenge'], 'email_code mode has email code challenge');
    $h->assertSame('totp', $auth->loginChallengeForEmail('totp@example.test')['challenge'], 'totp mode has totp challenge');
    $h->assertSame('invalid_credentials', $auth->createEmailTwoFactorChallenge('totp@example.test')['status'], 'totp mode does not create email code challenge');
    $h->assertSame('ok', $auth->createEmailTwoFactorChallenge('code@example.test')['status'], 'email_code mode creates email challenge');

    $iam = new IamAdminRepository($db);
    $pdo->exec("INSERT INTO iam_sessions(user_id,session_token_hash,expires_at,created_at) VALUES(2,'session',datetime('now','+1 hour'),datetime('now'))");
    $user = $iam->setLoginMode(2, 'password');
    $h->assertSame('password', $user['login_mode'], 'setLoginMode changes mode');
    $h->assertSame(0, (int) $pdo->query("SELECT count(*) FROM iam_sessions WHERE user_id=2")->fetchColumn(), 'mode change revokes sessions');

    $pdo->exec("INSERT INTO iam_sessions(user_id,session_token_hash,expires_at,created_at) VALUES(1,'password-change-session',datetime('now','+1 hour'),datetime('now'))");
    $iam->updateUser(1, ['email'=>'password@example.test','first_name'=>'Test','last_name'=>'Import','locale'=>'fr-CH','is_active'=>true,'password'=>'NewPassword123!']);
    $updatedHash = (string)$pdo->query('SELECT password_hash FROM iam_users WHERE id=1')->fetchColumn();
    $h->assertTrue(password_verify('NewPassword123!', $updatedHash), 'user import/update can replace an existing password');
    $h->assertSame(0, (int)$pdo->query('SELECT count(*) FROM iam_sessions WHERE user_id=1')->fetchColumn(), 'password replacement revokes existing sessions');
} finally {
    unset($pdo, $auth, $iam);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}
exit($h->finish('UNIT IAM login modes'));
