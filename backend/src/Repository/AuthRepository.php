<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\ErrorCode;
use App\Security\Csrf;
use App\Security\SessionManager;

final class AuthRepository
{
    private array $permissionsBySite = [];

    public function __construct(private readonly Database $db) {}

    public function database(): Database
    {
        return $this->db;
    }

    public function attempt(string $email, string $password, array $meta = []): bool
    {
        $result = $this->attemptWithTotp($email, $password, (string) ($meta['totp_code'] ?? ''), $meta);
        return $result['status'] === 'ok';
    }


    /** @return array{exists:bool,totp_enabled:bool,challenge:string,email:string} */
    public function loginChallengeForEmail(string $email): array
    {
        $emailNormalized = self::normalizeEmail($email);
        $user = $this->db->one('SELECT id, email, totp_enabled FROM iam_users WHERE email_normalized = :email_normalized AND is_active = 1 LIMIT 1', [
            'email_normalized' => $emailNormalized,
        ]);
        $totpEnabled = $user ? !empty($user['totp_enabled']) : false;
        return [
            'exists' => $user !== null,
            'totp_enabled' => $totpEnabled,
            'challenge' => $totpEnabled ? 'email_2fa' : 'password',
            'email' => $user ? (string) $user['email'] : $emailNormalized,
        ];
    }

    /** @return array{status:string,email?:string,code?:string,user_id?:int,expires_at?:string} */
    public function createEmailTwoFactorChallenge(string $email, array $meta = []): array
    {
        $emailNormalized = self::normalizeEmail($email);
        $user = $this->db->one('SELECT id, email, totp_enabled FROM iam_users WHERE email_normalized = :email_normalized AND is_active = 1 LIMIT 1', [
            'email_normalized' => $emailNormalized,
        ]);
        if (!$user || empty($user['totp_enabled'])) {
            $this->audit(null, 'auth.email_2fa_not_available', 'iam_user', null, ['email' => $emailNormalized, 'ip' => $meta['ip'] ?? null]);
            return ['status' => 'invalid_credentials'];
        }

        $code = (string) random_int(100000, 999999);
        $now = now_utc();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 600);
        $this->db->run('DELETE FROM iam_email_2fa_challenges WHERE user_id = :user_id AND (consumed_at IS NOT NULL OR expires_at <= :now)', [
            'user_id' => (int) $user['id'],
            'now' => $now,
        ]);
        $this->db->run('INSERT INTO iam_email_2fa_challenges(user_id, code_hash, ip_address, user_agent, expires_at, created_at) VALUES(:user_id, :code_hash, :ip_address, :user_agent, :expires_at, :created_at)', [
            'user_id' => (int) $user['id'],
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'ip_address' => self::limit((string) ($meta['ip'] ?? ''), 80),
            'user_agent' => self::limit((string) ($meta['user_agent'] ?? ''), 500),
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
        $this->audit((int) $user['id'], 'auth.email_2fa_sent', 'iam_user', (int) $user['id'], ['ip' => $meta['ip'] ?? null]);
        return ['status' => 'ok', 'email' => (string) $user['email'], 'code' => $code, 'user_id' => (int) $user['id'], 'expires_at' => $expiresAt];
    }

    /** @return array{status:string,user_id?:int} */
    public function verifyEmailTwoFactorCode(string $email, string $code, array $meta = []): array
    {
        $emailNormalized = self::normalizeEmail($email);
        $code = preg_replace('/\D+/', '', trim($code)) ?? '';
        $user = $this->db->one('SELECT * FROM iam_users WHERE email_normalized = :email_normalized AND is_active = 1 LIMIT 1', ['email_normalized' => $emailNormalized]);
        if (!$user || empty($user['totp_enabled'])) {
            $this->audit(null, 'auth.email_2fa_failed', 'iam_user', null, ['email' => $emailNormalized, 'ip' => $meta['ip'] ?? null]);
            return ['status' => 'invalid_credentials'];
        }
        if ($code === '') {
            return ['status' => 'totp_required', 'user_id' => (int) $user['id']];
        }

        $challenge = $this->db->one('SELECT * FROM iam_email_2fa_challenges WHERE user_id = :user_id AND consumed_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1', [
            'user_id' => (int) $user['id'],
            'now' => now_utc(),
        ]);
        if (!$challenge) {
            $this->audit((int) $user['id'], 'auth.email_2fa_expired', 'iam_user', (int) $user['id'], ['ip' => $meta['ip'] ?? null]);
            return ['status' => 'expired_totp', 'user_id' => (int) $user['id']];
        }
        if ((int) ($challenge['attempt_count'] ?? 0) >= 5) {
            return ['status' => 'invalid_totp', 'user_id' => (int) $user['id']];
        }
        if (!password_verify($code, (string) $challenge['code_hash'])) {
            $this->db->run('UPDATE iam_email_2fa_challenges SET attempt_count = attempt_count + 1 WHERE id = :id', ['id' => (int) $challenge['id']]);
            $this->audit((int) $user['id'], 'auth.email_2fa_failed', 'iam_user', (int) $user['id'], ['ip' => $meta['ip'] ?? null]);
            return ['status' => 'invalid_totp', 'user_id' => (int) $user['id']];
        }

        $this->db->run('UPDATE iam_email_2fa_challenges SET consumed_at = :consumed_at WHERE id = :id', [
            'consumed_at' => now_utc(),
            'id' => (int) $challenge['id'],
        ]);
        $this->openSession($user, $meta);
        return ['status' => 'ok', 'user_id' => (int) $user['id']];
    }

    /** @return array{status:string,user_id?:int} */
    public function attemptWithTotp(string $email, string $password, string $totpCode = '', array $meta = []): array
    {
        $emailNormalized = self::normalizeEmail($email);
        $user = $this->db->one('SELECT * FROM iam_users WHERE email_normalized = :email_normalized AND is_active = 1 LIMIT 1', ['email_normalized' => $emailNormalized]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $this->audit(null, 'auth.login_failed', 'iam_user', null, ['email' => $emailNormalized, 'ip' => $meta['ip'] ?? null]);
            return ['status' => 'invalid_credentials'];
        }

        if (!empty($user['totp_enabled'])) {
            $verification = $this->verifyTotpForUserRow($user, $totpCode);
            if ($verification === 'missing') {
                $this->audit((int) $user['id'], 'auth.totp_required', 'iam_user', (int) $user['id'], ['ip' => $meta['ip'] ?? null]);
                return ['status' => 'totp_required', 'user_id' => (int) $user['id']];
            }
            if ($verification !== 'ok') {
                $this->audit((int) $user['id'], 'auth.totp_failed', 'iam_user', (int) $user['id'], ['ip' => $meta['ip'] ?? null]);
                return ['status' => 'invalid_totp', 'user_id' => (int) $user['id']];
            }
        }

        $this->openSession($user, $meta);
        return ['status' => 'ok', 'user_id' => (int) $user['id']];
    }

    private function verifyTotpForUserRow(array $user, string $code): string
    {
        return trim($code) === '' ? 'missing' : 'invalid';
    }

    private function openSession(array $user, array $meta = []): void
    {
        SessionManager::rotate();
        Csrf::rotate();
        $token = bin2hex(random_bytes(32));
        $now = now_utc();
        $expires = gmdate('Y-m-d H:i:s', time() + 43200);
        $this->db->run('UPDATE iam_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id', [
            'last_login_at' => $now,
            'updated_at' => $now,
            'id' => (int) $user['id'],
        ]);
        $this->db->run('INSERT INTO iam_sessions(user_id, session_token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at) VALUES(:user_id, :session_token_hash, :ip_address, :user_agent, :last_seen_at, :expires_at, :created_at)', [
            'user_id' => (int) $user['id'],
            'session_token_hash' => self::hashSessionToken($token),
            'ip_address' => (string) ($meta['ip'] ?? ''),
            'user_agent' => self::limit((string) ($meta['user_agent'] ?? ''), 500),
            'last_seen_at' => $now,
            'expires_at' => $expires,
            'created_at' => $now,
        ]);
        $_SESSION['admin_user'] = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? ''))),
            'session_secret' => $token,
        ];
        $this->permissionsBySite = [];
        $this->audit((int) $user['id'], 'auth.login_success', 'iam_user', (int) $user['id'], ['ip' => $meta['ip'] ?? null, 'email_2fa' => !empty($user['totp_enabled'])]);
    }

    public function totpAppKey(): string
    {
        return (string) ($_ENV['CMS_TOTP_KEY'] ?? $_SERVER['CMS_TOTP_KEY'] ?? getenv('CMS_TOTP_KEY') ?: $_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY') ?: '');
    }

    public function logout(): void
    {
        $user = $this->user();
        if ($user && !empty($user['session_secret'])) {
            $this->db->run('DELETE FROM iam_sessions WHERE session_token_hash = :session_token_hash', ['session_token_hash' => self::hashSessionToken((string) $user['session_secret'])]);
            $this->audit((int) $user['id'], 'auth.logout', 'iam_user', (int) $user['id']);
        }
        SessionManager::destroy();
    }

    public function user(): ?array
    {
        return is_array($_SESSION['admin_user'] ?? null) ? $_SESSION['admin_user'] : null;
    }

    public function requireAuth(): void
    {
        $user = $this->user();
        if (!$user || empty($user['session_secret'])) {
            throw new ApiException(ErrorCode::AUTH_REQUIRED, ErrorCode::message(ErrorCode::AUTH_REQUIRED), ErrorCode::httpStatus(ErrorCode::AUTH_REQUIRED));
        }
        $row = $this->db->one(
            "SELECT s.*, u.is_active, u.last_password_change_at
             FROM iam_sessions s
             JOIN iam_users u ON u.id = s.user_id
             WHERE s.user_id = :user_id
               AND s.session_token_hash = :session_token_hash
               AND s.expires_at > :now
               AND u.is_active = 1
               AND (u.last_password_change_at IS NULL OR u.last_password_change_at = '' OR s.created_at >= u.last_password_change_at)
             LIMIT 1",
            [
                'user_id' => (int) $user['id'],
                'session_token_hash' => self::hashSessionToken((string) $user['session_secret']),
                'now' => now_utc(),
            ]
        );
        if (!$row) {
            SessionManager::destroy();
            throw new ApiException(ErrorCode::AUTH_REQUIRED, ErrorCode::message(ErrorCode::AUTH_REQUIRED), ErrorCode::httpStatus(ErrorCode::AUTH_REQUIRED));
        }
        $this->db->run('UPDATE iam_sessions SET last_seen_at = :last_seen_at WHERE id = :id', ['last_seen_at' => now_utc(), 'id' => (int) $row['id']]);
    }

    private static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        return function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
    }

    private static function limit(string $value, int $max): string
    {
        $value = str_replace("\0", '', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private static function hashSessionToken(string $token): string
    {
        return hash('sha256', $token);
    }


    /** @return array<string,mixed> */
    public function currentUserProfile(): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }

        $row = $this->db->one('SELECT id, email, first_name, last_name, locale, is_active, last_login_at, created_at, updated_at, totp_enabled, totp_required FROM iam_users WHERE id = :id LIMIT 1', [
            'id' => (int) $user['id'],
        ]);

        return is_array($row) ? $row : [];
    }

    /** @param array<string,string> $data @return array<string,mixed> */
    public function updateCurrentUserProfile(array $data): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }

        $email = trim($data['email'] ?? '');
        $emailNormalized = self::normalizeEmail($email);
        $duplicate = $this->db->one('SELECT id FROM iam_users WHERE email_normalized = :email_normalized AND id <> :id LIMIT 1', [
            'email_normalized' => $emailNormalized,
            'id' => (int) $user['id'],
        ]);
        if ($duplicate) {
            throw new \InvalidArgumentException('EMAIL_ALREADY_USED');
        }

        $now = now_utc();
        $this->db->run('UPDATE iam_users SET email = :email, email_normalized = :email_normalized, first_name = :first_name, last_name = :last_name, locale = :locale, updated_at = :updated_at WHERE id = :id', [
            'email' => $email,
            'email_normalized' => $emailNormalized,
            'first_name' => self::limit(trim($data['first_name'] ?? ''), 120),
            'last_name' => self::limit(trim($data['last_name'] ?? ''), 120),
            'locale' => self::limit(trim($data['locale'] ?? 'fr-CH'), 16),
            'updated_at' => $now,
            'id' => (int) $user['id'],
        ]);

        $profile = $this->currentUserProfile();
        $_SESSION['admin_user']['email'] = (string) ($profile['email'] ?? $email);
        $_SESSION['admin_user']['name'] = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));
        $this->audit((int) $user['id'], 'auth.profile_updated', 'iam_user', (int) $user['id']);

        return $profile;
    }



    public function changeCurrentUserPassword(string $currentPassword, string $newPassword): void
    {
        $user = $this->user();
        if (!$user) {
            return;
        }

        $row = $this->db->one('SELECT id, password_hash FROM iam_users WHERE id = :id AND is_active = 1 LIMIT 1', [
            'id' => (int) $user['id'],
        ]);
        if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
            $this->audit((int) $user['id'], 'auth.password_change_failed', 'iam_user', (int) $user['id']);
            throw new \InvalidArgumentException('CURRENT_PASSWORD_INVALID');
        }

        $now = now_utc();
        $this->db->run('UPDATE iam_users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => $now,
            'id' => (int) $user['id'],
        ]);

        if (!empty($user['session_secret'])) {
            $this->db->run('DELETE FROM iam_sessions WHERE user_id = :user_id AND session_token_hash <> :session_token_hash', [
                'user_id' => (int) $user['id'],
                'session_token_hash' => self::hashSessionToken((string) $user['session_secret']),
            ]);
        }

        $this->audit((int) $user['id'], 'auth.password_changed', 'iam_user', (int) $user['id'], ['other_sessions_revoked' => true]);
    }

    /** @return array<string,mixed>|null */
    public function findActiveUserByEmail(string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM iam_users WHERE email_normalized = :email_normalized AND is_active = 1 LIMIT 1',
            ['email_normalized' => self::normalizeEmail($email)]
        );
    }

    /** @return array<string,mixed>|null */
    public function findUserByPasswordResetSelector(string $selector): ?array
    {
        return $this->db->one(
            'SELECT * FROM iam_users WHERE password_reset_selector = :selector LIMIT 1',
            ['selector' => trim($selector)]
        );
    }

    public function storePasswordResetToken(int $userId, string $selector, string $tokenHash, string $expiresAt, string $requestedAt): void
    {
        $this->db->run(
            'UPDATE iam_users
             SET password_reset_selector = :selector,
                 password_reset_token_hash = :token_hash,
                 password_reset_expires_at = :expires_at,
                 password_reset_requested_at = :requested_at,
                 password_reset_sent_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'selector' => $selector,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'requested_at' => $requestedAt,
                'updated_at' => now_utc(),
                'id' => $userId,
            ]
        );
    }

    public function markPasswordResetSent(int $userId): void
    {
        $this->db->run(
            'UPDATE iam_users SET password_reset_sent_at = :sent_at, updated_at = :updated_at WHERE id = :id',
            ['sent_at' => now_utc(), 'updated_at' => now_utc(), 'id' => $userId]
        );
    }

    public function clearPasswordResetToken(int $userId): void
    {
        $this->db->run(
            'UPDATE iam_users
             SET password_reset_selector = NULL,
                 password_reset_token_hash = NULL,
                 password_reset_expires_at = NULL,
                 password_reset_requested_at = NULL,
                 password_reset_sent_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            ['updated_at' => now_utc(), 'id' => $userId]
        );
    }

    public function canRequestPasswordReset(int $userId, int $cooldownSeconds): bool
    {
        $row = $this->db->one('SELECT password_reset_requested_at FROM iam_users WHERE id = :id LIMIT 1', ['id' => $userId]);
        if (!$row) {
            return false;
        }
        $requestedAt = trim((string) ($row['password_reset_requested_at'] ?? ''));
        if ($requestedAt === '') {
            return true;
        }
        $requestedTs = strtotime($requestedAt . ' UTC');
        if ($requestedTs === false) {
            return true;
        }
        return (time() - $requestedTs) >= $cooldownSeconds;
    }

    public function updateUserPasswordAfterReset(int $userId, string $passwordHash): void
    {
        $now = now_utc();
        $this->db->run(
            'UPDATE iam_users
             SET password_hash = :password_hash,
                 last_password_change_at = :last_password_change_at,
                 updated_at = :updated_at
             WHERE id = :id AND is_active = 1',
            [
                'password_hash' => $passwordHash,
                'last_password_change_at' => $now,
                'updated_at' => $now,
                'id' => $userId,
            ]
        );
    }

    public function revokeAllSessionsForUser(int $userId): void
    {
        $this->db->run('DELETE FROM iam_sessions WHERE user_id = :user_id', ['user_id' => $userId]);
    }


    /** @return list<string> */
    public function permissions(?int $siteId = null): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }

        $siteId = $siteId ?? $this->currentSiteId();
        $cacheKey = $siteId > 0 ? 'site:' . $siteId : 'global';

        if (!array_key_exists($cacheKey, $this->permissionsBySite)) {
            if ($this->isSuperAdmin((int) $user['id'], $siteId)) {
                $rows = $this->db->all('SELECT permission_key FROM iam_permissions ORDER BY permission_key');
                $permissions = array_values(array_map('strval', array_column($rows, 'permission_key')));
                if (!in_array('*', $permissions, true)) {
                    array_unshift($permissions, '*');
                }
                $this->permissionsBySite[$cacheKey] = array_values(array_unique($permissions));
                return $this->permissionsBySite[$cacheKey];
            }

            $params = ['user_id' => (int) $user['id']];
            $siteClause = '';
            if ($siteId > 0) {
                $siteClause = "
                    UNION
                    SELECT DISTINCT p.permission_key
                    FROM iam_permissions p
                    JOIN iam_role_permissions rp ON rp.permission_id = p.id
                    JOIN iam_user_site_roles usr ON usr.role_id = rp.role_id
                    WHERE usr.user_id = :site_user_id AND usr.site_id = :site_id
                ";
                $params['site_user_id'] = (int) $user['id'];
                $params['site_id'] = $siteId;
            }

            $rows = $this->db->all(
                'SELECT DISTINCT permission_key FROM (
                    SELECT DISTINCT p.permission_key
                    FROM iam_permissions p
                    JOIN iam_role_permissions rp ON rp.permission_id = p.id
                    JOIN iam_user_roles ur ON ur.role_id = rp.role_id
                    WHERE ur.user_id = :user_id
                    ' . $siteClause . '
                ) ORDER BY permission_key',
                $params
            );
            $this->permissionsBySite[$cacheKey] = array_values(array_map('strval', array_column($rows, 'permission_key')));
        }

        return $this->permissionsBySite[$cacheKey];
    }

    public function hasPermission(string $permission, ?int $siteId = null): bool
    {
        $user = $this->user();
        if ($user && $this->isSuperAdmin((int) $user['id'], $siteId ?? $this->currentSiteId())) {
            return true;
        }
        $permissions = $this->permissions($siteId);
        return in_array($permission, $permissions, true) || in_array('*', $permissions, true);
    }

    public function canAccessSite(int $siteId): bool
    {
        $user = $this->user();
        if (!$user || $siteId <= 0) {
            return false;
        }
        if ($this->hasGlobalSiteAccess((int) $user['id'])) {
            return true;
        }
        $row = $this->db->one(
            'SELECT 1 FROM iam_user_site_roles WHERE user_id = :user_id AND site_id = :site_id LIMIT 1',
            ['user_id' => (int) $user['id'], 'site_id' => $siteId]
        );
        return (bool) $row;
    }

    public function currentUserIsSuperAdmin(?int $siteId = null): bool
    {
        $user = $this->user();
        return $user ? $this->isSuperAdmin((int) $user['id'], $siteId ?? $this->currentSiteId()) : false;
    }

    /** @return list<int> */
    public function authorizedSiteIds(): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }
        if ($this->hasGlobalSiteAccess((int) $user['id'])) {
            // [] is the established admin-context contract for unrestricted site access.
            return [];
        }
        $rows = $this->db->all(
            'SELECT DISTINCT site_id FROM iam_user_site_roles WHERE user_id = :user_id ORDER BY site_id',
            ['user_id' => (int) $user['id']]
        );
        return array_map(static fn(array $row): int => (int) $row['site_id'], $rows);
    }

    /** @return list<array{site_id:int, role_key:string, role_name:string}> */
    public function siteRoles(): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT usr.site_id, r.role_key, r.name AS role_name
             FROM iam_user_site_roles usr
             JOIN iam_roles r ON r.id = usr.role_id
             WHERE usr.user_id = :user_id
             ORDER BY usr.site_id, r.role_key',
            ['user_id' => (int) $user['id']]
        );

        return array_map(static fn(array $row): array => [
            'site_id' => (int) $row['site_id'],
            'role_key' => (string) $row['role_key'],
            'role_name' => (string) $row['role_name'],
        ], $rows);
    }


    private function hasGlobalSiteAccess(int $userId): bool
    {
        return (bool) $this->db->one(
            'SELECT 1 FROM iam_user_roles WHERE user_id = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    private function isSuperAdmin(int $userId, ?int $siteId = null): bool
    {
        $global = $this->db->one(
            'SELECT 1 FROM iam_user_roles ur JOIN iam_roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id AND r.role_key = :role_key LIMIT 1',
            ['user_id' => $userId, 'role_key' => 'super_admin']
        );
        if ($global) {
            return true;
        }

        $siteId = $siteId ?? 0;
        if ($siteId <= 0) {
            return false;
        }

        $scoped = $this->db->one(
            'SELECT 1 FROM iam_user_site_roles usr JOIN iam_roles r ON r.id = usr.role_id WHERE usr.user_id = :user_id AND usr.site_id = :site_id AND r.role_key = :role_key LIMIT 1',
            ['user_id' => $userId, 'site_id' => $siteId, 'role_key' => 'super_admin']
        );

        return (bool) $scoped;
    }

    private function currentSiteId(): int
    {
        return max(0, (int) ($_SERVER['CMS_SITE_ID'] ?? 0));
    }

    public function audit(?int $actorUserId, string $actionKey, ?string $resourceType = null, ?int $resourceId = null, array $context = []): void
    {
        $this->db->run('INSERT INTO iam_audit_logs(actor_user_id, action_key, resource_type, resource_id, context_json, ip_address, user_agent, created_at) VALUES(:actor_user_id, :action_key, :resource_type, :resource_id, :context_json, :ip_address, :user_agent, :created_at)', [
            'actor_user_id' => $actorUserId,
            'action_key' => $actionKey,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => self::limit((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
            'created_at' => now_utc(),
        ]);
    }
}
