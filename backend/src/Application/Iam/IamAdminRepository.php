<?php

declare(strict_types=1);

namespace App\Application\Iam;

use App\Core\Database;
use App\Security\TotpService;

final class IamAdminRepository
{
    public function __construct(private readonly Database $db, private readonly ?Database $coreDb = null) {}

    /** @return list<array<string,mixed>> */
    public function sites(?int $onlySiteId = null): array
    {
        $db = $this->coreDb ?? $this->db;
        $params = [];
        $where = '';
        if ($onlySiteId !== null && $onlySiteId > 0) {
            $where = 'WHERE s.id = :site_id';
            $params['site_id'] = $onlySiteId;
        }
        $rows = $db->all(
            "SELECT s.id, s.site_key, s.name, s.default_language_code, s.is_active,
                    COALESCE(sd.host, '') AS host,
                    COALESCE(sd.base_path, '') AS base_path
             FROM sites s
             LEFT JOIN site_domains sd ON sd.id = (
                 SELECT d.id FROM site_domains d
                 WHERE d.site_id = s.id AND d.is_active = 1
                 ORDER BY d.is_primary DESC, d.id ASC
                 LIMIT 1
             )
             {$where}
             ORDER BY s.is_active DESC, s.name COLLATE NOCASE, s.id",
            $params
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'site_key' => (string) $row['site_key'],
            'name' => (string) $row['name'],
            'default_language_code' => (string) ($row['default_language_code'] ?? 'fr'),
            'is_active' => (bool) $row['is_active'],
            'host' => (string) ($row['host'] ?? ''),
            'base_path' => (string) ($row['base_path'] ?? ''),
        ], $rows);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listUsers(array $filters): array
    {
        $where = [];
        $params = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(email_normalized LIKE :q OR lower(coalesce(first_name,\'\')) LIKE :q OR lower(coalesce(last_name,\'\')) LIKE :q)';
            $params['q'] = '%' . self::normalize($q) . '%';
        }
        if (($filters['status'] ?? '') === 'active') $where[] = 'is_active = 1';
        if (($filters['status'] ?? '') === 'inactive') $where[] = 'is_active = 0';
        if (!empty($filters['site_id'])) {
            $where[] = 'id IN (SELECT user_id FROM iam_user_site_roles WHERE site_id = :site_id)';
            $params['site_id'] = (int)$filters['site_id'];
        }
        $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int)($this->db->one("SELECT count(*) AS c FROM iam_users {$clause}", $params)['c'] ?? 0);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $rows = $this->db->all("SELECT id,email,first_name,last_name,locale,is_active,disabled_at,disabled_reason,last_login_at,created_at,updated_at,login_mode,totp_enabled,totp_required,totp_enabled_at,
            (SELECT count(*) FROM iam_sessions s WHERE s.user_id=iam_users.id AND s.expires_at > CURRENT_TIMESTAMP) AS active_session_count
            FROM iam_users {$clause} ORDER BY updated_at DESC, id DESC LIMIT :limit OFFSET :offset", $params + ['limit'=>$limit,'offset'=>$offset]);
        return ['rows'=>array_map(fn($r)=>$this->userSummary($r), $rows), 'total'=>$total];
    }

    public function findUser(int $id): ?array
    {
        $row = $this->db->one('SELECT id,email,first_name,last_name,locale,is_active,disabled_at,disabled_reason,last_login_at,created_at,updated_at,login_mode,totp_enabled,totp_required,totp_enabled_at FROM iam_users WHERE id=:id LIMIT 1', ['id'=>$id]);
        if (!$row) return null;
        $user = $this->userSummary($row);
        $user['roles'] = $this->globalRoleIds($id);
        $user['site_roles'] = $this->siteRoleRows($id);
        $user['sessions'] = $this->listSessions(['user_id'=>$id,'limit'=>20,'offset'=>0])['rows'];
        return $user;
    }

    public function createUser(array $data): array
    {
        $email = trim((string)$data['email']);
        $password = (string)($data['password'] ?? '');
        $nextActive = array_key_exists('is_active', $data) ? !empty($data['is_active']) : true;
        $now = now_utc();
        $loginMode = self::sanitizeLoginMode((string)($data['login_mode'] ?? 'password'));
        $this->db->run('INSERT INTO iam_users(email,email_normalized,password_hash,first_name,last_name,locale,is_active,login_mode,totp_enabled,totp_required,created_at,updated_at) VALUES(:email,:email_normalized,:password_hash,:first_name,:last_name,:locale,:is_active,:login_mode,:totp_enabled,:totp_required,:created_at,:updated_at)', [
            'email'=>$email,
            'email_normalized'=>self::normalize($email),
            'password_hash'=>password_hash($password, PASSWORD_DEFAULT),
            'first_name'=>self::limit(trim((string)($data['first_name'] ?? '')),120),
            'last_name'=>self::limit(trim((string)($data['last_name'] ?? '')),120),
            'locale'=>self::limit(trim((string)($data['locale'] ?? 'fr-CH')),16),
            'is_active'=>$nextActive ? 1 : 0,
            'login_mode'=>$loginMode,
            'totp_enabled'=>$loginMode === 'password' ? 0 : 1,
            'totp_required'=>$loginMode === 'totp' ? 1 : 0,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
        $id = $this->db->lastInsertId();
        $this->replaceUserRoles($id, $data['role_ids'] ?? [], $data['site_roles'] ?? []);
        return $this->findUser($id) ?? [];
    }


    public function createUserScoped(array $data, int $siteId): array
    {
        $data['role_ids'] = [];
        $data['site_roles'] = $this->scopedSiteRoles($data['site_roles'] ?? [], $siteId);
        if ($data['site_roles'] === []) {
            throw new \InvalidArgumentException('USER_ROLE_REQUIRED');
        }
        return $this->createUser($data);
    }

    public function updateUserScoped(int $id, array $data, int $siteId): array
    {
        if (!$this->userBelongsToSite($id, $siteId)) throw new \InvalidArgumentException('USER_NOT_FOUND');
        $currentSiteRoles = $this->scopedSiteRoles($data['site_roles'] ?? [], $siteId);
        $preservedSiteRoles = array_values(array_filter($this->siteRoleRows($id), static fn(array $row): bool => (int)$row['site_id'] !== $siteId));
        $data['role_ids'] = $this->globalRoleIds($id);
        $data['site_roles'] = array_merge($preservedSiteRoles, $currentSiteRoles);
        return $this->updateUser($id, $data);
    }

    public function userBelongsToSite(int $userId, int $siteId): bool
    {
        return $siteId > 0 && (bool)$this->db->one('SELECT 1 FROM iam_user_site_roles WHERE user_id=:user_id AND site_id=:site_id LIMIT 1', ['user_id'=>$userId,'site_id'=>$siteId]);
    }

    public function updateUser(int $id, array $data): array
    {
        $existing = $this->findUser($id);
        if (!$existing) throw new \InvalidArgumentException('USER_NOT_FOUND');
        $email = trim((string)($data['email'] ?? $existing['email']));
        $duplicate = $this->db->one('SELECT id FROM iam_users WHERE email_normalized=:email_normalized AND id<>:id LIMIT 1', ['email_normalized'=>self::normalize($email),'id'=>$id]);
        if ($duplicate) throw new \InvalidArgumentException('EMAIL_ALREADY_USED');
        $nextActive = !empty($data['is_active']);
        $nextRoleIds = array_key_exists('role_ids',$data) ? $data['role_ids'] : $this->globalRoleIds($id);
        $nextSiteRoles = array_key_exists('site_roles',$data) ? $data['site_roles'] : $this->siteRoleRows($id);
        if ($this->wouldLeaveNoActiveSuperAdmin($id, $nextActive, $nextRoleIds, $nextSiteRoles)) {
            throw new \InvalidArgumentException('LAST_SUPER_ADMIN');
        }
        $this->db->run('UPDATE iam_users SET email=:email,email_normalized=:email_normalized,first_name=:first_name,last_name=:last_name,locale=:locale,is_active=:is_active,disabled_at=:disabled_at,disabled_reason=:disabled_reason,updated_at=:updated_at WHERE id=:id', [
            'email'=>$email,
            'email_normalized'=>self::normalize($email),
            'first_name'=>self::limit(trim((string)($data['first_name'] ?? '')),120),
            'last_name'=>self::limit(trim((string)($data['last_name'] ?? '')),120),
            'locale'=>self::limit(trim((string)($data['locale'] ?? 'fr-CH')),16),
            'is_active'=>$nextActive ? 1 : 0,
            'disabled_at'=>$nextActive ? null : (($existing['disabled_at'] ?? null) ?: now_utc()),
            'disabled_reason'=>$nextActive ? null : self::limit(trim((string)($data['disabled_reason'] ?? '')),255),
            'updated_at'=>now_utc(),
            'id'=>$id,
        ]);
        if (array_key_exists('role_ids',$data) || array_key_exists('site_roles',$data)) {
            $this->replaceUserRoles($id, $nextRoleIds, $nextSiteRoles);
        }
        if (!$nextActive) $this->revokeSessions($id);
        return $this->findUser($id) ?? [];
    }

    public function setActive(int $id, bool $active, string $reason=''): array
    {
        if (!$this->findUser($id)) throw new \InvalidArgumentException('USER_NOT_FOUND');
        if (!$active && $this->isLastActiveSuperAdmin($id)) throw new \InvalidArgumentException('LAST_SUPER_ADMIN');
        $this->db->run('UPDATE iam_users SET is_active=:active, disabled_at=:disabled_at, disabled_reason=:reason, updated_at=:updated_at WHERE id=:id', [
            'active'=>$active ? 1 : 0, 'disabled_at'=>$active ? null : now_utc(), 'reason'=>$active ? null : self::limit($reason,255), 'updated_at'=>now_utc(), 'id'=>$id
        ]);
        if (!$active) $this->revokeSessions($id);
        return $this->findUser($id) ?? [];
    }

    public function resetPassword(int $id): string
    {
        if (!$this->findUser($id)) throw new \InvalidArgumentException('USER_NOT_FOUND');
        $temporary = self::temporaryPassword();
        $this->db->run('UPDATE iam_users SET password_hash=:hash, updated_at=:updated_at WHERE id=:id', ['hash'=>password_hash($temporary, PASSWORD_DEFAULT),'updated_at'=>now_utc(),'id'=>$id]);
        $this->revokeSessions($id);
        return $temporary;
    }

    public function deleteUser(int $id): void
    {
        $user = $this->findUser($id);
        if (!$user) throw new \InvalidArgumentException('USER_NOT_FOUND');
        if (!empty($user['is_active'])) throw new \InvalidArgumentException('USER_MUST_BE_INACTIVE_BEFORE_DELETE');
        if ($this->isLastActiveSuperAdmin($id)) throw new \InvalidArgumentException('LAST_SUPER_ADMIN');
        $this->revokeSessions($id);
        $this->db->run('DELETE FROM iam_users WHERE id=:id', ['id'=>$id]);
    }

    /** @return list<array<string,mixed>> */

    public function prepareTotp(int $userId, string $issuer = 'DEC CMS'): array
    {
        $user = $this->findUser($userId);
        if (!$user) throw new \InvalidArgumentException('USER_NOT_FOUND');
        $secret = TotpService::generateSecret();
        return [
            'user_id' => $userId,
            'email' => (string) $user['email'],
            'mode' => 'totp',
            'issuer' => $issuer,
            'secret' => $secret,
            'manual_entry_key' => $secret,
            'otpauth_uri' => TotpService::otpauthUri($issuer, (string) $user['email'], $secret),
            'qr_payload' => TotpService::otpauthUri($issuer, (string) $user['email'], $secret),
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ];
    }

    public function enableTotp(int $userId, string $secret = '', string $code = '', bool $required = true, string $appKey = ''): array
    {
        if (!$this->findUser($userId)) throw new \InvalidArgumentException('USER_NOT_FOUND');
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '' || !TotpService::verifyCode($secret, $code, 1)) {
            throw new \InvalidArgumentException('INVALID_TOTP_CODE');
        }
        $now = now_utc();
        $this->db->run('UPDATE iam_users SET login_mode=:login_mode, totp_enabled=1, totp_required=:required, totp_secret_protected=:secret, totp_recovery_codes_json=NULL, totp_enabled_at=:enabled_at, updated_at=:updated_at WHERE id=:id', [
            'login_mode' => 'totp',
            'required' => $required ? 1 : 0,
            'secret' => TotpService::encryptSecret($secret, $appKey),
            'enabled_at' => $now,
            'updated_at' => $now,
            'id' => $userId,
        ]);
        $this->db->run('DELETE FROM iam_email_2fa_challenges WHERE user_id=:id', ['id' => $userId]);
        $this->revokeSessions($userId);
        return ['user' => $this->findUser($userId) ?? [], 'recovery_codes' => []];
    }

    public function disableTotp(int $userId): array
    {
        return $this->setLoginMode($userId, 'password');
    }

    public function regenerateTotpRecoveryCodes(int $userId): array
    {
        $user = $this->findUser($userId);
        if (!$user) throw new \InvalidArgumentException('USER_NOT_FOUND');
        if (($user['login_mode'] ?? 'password') !== 'totp') throw new \InvalidArgumentException('TOTP_NOT_ENABLED');
        return ['user' => $this->findUser($userId) ?? [], 'recovery_codes' => []];
    }

    public function setLoginMode(int $userId, string $mode): array
    {
        if (!$this->findUser($userId)) throw new \InvalidArgumentException('USER_NOT_FOUND');
        $mode = self::sanitizeLoginMode($mode);
        $now = now_utc();
        $this->db->run('UPDATE iam_users SET login_mode=:login_mode, totp_enabled=:totp_enabled, totp_required=:totp_required, totp_secret_protected=NULL, totp_recovery_codes_json=NULL, totp_enabled_at=:totp_enabled_at, updated_at=:updated_at WHERE id=:id', [
            'login_mode' => $mode,
            'totp_enabled' => $mode === 'password' ? 0 : 1,
            'totp_required' => $mode === 'totp' ? 1 : 0,
            'totp_enabled_at' => $mode === 'password' ? null : $now,
            'updated_at' => $now,
            'id' => $userId,
        ]);
        if ($mode !== 'email_code') {
            $this->db->run('DELETE FROM iam_email_2fa_challenges WHERE user_id=:id', ['id' => $userId]);
        }
        $this->revokeSessions($userId);
        return $this->findUser($userId) ?? [];
    }

    public function enableEmailCodeLogin(int $userId): array
    {
        return $this->setLoginMode($userId, 'email_code');
    }

    public function permissions(): array
    {
        return $this->db->all('SELECT id,permission_key,name,description FROM iam_permissions ORDER BY permission_key');
    }

    /** @return list<array<string,mixed>> */
    public function roles(): array
    {
        $rows = $this->db->all('SELECT id,role_key,name,description FROM iam_roles ORDER BY role_key');
        return array_map(function($r) {
            $r['permission_ids'] = array_map('intval', array_column($this->db->all('SELECT permission_id FROM iam_role_permissions WHERE role_id=:id ORDER BY permission_id', ['id'=>(int)$r['id']]), 'permission_id'));
            $r['is_system'] = in_array($r['role_key'], ['super_admin','admin','editor','translator','publication','seo','user'], true);
            return $r;
        }, $rows);
    }

    public function createRole(array $data): array
    {
        $this->db->run('INSERT INTO iam_roles(role_key,name,description) VALUES(:role_key,:name,:description)', ['role_key'=>self::roleKey((string)$data['role_key']),'name'=>self::limit(trim((string)$data['name']),120),'description'=>self::limit(trim((string)($data['description'] ?? '')),500)]);
        $id = $this->db->lastInsertId();
        $this->replaceRolePermissions($id, $data['permission_ids'] ?? []);
        return $this->role($id) ?? [];
    }

    public function updateRole(int $id, array $data): array
    {
        $role = $this->role($id);
        if (!$role) throw new \InvalidArgumentException('ROLE_NOT_FOUND');
        $newKey = self::roleKey((string)($data['role_key'] ?? $role['role_key']));
        if ((bool)$role['is_system'] && $newKey !== $role['role_key']) throw new \InvalidArgumentException('SYSTEM_ROLE_KEY_LOCKED');
        $this->db->run('UPDATE iam_roles SET role_key=:role_key,name=:name,description=:description WHERE id=:id', ['role_key'=>$newKey,'name'=>self::limit(trim((string)($data['name'] ?? $role['name'])),120),'description'=>self::limit(trim((string)($data['description'] ?? '')),500),'id'=>$id]);
        if (array_key_exists('permission_ids',$data)) $this->replaceRolePermissions($id, $data['permission_ids']);
        return $this->role($id) ?? [];
    }

    public function role(int $id): ?array
    {
        foreach ($this->roles() as $role) if ((int)$role['id'] === $id) return $role;
        return null;
    }

    public function listSessions(array $filters): array
    {
        $where=[];$params=[];
        if (!empty($filters['user_id'])) {$where[]='s.user_id=:user_id';$params['user_id']=(int)$filters['user_id'];}
        if (($filters['status'] ?? '') === 'active') $where[]='s.expires_at > CURRENT_TIMESTAMP';
        if (($filters['status'] ?? '') === 'expired') $where[]='s.expires_at <= CURRENT_TIMESTAMP';
        $clause=$where?'WHERE '.implode(' AND ',$where):'';
        $total=(int)($this->db->one("SELECT count(*) c FROM iam_sessions s {$clause}",$params)['c']??0);
        $limit=max(1,min(100,(int)($filters['limit']??50)));$offset=max(0,(int)($filters['offset']??0));
        $rows=$this->db->all("SELECT s.id,s.user_id,u.email,s.ip_address,s.user_agent,s.last_seen_at,s.expires_at,s.created_at,CASE WHEN s.expires_at > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS is_active FROM iam_sessions s JOIN iam_users u ON u.id=s.user_id {$clause} ORDER BY s.last_seen_at DESC, s.id DESC LIMIT :limit OFFSET :offset", $params+['limit'=>$limit,'offset'=>$offset]);
        return ['rows'=>array_map(fn($r)=>['id'=>(int)$r['id'],'user_id'=>(int)$r['user_id'],'email'=>(string)$r['email'],'ip_address'=>(string)($r['ip_address']??''),'user_agent'=>(string)($r['user_agent']??''),'last_seen_at'=>$r['last_seen_at']??null,'expires_at'=>(string)$r['expires_at'],'created_at'=>(string)$r['created_at'],'is_active'=>(bool)$r['is_active']],$rows),'total'=>$total];
    }

    public function revokeSession(int $sessionId): void { $this->db->run('DELETE FROM iam_sessions WHERE id=:id', ['id'=>$sessionId]); }
    public function revokeSessions(int $userId): void { $this->db->run('DELETE FROM iam_sessions WHERE user_id=:user_id', ['user_id'=>$userId]); }

    public function auditLogs(array $filters): array
    {
        $where=[];$params=[];
        if (!empty($filters['actor_user_id'])) {$where[]='a.actor_user_id=:actor';$params['actor']=(int)$filters['actor_user_id'];}
        if (!empty($filters['action_key'])) {$where[]='a.action_key LIKE :action';$params['action']='%'.(string)$filters['action_key'].'%';}
        if (!empty($filters['resource_type'])) {$where[]='a.resource_type=:resource_type';$params['resource_type']=(string)$filters['resource_type'];}
        $clause=$where?'WHERE '.implode(' AND ',$where):'';
        $total=(int)($this->db->one("SELECT count(*) c FROM iam_audit_logs a {$clause}",$params)['c']??0);
        $limit=max(1,min(200,(int)($filters['limit']??50)));$offset=max(0,(int)($filters['offset']??0));
        $rows=$this->db->all("SELECT a.*, u.email AS actor_email FROM iam_audit_logs a LEFT JOIN iam_users u ON u.id=a.actor_user_id {$clause} ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset", $params+['limit'=>$limit,'offset'=>$offset]);
        return ['rows'=>array_map(fn($r)=>['id'=>(int)$r['id'],'actor_user_id'=>isset($r['actor_user_id'])?(int)$r['actor_user_id']:null,'actor_email'=>$r['actor_email']??null,'action_key'=>(string)$r['action_key'],'resource_type'=>$r['resource_type']??null,'resource_id'=>isset($r['resource_id'])?(int)$r['resource_id']:null,'context'=>json_decode((string)($r['context_json']??'{}'),true) ?: [],'ip_address'=>(string)($r['ip_address']??''),'user_agent'=>(string)($r['user_agent']??''),'created_at'=>(string)$r['created_at']],$rows),'total'=>$total];
    }


    private function scopedSiteRoles(array $siteRoles, int $siteId): array
    {
        $rows = [];
        foreach ($siteRoles as $row) {
            $roleId = (int)($row['role_id'] ?? 0);
            if ($roleId > 0) $rows[] = ['site_id'=>$siteId, 'role_id'=>$roleId];
        }
        return $rows;
    }

    private function replaceUserRoles(int $userId, array $roleIds, array $siteRoles): void
    {
        $this->db->run('DELETE FROM iam_user_roles WHERE user_id=:id',['id'=>$userId]);
        foreach (array_unique(array_map('intval',$roleIds)) as $roleId) if ($roleId>0) $this->db->run('INSERT OR IGNORE INTO iam_user_roles(user_id,role_id) VALUES(:u,:r)',['u'=>$userId,'r'=>$roleId]);
        $this->db->run('DELETE FROM iam_user_site_roles WHERE user_id=:id',['id'=>$userId]);
        foreach ($siteRoles as $row) {
            $siteId=(int)($row['site_id']??0); $roleId=(int)($row['role_id']??0);
            if ($siteId>0 && $roleId>0) $this->db->run('INSERT OR IGNORE INTO iam_user_site_roles(user_id,site_id,role_id) VALUES(:u,:s,:r)',['u'=>$userId,'s'=>$siteId,'r'=>$roleId]);
        }
    }
    private function replaceRolePermissions(int $roleId, array $permissionIds): void
    {
        $this->db->run('DELETE FROM iam_role_permissions WHERE role_id=:id',['id'=>$roleId]);
        foreach (array_unique(array_map('intval',$permissionIds)) as $pid) if ($pid>0) $this->db->run('INSERT OR IGNORE INTO iam_role_permissions(role_id,permission_id) VALUES(:r,:p)',['r'=>$roleId,'p'=>$pid]);
    }

    private function isLastActiveSuperAdmin(int $userId): bool
    {
        if (!$this->userHasSuperAdminRole($userId)) return false;
        return $this->activeSuperAdminCount($userId) === 0;
    }

    private function wouldLeaveNoActiveSuperAdmin(int $userId, bool $nextActive, array $nextRoleIds, array $nextSiteRoles): bool
    {
        $hasSuperAdminBefore = $this->userHasSuperAdminRole($userId);
        $hasSuperAdminAfterUpdate = $this->roleListContainsSuperAdmin($nextRoleIds) || $this->siteRoleListContainsSuperAdmin($nextSiteRoles);
        if (!$hasSuperAdminBefore && !$hasSuperAdminAfterUpdate) return false;
        if ($nextActive && $hasSuperAdminAfterUpdate) return false;
        return $this->activeSuperAdminCount($userId) === 0;
    }

    private function activeSuperAdminCount(?int $excludingUserId = null): int
    {
        $params = [];
        $exclude = '';
        if ($excludingUserId !== null) {
            $exclude = 'AND u.id <> :user_id';
            $params['user_id'] = $excludingUserId;
        }
        $sql = "SELECT count(DISTINCT u.id) AS c
            FROM iam_users u
            LEFT JOIN iam_user_roles ur ON ur.user_id = u.id
            LEFT JOIN iam_roles gr ON gr.id = ur.role_id
            LEFT JOIN iam_user_site_roles usr ON usr.user_id = u.id
            LEFT JOIN iam_roles sr ON sr.id = usr.role_id
            WHERE u.is_active = 1 AND (gr.role_key = 'super_admin' OR sr.role_key = 'super_admin') {$exclude}";
        return (int)($this->db->one($sql, $params)['c'] ?? 0);
    }

    private function userHasSuperAdminRole(int $userId): bool
    {
        return (bool)$this->db->one("SELECT 1 AS ok
            FROM iam_users u
            LEFT JOIN iam_user_roles ur ON ur.user_id = u.id
            LEFT JOIN iam_roles gr ON gr.id = ur.role_id
            LEFT JOIN iam_user_site_roles usr ON usr.user_id = u.id
            LEFT JOIN iam_roles sr ON sr.id = usr.role_id
            WHERE u.id = :id AND (gr.role_key = 'super_admin' OR sr.role_key = 'super_admin') LIMIT 1", ['id'=>$userId]);
    }

    private function roleListContainsSuperAdmin(array $roleIds): bool
    {
        foreach (array_unique(array_map('intval', $roleIds)) as $roleId) {
            if ($roleId > 0 && $this->roleKeyById($roleId) === 'super_admin') return true;
        }
        return false;
    }

    private function siteRoleListContainsSuperAdmin(array $siteRoles): bool
    {
        foreach ($siteRoles as $row) {
            $roleId = (int)($row['role_id'] ?? 0);
            if ($roleId > 0 && $this->roleKeyById($roleId) === 'super_admin') return true;
        }
        return false;
    }

    private function roleKeyById(int $roleId): string
    {
        $row = $this->db->one('SELECT role_key FROM iam_roles WHERE id=:id LIMIT 1', ['id'=>$roleId]);
        return (string)($row['role_key'] ?? '');
    }
    private function globalRoleIds(int $userId): array { return array_map('intval', array_column($this->db->all('SELECT role_id FROM iam_user_roles WHERE user_id=:id ORDER BY role_id',['id'=>$userId]), 'role_id')); }
    private function siteRoleRows(int $userId): array { return array_map(fn($r)=>['site_id'=>(int)$r['site_id'],'role_id'=>(int)$r['role_id']], $this->db->all('SELECT site_id,role_id FROM iam_user_site_roles WHERE user_id=:id ORDER BY site_id,role_id',['id'=>$userId])); }
    private function userSummary(array $r): array { $first=(string)($r['first_name']??'');$last=(string)($r['last_name']??'');$id=(int)$r['id'];$mode=self::sanitizeLoginMode((string)($r['login_mode']??(!empty($r['totp_enabled'])?'email_code':'password')));return ['id'=>$id,'email'=>(string)$r['email'],'first_name'=>$first,'last_name'=>$last,'name'=>trim($first.' '.$last) ?: (string)$r['email'],'locale'=>(string)($r['locale']??'fr-CH'),'is_active'=>(bool)$r['is_active'],'disabled_at'=>$r['disabled_at']??null,'disabled_reason'=>$r['disabled_reason']??null,'last_login_at'=>$r['last_login_at']??null,'active_session_count'=>(int)($r['active_session_count']??0),'login_mode'=>$mode,'email_code_enabled'=>$mode==='email_code','totp_enabled'=>$mode==='totp','totp_required'=>$mode==='totp','totp_enabled_at'=>$r['totp_enabled_at']??null,'roles'=>$this->globalRoleIds($id),'role_ids'=>$this->globalRoleIds($id),'site_roles'=>$this->siteRoleRows($id),'created_at'=>(string)($r['created_at']??''),'updated_at'=>(string)($r['updated_at']??'')]; }
    private static function sanitizeLoginMode(string $mode): string { if (in_array($mode, ['password','email_code','totp'], true)) return $mode; throw new \InvalidArgumentException('INVALID_LOGIN_MODE'); }
    private static function normalize(string $email): string { $email=trim($email); return function_exists('mb_strtolower')?mb_strtolower($email):strtolower($email); }
    private static function limit(string $v,int $m): string { $v=str_replace("\0",'',$v); return function_exists('mb_substr')?mb_substr($v,0,$m):substr($v,0,$m); }
    private static function roleKey(string $v): string { $v=strtolower(trim($v)); if(!preg_match('/^[a-z][a-z0-9_]{1,63}$/',$v)) throw new \InvalidArgumentException('INVALID_ROLE_KEY'); return $v; }
    private static function temporaryPassword(): string { return 'Temp-' . bin2hex(random_bytes(4)) . '-' . random_int(1000,9999); }
}
