<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Iam\IamAdminRepository;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Security\Authorization;

final class IamAdminApiController
{
    public function __construct(private readonly Request $request, private readonly AuthRepository $auth, private readonly Authorization $authorization, private readonly IamAdminRepository $iam) {}

    public function sites(): Response
    {
        $this->requireIamPermission('users.read');
        $siteId = $this->requestedSiteId();
        return Response::success(['sites'=>$this->iam->sites($this->isSiteScopedRequest($siteId) ? $siteId : null)], 'admin.iam.sites.index.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function users(): Response
    {
        $this->requireIamPermission('users.read');
        $filters = $this->request->query;
        $siteId = $this->requestedSiteId();
        if ($this->isSiteScopedRequest($siteId)) {
            $this->authorization->requireSiteAccess($siteId);
            $filters['site_id'] = $siteId;
            $filters['strict_site_scope'] = true;
        }
        $result = $this->iam->listUsers($filters);
        return $this->paged(['users'=>$result['rows']], 'admin.iam.users.index.v1', $result['total']);
    }

    public function showUser(int $id): Response
    {
        $this->requireIamPermission('users.read');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $user = $this->iam->findUser($id);
        if (!$user) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        return Response::success(['user'=>$user], 'admin.iam.users.show.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function storeUser(): Response
    {
        $this->requireIamPermission('users.manage');
        $payload = AdminApiContract::dataPayload($this->request);
        $errors = $this->validateUser($payload, true);
        if ($errors) return AdminApiContract::validationResponse($errors, 'Utilisateur invalide.');
        $siteId = $this->requestedSiteId();
        try { $user = $this->isSiteScopedRequest($siteId) ? $this->iam->createUserScoped($payload, $siteId) : $this->iam->createUser($payload); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.created', 'iam_user', (int)$user['id'], ['email'=>$user['email']]);
        return Response::success(['user'=>$user,'message'=>'Utilisateur créé.'], 'admin.iam.users.show.v1', ['contract_version'=>AdminApiContract::VERSION], 201);
    }

    public function updateUser(int $id): Response
    {
        $this->requireIamPermission('users.manage');
        $payload = AdminApiContract::dataPayload($this->request);
        $errors = $this->validateUser($payload, false);
        if ($errors) return AdminApiContract::validationResponse($errors, 'Utilisateur invalide.');
        $siteId = $this->requestedSiteId();
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        try { $user = $this->isSiteScopedRequest($siteId) ? $this->iam->updateUserScoped($id, $payload, $siteId) : $this->iam->updateUser($id, $payload); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.updated', 'iam_user', $id);
        return Response::success(['user'=>$user,'message'=>'Utilisateur mis à jour.'], 'admin.iam.users.show.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }


    public function destroyUser(int $id): Response
    {
        $this->requireIamPermission('users.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        try { $this->iam->deleteUser($id); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.deleted', 'iam_user', $id);
        return Response::success(['message'=>'Utilisateur supprimé définitivement.'], 'admin.iam.users.destroy.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function activateUser(int $id): Response
    {
        $this->requireIamPermission('users.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $user = $this->iam->setActive($id, true);
        $this->audit('iam.user.activated', 'iam_user', $id);
        return Response::success(['user'=>$user,'message'=>'Utilisateur activé.'], 'admin.iam.users.show.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function deactivateUser(int $id): Response
    {
        $this->requireIamPermission('users.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $user = $this->iam->setActive($id, false, (string)($payload['reason'] ?? ''));
        $this->audit('iam.user.deactivated', 'iam_user', $id, ['reason'=>(string)($payload['reason'] ?? '')]);
        return Response::success(['user'=>$user,'message'=>'Utilisateur désactivé et sessions révoquées.'], 'admin.iam.users.show.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function resetPassword(int $id): Response
    {
        $this->requireIamPermission('users.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $temporary = $this->iam->resetPassword($id);
        $this->audit('iam.user.password_reset', 'iam_user', $id);
        return Response::success(['temporary_password'=>$temporary,'expires_policy'=>'À transmettre hors CMS puis à changer à la première connexion selon politique projet.'], 'admin.iam.users.reset_password.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function loginMode(int $id): Response
    {
        $this->requireIamPermission('users.read');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $user = $this->iam->findUser($id);
        if (!$user) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        return Response::success(['user_id'=>$id,'login_mode'=>$user['login_mode'] ?? 'password','user'=>$user], 'admin.iam.users.login_mode.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function changeLoginMode(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $mode = (string)($payload['login_mode'] ?? $payload['mode'] ?? '');
        if ($mode === 'totp') {
            return Response::error('VALIDATION_FAILED', 'Préparez puis confirmez un code TOTP avant activation.', 422, ['fields'=>['login_mode'=>['Le mode TOTP exige une confirmation de code.']]]);
        }
        try { $user = $mode === 'email_code' ? $this->iam->enableEmailCodeLogin($id) : $this->iam->setLoginMode($id, 'password'); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.login_mode_changed', 'iam_user', $id, ['login_mode'=>$user['login_mode'] ?? $mode]);
        return Response::success(['user'=>$user,'login_mode'=>$user['login_mode'] ?? $mode,'message'=>'Mode de connexion mis à jour et sessions révoquées.'], 'admin.iam.users.login_mode.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function prepareLoginModeTotp(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $issuer = trim((string)($payload['issuer'] ?? 'DEC CMS')) ?: 'DEC CMS';
        try { $setup = $this->iam->prepareTotp($id, $issuer); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.totp_prepare', 'iam_user', $id);
        return Response::success(['totp'=>$setup,'message'=>'Scannez le QR/payload ou saisissez le secret, puis confirmez un code TOTP.'], 'admin.iam.users.login_mode.totp.prepare.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function confirmLoginModeTotp(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        try { $result = $this->iam->enableTotp($id, (string)($payload['secret'] ?? ''), (string)($payload['code'] ?? ''), true, $this->auth->totpAppKey()); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.login_mode_changed', 'iam_user', $id, ['login_mode'=>'totp']);
        return Response::success(['user'=>$result['user'],'login_mode'=>'totp','recovery_codes'=>$result['recovery_codes'],'message'=>'Mode mot de passe + application TOTP activé et sessions révoquées. Copiez les codes de récupération maintenant.'], 'admin.iam.users.login_mode.totp.confirm.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function disableLoginModeTotp(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        try { $user = $this->iam->setLoginMode($id, 'password'); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.login_mode_changed', 'iam_user', $id, ['login_mode'=>'password','previous'=>'totp']);
        return Response::success(['user'=>$user,'login_mode'=>'password','message'=>'TOTP désactivé, retour au mot de passe classique et sessions révoquées.'], 'admin.iam.users.login_mode.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function rotateLoginModeTotp(int $id): Response
    {
        return $this->prepareLoginModeTotp($id);
    }


    public function prepareTotp(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $issuer = trim((string)($payload['issuer'] ?? 'DEC CMS')) ?: 'DEC CMS';
        $setup = $this->iam->prepareTotp($id, $issuer);
        $this->audit('iam.user.totp_prepare', 'iam_user', $id);
        return Response::success(['totp'=>$setup,'message'=>'Secret TOTP généré. Confirmez un premier code valide pour activer le mode mot de passe + application TOTP.'], 'admin.iam.users.totp.prepare.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function enableTotp(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $mode = (string)($payload['mode'] ?? '');
        try {
            if ($mode === 'email' || $mode === 'email_code') {
                $user = $this->iam->enableEmailCodeLogin($id);
                $result = ['user'=>$user, 'recovery_codes'=>[]];
            } else {
                $result = $this->iam->enableTotp($id, (string)($payload['secret'] ?? ''), (string)($payload['code'] ?? ''), array_key_exists('required', $payload) ? !empty($payload['required']) : true, $this->auth->totpAppKey());
            }
        }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $loginMode = (string)($result['user']['login_mode'] ?? 'totp');
        $this->audit('iam.user.login_mode_changed', 'iam_user', $id, ['login_mode'=>$loginMode,'compat_endpoint'=>'totp.enable']);
        $message = $loginMode === 'email_code'
            ? 'Connexion par code email activée. Au prochain login, un code sera envoyé par email et aucun mot de passe ne sera demandé.'
            : 'Mode mot de passe + application TOTP activé et sessions révoquées.';
        return Response::success(['user'=>$result['user'],'recovery_codes'=>$result['recovery_codes'],'message'=>$message], 'admin.iam.users.totp.enable.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function disableTotp(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        try { $user = $this->iam->disableTotp($id); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.login_mode_changed', 'iam_user', $id, ['login_mode'=>'password','compat_endpoint'=>'totp.disable']);
        return Response::success(['user'=>$user,'message'=>'Mode mot de passe classique activé et sessions révoquées.'], 'admin.iam.users.totp.disable.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function regenerateTotpRecoveryCodes(int $id): Response
    {
        $this->requireIamPermission('users.email_2fa.manage');
        if (!$this->ensureScopedUserAccess($id)) return Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
        try { $result = $this->iam->regenerateTotpRecoveryCodes($id, $this->auth->totpAppKey()); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.user.totp_recovery_regenerated', 'iam_user', $id);
        return Response::success(['user'=>$result['user'],'recovery_codes'=>$result['recovery_codes'],'message'=>'Nouveaux codes de récupération générés. Les anciens codes sont invalidés.'], 'admin.iam.users.totp.recovery.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function roles(): Response
    {
        $this->requireIamPermission('roles.read');
        $siteId = $this->requestedSiteId();
        if ($this->isSiteScopedRequest($siteId) && (string)($this->request->query['mode'] ?? '') !== 'assignment') {
            return Response::error('GLOBAL_ROLES_NOT_AVAILABLE_FROM_SUBSITE', 'Les rôles globaux ne sont pas administrables depuis un sous-site.', 403);
        }
        return Response::success(['roles'=>$this->iam->roles(),'permissions'=>$this->iam->permissions()], 'admin.iam.roles.index.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function storeRole(): Response
    {
        $this->requireIamPermission('roles.manage');
        $payload = AdminApiContract::dataPayload($this->request);
        $errors = $this->validateRole($payload, true);
        if ($errors) return AdminApiContract::validationResponse($errors, 'Rôle invalide.');
        try { $role = $this->iam->createRole($payload); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.role.created', 'iam_role', (int)$role['id'], ['role_key'=>$role['role_key']]);
        return Response::success(['role'=>$role,'message'=>'Rôle créé.'], 'admin.iam.roles.show.v1', ['contract_version'=>AdminApiContract::VERSION], 201);
    }

    public function updateRole(int $id): Response
    {
        $this->requireIamPermission('roles.manage');
        $payload = AdminApiContract::dataPayload($this->request);
        $errors = $this->validateRole($payload, false);
        if ($errors) return AdminApiContract::validationResponse($errors, 'Rôle invalide.');
        try { $role = $this->iam->updateRole($id, $payload); }
        catch (\Throwable $e) { return $this->validationFromException($e); }
        $this->audit('iam.role.updated', 'iam_role', $id, ['role_key'=>$role['role_key']]);
        return Response::success(['role'=>$role,'message'=>'Rôle mis à jour.'], 'admin.iam.roles.show.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function permissions(): Response
    {
        $this->requireIamPermission('roles.read');
        return Response::success(['permissions'=>$this->iam->permissions()], 'admin.iam.permissions.index.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function sessions(): Response
    {
        $this->requireIamPermission('sessions.read');
        $result = $this->iam->listSessions($this->request->query);
        return $this->paged(['sessions'=>$result['rows']], 'admin.iam.sessions.index.v1', $result['total']);
    }

    public function revokeSession(int $id): Response
    {
        $this->requireIamPermission('sessions.manage');
        $this->iam->revokeSession($id);
        $this->audit('iam.session.revoked', 'iam_session', $id);
        return Response::success(['message'=>'Session révoquée.'], 'admin.iam.sessions.revoke.v1', ['contract_version'=>AdminApiContract::VERSION]);
    }

    public function auditLogs(): Response
    {
        $this->requireIamPermission('audit.read');
        $result = $this->iam->auditLogs($this->request->query);
        return $this->paged(['audit_logs'=>$result['rows']], 'admin.iam.audit.index.v1', $result['total']);
    }



    private function requestedSiteId(): int
    {
        return isset($this->request->query['site_id']) ? max(0, (int) $this->request->query['site_id']) : 0;
    }

    private function isSiteScopedRequest(int $siteId): bool
    {
        return $siteId > 0 && $this->auth->authorizedSiteIds() !== [];
    }

    private function ensureScopedUserAccess(int $userId): bool
    {
        $siteId = $this->requestedSiteId();
        if (!$this->isSiteScopedRequest($siteId)) return true;
        $this->authorization->requireSiteAccess($siteId);
        return $this->iam->userBelongsToSite($userId, $siteId);
    }

    private function requireIamPermission(string $permission): void
    {
        $siteId = isset($this->request->query['site_id']) ? max(0, (int) $this->request->query['site_id']) : 0;
        if ($siteId > 0 && $this->auth->hasPermission($permission, $siteId)) {
            return;
        }
        $this->authorization->require($permission);
    }

    private function paged(array $data, string $contract, int $total): Response
    {
        $limit = max(1, min(200, (int)($this->request->query['limit'] ?? 50)));
        $offset = max(0, (int)($this->request->query['offset'] ?? 0));
        return Response::success($data, $contract, ['contract_version'=>AdminApiContract::VERSION,'pagination'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset,'has_more'=>$offset+$limit<$total]]);
    }

    private function audit(string $action, string $resourceType, ?int $resourceId=null, array $context=[]): void
    {
        $this->auth->audit((int)($this->auth->user()['id'] ?? 0) ?: null, $action, $resourceType, $resourceId, $context);
    }

    private function validateUser(array $p, bool $creating): array
    {
        $e=[]; $email=trim((string)($p['email'] ?? ''));
        if ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) $e['email'][]='Adresse email invalide.';
        if ($creating && trim((string)($p['password'] ?? ''))==='') $e['password'][]='Mot de passe initial obligatoire.';
        if (isset($p['password']) && strlen((string)$p['password'])<12) $e['password'][]='Minimum 12 caractères.';
        foreach (['role_ids','site_roles'] as $k) if (isset($p[$k]) && !is_array($p[$k])) $e[$k][]='Liste attendue.';
        if ($creating) {
            $roleIds = is_array($p['role_ids'] ?? null) ? array_filter(array_map('intval', $p['role_ids'])) : [];
            $siteRoles = is_array($p['site_roles'] ?? null) ? array_filter($p['site_roles'], static fn($row): bool => is_array($row) && (int)($row['site_id'] ?? 0) > 0 && (int)($row['role_id'] ?? 0) > 0) : [];
            if ($roleIds === [] && $siteRoles === []) {
                $e['roles'][] = 'Attribuez au moins un rôle global ou un accès par site avant de créer le compte.';
            }
        }
        return $e;
    }

    private function validateRole(array $p, bool $creating): array
    {
        $e=[]; if ($creating && trim((string)($p['role_key'] ?? ''))==='') $e['role_key'][]='Clé obligatoire.';
        if (isset($p['role_key']) && !preg_match('/^[a-z][a-z0-9_]{1,63}$/', (string)$p['role_key'])) $e['role_key'][]='Format attendu : lettres minuscules, chiffres et underscores.';
        if (trim((string)($p['name'] ?? ''))==='') $e['name'][]='Nom obligatoire.';
        if (isset($p['permission_ids']) && !is_array($p['permission_ids'])) $e['permission_ids'][]='Liste attendue.';
        return $e;
    }

    private function validationFromException(\Throwable $e): Response
    {
        return match ($e->getMessage()) {
            'EMAIL_ALREADY_USED' => AdminApiContract::validationResponse(['email'=>['Cette adresse email est déjà utilisée.']], 'Utilisateur invalide.'),
            'USER_NOT_FOUND' => Response::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404),
            'ROLE_NOT_FOUND' => Response::error('ROLE_NOT_FOUND', 'Rôle introuvable.', 404),
            'SYSTEM_ROLE_KEY_LOCKED' => AdminApiContract::validationResponse(['role_key'=>['La clé d’un rôle système est verrouillée.']], 'Rôle système protégé.'),
            'LAST_SUPER_ADMIN' => Response::error('LAST_SUPER_ADMIN', 'Action interdite : il doit rester au moins un super admin actif.', 409),
            'USER_MUST_BE_INACTIVE_BEFORE_DELETE' => Response::error('USER_MUST_BE_INACTIVE_BEFORE_DELETE', 'L’utilisateur doit être archivé ou inactif avant suppression définitive.', 409),
            'USER_ROLE_REQUIRED' => AdminApiContract::validationResponse(['roles'=>['Attribuez au moins un rôle global ou un accès par site avant de créer le compte.']], 'Utilisateur invalide.'),
            'INVALID_ROLE_KEY' => AdminApiContract::validationResponse(['role_key'=>['Clé de rôle invalide.']], 'Rôle invalide.'),
            'INVALID_TOTP_CODE' => AdminApiContract::validationResponse(['code'=>['Code 2FA invalide.']], 'Code 2FA invalide.'),
            'INVALID_LOGIN_MODE' => AdminApiContract::validationResponse(['login_mode'=>['Mode de connexion invalide.']], 'Utilisateur invalide.'),
            'TOTP_NOT_ENABLED' => Response::error('TOTP_NOT_ENABLED', 'La 2FA n’est pas activée pour cet utilisateur.', 409),
            default => throw $e,
        };
    }
}
