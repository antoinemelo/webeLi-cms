<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Security\Authorization;
use App\Service\Webhook\WebhookHttpClient;
use App\Service\Webhook\WebhookSigner;

final class SecurityAdminApiController
{
    private const CONTRACT = 'admin.security_api.v1';

    public function __construct(
        private readonly Request $request,
        private readonly Database $coreDb,
        private readonly Database $iamDb,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
    ) {}

    public function index(): Response
    {
        $this->requireAnySecurityPermission();
        $siteId = max(0, (int) ($this->request->query['site_id'] ?? 0));
        $canTokens = $this->can('security.tokens.read') || $this->can('security.tokens.manage');
        $canWebhooks = $this->can('security.webhooks.read') || $this->can('security.webhooks.manage');
        $canCors = $this->can('security.cors.read') || $this->can('security.cors.manage');
        return Response::success([
            'selected_site_id' => $siteId,
            'sites' => $this->sites(),
            'permissions' => [
                'security.tokens.read' => $canTokens,
                'security.tokens.manage' => $this->can('security.tokens.manage'),
                'security.webhooks.read' => $canWebhooks,
                'security.webhooks.manage' => $this->can('security.webhooks.manage'),
                'security.cors.read' => $canCors,
                'security.cors.manage' => $this->can('security.cors.manage'),
            ],
            'tokens' => $canTokens ? $this->tokens($siteId) : [],
            'webhooks' => $canWebhooks ? $this->webhooks($siteId) : [],
            'cors' => $canCors ? ($siteId > 0 ? [$this->corsForSite($siteId)] : $this->corsSettings()) : [],
            'webhook_stats' => $canWebhooks ? $this->webhookStats($siteId) : ['pending' => 0, 'failed' => 0, 'succeeded' => 0, 'total' => 0],
            'available_scopes' => ['headless:read', 'routes:read', 'content:read', 'media:read', 'search:read', 'menus:read', 'taxonomies:read', 'catalog:read', 'pos.catalog.read', '*'],
            'available_webhook_events' => ['content.published', 'content.unpublished', 'content.updated', '*'],
            'blueprints' => $this->securityBlueprints(),
        ], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function createToken(): Response
    {
        $this->requireSecurityTokensManage();
        $payload = AdminApiContract::dataPayload($this->request);
        $name = $this->limited((string)($payload['name'] ?? ''), 120);
        $siteId = $this->nullableSiteId($payload['site_id'] ?? null);
        if ($name === '') return Response::validation(['name' => ['Nom requis.']], 'Token API invalide.');
        if ($siteId === null) return Response::validation(['site_id' => ['Site requis : les tokens API sont configurés dans le site sélectionné.']], 'Token API invalide.');
        $token = 'amcms_' . bin2hex(random_bytes(32));
        $now = now_utc();
        $this->iamDb->run(
            'INSERT INTO api_tokens(name, token_hash, site_id, scopes, is_active, expires_at, created_at, updated_at) VALUES(:name, :hash, :site_id, :scopes, :active, :expires_at, :created_at, :updated_at)',
            [
                'name' => $name,
                'hash' => hash('sha256', $token),
                'site_id' => $siteId,
                'scopes' => $this->scopeString($payload['scopes'] ?? 'headless:read'),
                'active' => !array_key_exists('is_active', $payload) || !empty($payload['is_active']) ? 1 : 0,
                'expires_at' => $this->nullableDateTime($payload['expires_at'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $id = $this->iamDb->lastInsertId();
        $this->audit('security.token.created', 'api_token', $id, ['name' => $name]);
        return Response::success(['token' => $this->token((int)$id), 'plain_token' => $token, 'message' => 'Token créé. Copiez la valeur maintenant : elle ne sera plus affichée.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION], 201);
    }

    public function updateToken(int $id): Response
    {
        $this->requireSecurityTokensManage();
        $existing = $this->token($id);
        if (!$existing) return Response::error('TOKEN_NOT_FOUND', 'Token introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $this->iamDb->run(
            'UPDATE api_tokens SET name=:name, site_id=:site_id, scopes=:scopes, is_active=:active, expires_at=:expires_at, updated_at=:updated_at WHERE id=:id',
            [
                'name' => $this->limited((string)($payload['name'] ?? $existing['name']), 120) ?: (string)$existing['name'],
                'site_id' => array_key_exists('site_id', $payload) ? $this->nullableSiteId($payload['site_id']) : ($existing['site_id'] ?? null),
                'scopes' => $this->scopeString($payload['scopes'] ?? $existing['scopes']),
                'active' => array_key_exists('is_active', $payload) ? (!empty($payload['is_active']) ? 1 : 0) : (!empty($existing['is_active']) ? 1 : 0),
                'expires_at' => array_key_exists('expires_at', $payload) ? $this->nullableDateTime($payload['expires_at']) : ($existing['expires_at'] ?? null),
                'updated_at' => now_utc(),
                'id' => $id,
            ]
        );
        $this->audit('security.token.updated', 'api_token', $id);
        return Response::success(['token' => $this->token($id), 'message' => 'Token mis à jour.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function deleteToken(int $id): Response
    {
        $this->requireSecurityTokensManage();
        $existing = $this->token($id);
        if (!$existing) return Response::error('TOKEN_NOT_FOUND', 'Token introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $siteId = $this->nullableSiteId($payload['site_id'] ?? ($existing['site_id'] ?? null));
        if ($siteId !== null && isset($existing['site_id']) && (int) $existing['site_id'] !== $siteId) {
            return Response::error('TOKEN_NOT_FOUND', 'Token introuvable pour le site sélectionné.', 404);
        }
        $this->iamDb->run('DELETE FROM api_tokens WHERE id=:id', ['id' => $id]);
        $this->audit('security.token.deleted', 'api_token', $id, ['site_id' => $existing['site_id'] ?? null]);
        return Response::success(['message' => 'Token supprimé.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function testToken(): Response
    {
        $this->requireSecurityTokensManage();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $plain = trim((string) ($payload['token'] ?? $payload['plain_token'] ?? ''));
        $siteId = $this->nullableSiteId($payload['site_id'] ?? null);
        $requiredScope = trim((string) ($payload['scope'] ?? ''));
        if ($plain === '') {
            return Response::validation(['token' => ['Token brut requis pour le test.']], 'Test de token invalide.');
        }
        $row = $this->iamDb->one('SELECT * FROM api_tokens WHERE token_hash = :hash LIMIT 1', ['hash' => hash('sha256', $plain)]);
        if (!$row) {
            return Response::success(['valid' => false, 'reason' => 'not_found', 'message' => 'Token inconnu.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        }
        if ($siteId !== null && isset($row['site_id']) && (int) $row['site_id'] !== $siteId) {
            return Response::success(['valid' => false, 'reason' => 'site_mismatch', 'token' => $this->tokenRow($row), 'message' => 'Token rattaché à un autre site.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        }
        if (empty($row['is_active'])) {
            return Response::success(['valid' => false, 'reason' => 'inactive', 'token' => $this->tokenRow($row), 'message' => 'Token inactif.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) !== false && strtotime((string) $row['expires_at']) <= time()) {
            return Response::success(['valid' => false, 'reason' => 'expired', 'token' => $this->tokenRow($row), 'message' => 'Token expiré.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        }
        if ($requiredScope !== '' && !$this->tokenHasScope((string) $row['scopes'], $requiredScope)) {
            return Response::success(['valid' => false, 'reason' => 'scope_missing', 'token' => $this->tokenRow($row), 'message' => 'Scope manquant pour ce token.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        }
        $this->iamDb->run('UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id', ['id' => (int) $row['id']]);
        $this->audit('security.token.tested', 'api_token', (int) $row['id'], ['site_id' => $row['site_id'] ?? null, 'scope' => $requiredScope ?: null]);
        return Response::success(['valid' => true, 'token' => $this->token((int) $row['id']), 'message' => 'Token valide. Dernier usage mis à jour.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function createWebhook(): Response
    {
        $this->requireSecurityWebhooksManage();
        $payload = AdminApiContract::dataPayload($this->request);
        $url = trim((string)($payload['url'] ?? ''));
        $siteId = $this->nullableSiteId($payload['site_id'] ?? null);
        if ($siteId === null) return Response::validation(['site_id' => ['Site requis : les webhooks sont configurés dans le site sélectionné.']], 'Webhook invalide.');
        if (!$this->isAllowedWebhookUrl($url)) return Response::validation(['url' => ['URL HTTPS requise, sauf localhost/127.0.0.1 en développement.']], 'Webhook invalide.');
        $secret = trim((string)($payload['secret'] ?? ''));
        if ($secret === '') $secret = bin2hex(random_bytes(24));
        if (strlen($secret) < 16) return Response::validation(['secret' => ['Le secret doit contenir au moins 16 caractères.']], 'Webhook invalide.');
        $now = now_utc();
        $this->coreDb->run(
            'INSERT INTO webhook_endpoints(site_id, name, url, events_json, secret, is_active, max_attempts, next_attempt_at, created_at, updated_at) VALUES(:site_id, :name, :url, :events_json, :secret, :active, :max_attempts, :next_attempt_at, :created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'name' => $this->limited((string)($payload['name'] ?? ''), 120),
                'url' => $url,
                'events_json' => json_encode($this->eventList($payload['events'] ?? ['*']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'secret' => $secret,
                'active' => !array_key_exists('is_active', $payload) || !empty($payload['is_active']) ? 1 : 0,
                'max_attempts' => max(1, min(25, (int)($payload['max_attempts'] ?? 5))),
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $id = $this->coreDb->lastInsertId();
        $this->audit('security.webhook.created', 'webhook_endpoint', $id, ['url' => $url]);
        return Response::success(['webhook' => $this->webhook((int)$id), 'message' => 'Webhook créé.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION], 201);
    }

    public function updateWebhook(int $id): Response
    {
        $this->requireSecurityWebhooksManage();
        $existing = $this->webhook($id);
        if (!$existing) return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $url = trim((string)($payload['url'] ?? $existing['url']));
        if (!$this->isAllowedWebhookUrl($url)) return Response::validation(['url' => ['URL HTTPS requise, sauf localhost/127.0.0.1 en développement.']], 'Webhook invalide.');
        $secret = trim((string)($payload['secret'] ?? $existing['secret']));
        if (strlen($secret) < 16) return Response::validation(['secret' => ['Le secret doit contenir au moins 16 caractères.']], 'Webhook invalide.');
        $this->coreDb->run(
            'UPDATE webhook_endpoints SET site_id=:site_id, name=:name, url=:url, events_json=:events_json, secret=:secret, is_active=:active, max_attempts=:max_attempts, updated_at=:updated_at WHERE id=:id',
            [
                'site_id' => array_key_exists('site_id', $payload) ? $this->nullableSiteId($payload['site_id']) : ($existing['site_id'] ?? null),
                'name' => $this->limited((string)($payload['name'] ?? $existing['name']), 120),
                'url' => $url,
                'events_json' => json_encode($this->eventList($payload['events'] ?? $existing['events']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'secret' => $secret,
                'active' => array_key_exists('is_active', $payload) ? (!empty($payload['is_active']) ? 1 : 0) : (!empty($existing['is_active']) ? 1 : 0),
                'max_attempts' => max(1, min(25, (int)($payload['max_attempts'] ?? $existing['max_attempts']))),
                'updated_at' => now_utc(),
                'id' => $id,
            ]
        );
        $this->audit('security.webhook.updated', 'webhook_endpoint', $id);
        return Response::success(['webhook' => $this->webhook($id), 'message' => 'Webhook mis à jour.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function deleteWebhook(int $id): Response
    {
        $this->requireSecurityWebhooksManage();
        $existing = $this->webhook($id);
        if (!$existing) return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $siteId = $this->nullableSiteId($payload['site_id'] ?? ($existing['site_id'] ?? null));
        if ($siteId !== null && isset($existing['site_id']) && (int) $existing['site_id'] !== $siteId) {
            return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable pour le site sélectionné.', 404);
        }
        // SQLite foreign keys may be disabled in some local/dev contexts. Delete
        // deliveries explicitly before removing the endpoint so the operation is
        // deterministic and does not depend on PRAGMA foreign_keys.
        if ($this->coreDb->tableExists('webhook_deliveries')) {
            $this->coreDb->run('DELETE FROM webhook_deliveries WHERE webhook_id=:id', ['id' => $id]);
        }
        $this->coreDb->run('DELETE FROM webhook_endpoints WHERE id=:id', ['id' => $id]);
        $this->audit('security.webhook.deleted', 'webhook_endpoint', $id, ['site_id' => $existing['site_id'] ?? null]);
        return Response::success(['message' => 'Webhook supprimé.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function webhookDeliveries(int $id): Response
    {
        $this->requireAnySecurityPermission();
        if (!$this->can('security.webhooks.read') && !$this->can('security.webhooks.manage')) {
            $this->authorization->require('security.webhooks.read');
        }
        $existing = $this->webhook($id);
        if (!$existing) return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable.', 404);
        $siteId = $this->nullableSiteId($this->request->query['site_id'] ?? null);
        if ($siteId !== null && isset($existing['site_id']) && (int) $existing['site_id'] !== $siteId) {
            return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable pour le site sélectionné.', 404);
        }
        $limit = max(1, min(100, (int) ($this->request->query['limit'] ?? 30)));
        return Response::success([
            'webhook' => $existing,
            'deliveries' => $this->deliveriesForWebhook($id, $limit),
            'stats' => $this->webhookDeliveryStats($id),
        ], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function pingWebhook(int $id): Response
    {
        $this->requireSecurityWebhooksManage();
        $existing = $this->webhook($id);
        if (!$existing) return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $siteId = $this->nullableSiteId($payload['site_id'] ?? ($existing['site_id'] ?? null));
        if ($siteId !== null && isset($existing['site_id']) && (int) $existing['site_id'] !== $siteId) {
            return Response::error('WEBHOOK_NOT_FOUND', 'Webhook introuvable pour le site sélectionné.', 404);
        }
        $deliveryId = 'wh_ping_' . $id . '_' . bin2hex(random_bytes(8));
        $bodyArray = [
            'id' => $deliveryId,
            'event_id' => null,
            'event' => 'webhook.ping',
            'occurred_at' => now_utc(),
            'data' => [
                'site_id' => $existing['site_id'] ?? null,
                'webhook_id' => $id,
                'source' => 'admin',
                'message' => 'Ping de test depuis l’administration DEC CMS.',
            ],
        ];
        $body = json_encode($bodyArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) $body = '{}';
        $eventId = $this->createSyntheticOutboxEvent('webhook.ping', $bodyArray);
        $deliveryPk = $this->createAdminDelivery($id, $eventId, $deliveryId, 'webhook.ping', $body);
        $headers = [
            'X-AMCMS-Signature' => WebhookSigner::sign($body, (string) $existing['secret']),
            'X-AMCMS-Event' => 'webhook.ping',
            'X-AMCMS-Delivery' => $deliveryId,
            'X-AMCMS-Test' => '1',
        ];
        $response = ['status' => null, 'body' => ''];
        try {
            $response = (new WebhookHttpClient())->postJson((string) $existing['url'], $body, $headers, 10);
            $status = (int) ($response['status'] ?? 0);
            $responseBody = (string) ($response['body'] ?? '');
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Webhook endpoint returned HTTP ' . $status);
            }
            $this->markAdminDeliverySucceeded($deliveryPk, $id, $status, $responseBody);
            $this->audit('security.webhook.ping_succeeded', 'webhook_endpoint', $id, ['status' => $status]);
            return Response::success(['ok' => true, 'delivery' => $this->delivery($deliveryPk), 'message' => 'Ping webhook réussi.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        } catch (\Throwable $e) {
            $status = isset($response['status']) ? (int) $response['status'] : null;
            $responseBody = isset($response['body']) ? (string) $response['body'] : '';
            $this->markAdminDeliveryFailed($deliveryPk, $id, $e->getMessage(), $status, $responseBody);
            $this->audit('security.webhook.ping_failed', 'webhook_endpoint', $id, ['status' => $status, 'error' => $e->getMessage()]);
            return Response::success(['ok' => false, 'delivery' => $this->delivery($deliveryPk), 'message' => 'Ping webhook échoué : ' . $e->getMessage()], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
        }
    }

    public function cleanupWebhookDeliveries(): Response
    {
        $this->requireSecurityWebhooksManage();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $siteId = $this->nullableSiteId($payload['site_id'] ?? null);
        $webhookId = isset($payload['webhook_id']) ? max(0, (int) $payload['webhook_id']) : 0;
        $olderThanDays = max(0, min(3650, (int) ($payload['older_than_days'] ?? 30)));
        $statuses = $this->deliveryStatusList($payload['statuses'] ?? ['succeeded', 'failed']);
        if ($statuses === []) {
            return Response::validation(['statuses' => ['Statut de livraison requis.']], 'Nettoyage invalide.');
        }
        $where = ['status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'];
        $params = $statuses;
        if ($olderThanDays > 0) {
            $where[] = "created_at < datetime('now', ?)";
            $params[] = '-' . $olderThanDays . ' days';
        }
        if ($webhookId > 0) {
            $where[] = 'webhook_id = ?';
            $params[] = $webhookId;
        }
        if ($siteId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM webhook_endpoints w WHERE w.id = webhook_deliveries.webhook_id AND w.site_id = ?)';
            $params[] = $siteId;
        }
        $sql = 'DELETE FROM webhook_deliveries WHERE ' . implode(' AND ', $where);
        $before = $this->coreDb->one('SELECT COUNT(*) AS c FROM webhook_deliveries')['c'] ?? 0;
        $this->coreDb->run($sql, $params);
        $after = $this->coreDb->one('SELECT COUNT(*) AS c FROM webhook_deliveries')['c'] ?? 0;
        $deleted = max(0, (int) $before - (int) $after);
        $this->audit('security.webhook_deliveries.cleaned', 'webhook_delivery', 0, ['deleted' => $deleted, 'site_id' => $siteId, 'webhook_id' => $webhookId ?: null, 'statuses' => $statuses, 'older_than_days' => $olderThanDays]);
        return Response::success(['deleted' => $deleted, 'webhook_stats' => $this->webhookStats($siteId ?? 0), 'message' => $deleted . ' livraison(s) supprimée(s).'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }

    public function updateCors(int $siteId): Response
    {
        $this->requireSecurityCorsManage();
        if (!$this->site($siteId)) return Response::error('SITE_NOT_FOUND', 'Site introuvable.', 404);
        $payload = AdminApiContract::dataPayload($this->request, false);
        $origins = $this->originList($payload['origins'] ?? []);
        $this->coreDb->run(
            "INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public, updated_by_iam_user_id, updated_at)
             VALUES(:site_id, 'api', 'cors_allowed_origins', :value_json, 0, :user_id, CURRENT_TIMESTAMP)
             ON CONFLICT(site_id, namespace, setting_key) DO UPDATE SET value_json=excluded.value_json, is_public=0, updated_by_iam_user_id=excluded.updated_by_iam_user_id, updated_at=CURRENT_TIMESTAMP",
            [
                'site_id' => $siteId,
                'value_json' => json_encode($origins, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'user_id' => (int)($this->auth->user()['id'] ?? 0) ?: null,
            ]
        );
        $this->audit('security.cors.updated', 'site', $siteId, ['origins' => $origins]);
        return Response::success(['cors' => $this->corsForSite($siteId), 'message' => 'Origines CORS mises à jour.'], self::CONTRACT, ['contract_version' => AdminApiContract::VERSION]);
    }


    /** @return array<string,array<string,mixed>> */
    private function securityBlueprints(): array
    {
        if (!$this->coreDb->tableExists('blueprints') || !$this->coreDb->tableExists('blueprint_fields')) {
            return [];
        }
        $keys = ['security_api_token', 'security_webhook', 'security_cors', 'iam_login_mode'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->coreDb->all("SELECT id, blueprint_key, label, description FROM blueprints WHERE resource_type = 'system' AND blueprint_key IN ({$placeholders})", $keys);
        $out = [];
        foreach ($rows as $row) {
            $fields = $this->coreDb->all('SELECT field_handle, field_type, label, help_text, is_required, is_localized, default_value_json, options_json, validation_json, config_json FROM blueprint_fields WHERE blueprint_id = :id ORDER BY sort_order, id', ['id' => (int) $row['id']]);
            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[(string) $field['field_handle']] = [
                    'handle' => (string) $field['field_handle'],
                    'type' => (string) $field['field_type'],
                    'label' => (string) $field['label'],
                    'help_text' => (string) ($field['help_text'] ?? ''),
                    'is_required' => (bool) ($field['is_required'] ?? false),
                    'is_localized' => (bool) ($field['is_localized'] ?? false),
                    'default' => $this->decodeJsonValue($field['default_value_json'] ?? null),
                    'options' => $this->decodeJsonValue($field['options_json'] ?? null),
                    'validation' => $this->decodeJsonValue($field['validation_json'] ?? null),
                    'config' => $this->decodeJsonValue($field['config_json'] ?? null),
                ];
            }
            $out[(string) $row['blueprint_key']] = [
                'key' => (string) $row['blueprint_key'],
                'label' => (string) $row['label'],
                'description' => (string) ($row['description'] ?? ''),
                'fields' => $fieldMap,
            ];
        }
        return $out;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function can(string $permission): bool
    {
        return $this->auth->hasPermission($permission);
    }

    private function requireAnySecurityPermission(): void
    {
        foreach ([
            'security.tokens.read',
            'security.tokens.manage',
            'security.webhooks.read',
            'security.webhooks.manage',
            'security.cors.read',
            'security.cors.manage',
        ] as $permission) {
            if ($this->can($permission)) {
                return;
            }
        }
        $this->authorization->require('settings.sensitive');
    }

    private function requireSecurityTokensManage(): void
    {
        $this->authorization->require('security.tokens.manage');
    }

    private function requireSecurityWebhooksManage(): void
    {
        $this->authorization->require('security.webhooks.manage');
    }

    private function requireSecurityCorsManage(): void
    {
        $this->authorization->require('security.cors.manage');
    }

    /** @return list<array<string,mixed>> */
    private function sites(): array
    {
        $rows = $this->coreDb->all(
            "SELECT s.id, s.site_key, s.name, s.default_language_code, s.is_active,
                    COALESCE(sd.scheme, 'https') AS scheme,
                    COALESCE(sd.host, '') AS host,
                    COALESCE(sd.base_path, '') AS base_path
             FROM sites s
             LEFT JOIN site_domains sd ON sd.id = (
                 SELECT sd2.id FROM site_domains sd2
                 WHERE sd2.site_id = s.id AND sd2.is_active = 1
                 ORDER BY sd2.is_primary DESC, length(sd2.base_path) ASC, sd2.id ASC
                 LIMIT 1
             )
             ORDER BY s.id"
        );

        return array_map(function (array $r): array {
            $storedBasePath = $this->normalizeBasePath((string) ($r['base_path'] ?? ''));
            $host = $this->hostWithoutPort((string) ($r['host'] ?? ''));
            $scheme = in_array((string) ($r['scheme'] ?? 'https'), ['http', 'https'], true) ? (string) $r['scheme'] : 'https';
            $requestBasePath = $this->requestBasePath($storedBasePath);
            $publicBasePath = $this->publicBasePath($storedBasePath);
            $publicUrl = $host !== '' ? rtrim($scheme . '://' . $host . $publicBasePath, '/') : '';

            return [
                'id' => (int) $r['id'],
                'site_key' => (string) $r['site_key'],
                'name' => (string) $r['name'],
                'default_language_code' => (string) $r['default_language_code'],
                'is_active' => (bool) $r['is_active'],
                'host' => $host,
                'base_path' => $publicBasePath,
                'request_base_path' => $requestBasePath,
                'public_path' => function_exists('url_path') ? url_path($requestBasePath === '' ? '/' : $requestBasePath . '/') : ($publicBasePath ?: '/'),
                'public_url' => $publicUrl,
            ];
        }, $rows);
    }


    private function normalizeBasePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . trim($path, '/');
    }

    private function requestBasePath(string $domainBasePath): string
    {
        $basePath = $this->normalizeBasePath($domainBasePath);
        $appBasePath = $this->normalizeBasePath(function_exists('app_base_path') ? app_base_path() : '');
        if ($appBasePath !== '' && ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/'))) {
            $basePath = substr($basePath, strlen($appBasePath)) ?: '';
        }
        return $this->normalizeBasePath($basePath);
    }

    private function publicBasePath(string $domainBasePath): string
    {
        $basePath = $this->normalizeBasePath($domainBasePath);
        $appBasePath = $this->normalizeBasePath(function_exists('app_base_path') ? app_base_path() : '');
        if ($appBasePath === '') {
            return $basePath;
        }
        if ($basePath === '' || $basePath === '/') {
            return $appBasePath;
        }
        if ($basePath === $appBasePath || str_starts_with($basePath, $appBasePath . '/')) {
            return $basePath;
        }
        return $this->normalizeBasePath($appBasePath . '/' . ltrim($basePath, '/'));
    }

    private function hostWithoutPort(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, ':')) {
            return explode(':', $host, 2)[0];
        }
        return $host;
    }

    private function site(int $id): ?array
    {
        return $this->coreDb->one('SELECT id, site_key, name FROM sites WHERE id=:id LIMIT 1', ['id'=>$id]) ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function tokens(int $siteId = 0): array
    {
        $rows = $siteId > 0
            ? $this->iamDb->all('SELECT * FROM api_tokens WHERE site_id = :site_id ORDER BY updated_at DESC, id DESC', ['site_id' => $siteId])
            : $this->iamDb->all('SELECT * FROM api_tokens ORDER BY updated_at DESC, id DESC');
        return array_values(array_filter(array_map(fn(array $r): array => $this->tokenRow($r), $rows), fn($r) => $r !== []));
    }

    private function token(int $id): ?array
    {
        $row = $this->iamDb->one('SELECT * FROM api_tokens WHERE id=:id LIMIT 1', ['id'=>$id]);
        return $row ? $this->tokenRow($row) : null;
    }

    private function tokenRow(array $r): array
    {
        return ['id'=>(int)$r['id'], 'name'=>(string)$r['name'], 'site_id'=>isset($r['site_id']) ? (int)$r['site_id'] : null, 'scopes'=>(string)$r['scopes'], 'is_active'=>(bool)$r['is_active'], 'expires_at'=>$r['expires_at'] ?? null, 'last_used_at'=>$r['last_used_at'] ?? null, 'created_at'=>(string)$r['created_at'], 'updated_at'=>(string)$r['updated_at']];
    }

    /** @return list<array<string,mixed>> */
    private function webhooks(int $siteId = 0): array
    {
        $rows = $siteId > 0
            ? $this->coreDb->all('SELECT * FROM webhook_endpoints WHERE site_id = :site_id ORDER BY updated_at DESC, id DESC', ['site_id' => $siteId])
            : $this->coreDb->all('SELECT * FROM webhook_endpoints ORDER BY updated_at DESC, id DESC');
        return array_values(array_filter(array_map(fn(array $r): array => $this->webhookRow($r), $rows), fn($r) => $r !== []));
    }

    private function webhook(int $id): ?array
    {
        $row = $this->coreDb->one('SELECT * FROM webhook_endpoints WHERE id=:id LIMIT 1', ['id'=>$id]);
        return $row ? $this->webhookRow($row) : null;
    }

    private function webhookRow(array $r): array
    {
        $events = json_decode((string)($r['events_json'] ?? '[]'), true);
        return ['id'=>(int)$r['id'], 'site_id'=>isset($r['site_id']) ? (int)$r['site_id'] : null, 'name'=>(string)($r['name'] ?? ''), 'url'=>(string)$r['url'], 'events'=>is_array($events) ? array_values(array_map('strval', $events)) : [], 'secret'=>(string)$r['secret'], 'is_active'=>(bool)$r['is_active'], 'max_attempts'=>(int)$r['max_attempts'], 'last_attempt_at'=>$r['last_attempt_at'] ?? null, 'next_attempt_at'=>$r['next_attempt_at'] ?? null, 'created_at'=>(string)$r['created_at'], 'updated_at'=>(string)$r['updated_at']];
    }

    /** @return list<array<string,mixed>> */
    private function corsSettings(): array
    {
        return array_map(fn(array $site): array => $this->corsForSite((int)$site['id']), $this->sites());
    }

    private function corsForSite(int $siteId): array
    {
        $row = $this->coreDb->one("SELECT value_json FROM site_settings WHERE site_id=:site_id AND namespace='api' AND setting_key='cors_allowed_origins' LIMIT 1", ['site_id'=>$siteId]);
        $origins = $this->originList(json_decode((string)($row['value_json'] ?? '[]'), true));
        return ['site_id'=>$siteId, 'origins'=>$origins];
    }

    /** @return array{pending:int,failed:int,succeeded:int,total:int} */
    private function webhookStats(int $siteId = 0): array
    {
        $siteFilter = $siteId > 0 ? ' AND EXISTS (SELECT 1 FROM webhook_endpoints w WHERE w.id = webhook_deliveries.webhook_id AND w.site_id = :site_id)' : '';
        $params = $siteId > 0 ? ['site_id' => $siteId] : [];
        return [
            'pending' => (int)($this->coreDb->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status IN ('pending','processing')" . $siteFilter, $params)['c'] ?? 0),
            'failed' => (int)($this->coreDb->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status = 'failed'" . $siteFilter, $params)['c'] ?? 0),
            'succeeded' => (int)($this->coreDb->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status = 'succeeded'" . $siteFilter, $params)['c'] ?? 0),
            'total' => (int)($this->coreDb->one('SELECT COUNT(*) AS c FROM webhook_deliveries WHERE 1=1' . $siteFilter, $params)['c'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function deliveriesForWebhook(int $webhookId, int $limit = 30): array
    {
        $rows = $this->coreDb->all(
            'SELECT id, webhook_id, outbox_event_id, delivery_id, event_topic, status, attempts, http_status, response_body, last_error, created_at, next_attempt_at, last_attempt_at, delivered_at
             FROM webhook_deliveries
             WHERE webhook_id = :webhook_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, min(100, $limit)),
            ['webhook_id' => $webhookId]
        );
        return array_map(fn(array $row): array => $this->deliveryRow($row), $rows);
    }

    /** @return array{pending:int,failed:int,succeeded:int,total:int} */
    private function webhookDeliveryStats(int $webhookId): array
    {
        return [
            'pending' => (int)($this->coreDb->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE webhook_id=:id AND status IN ('pending','processing')", ['id' => $webhookId])['c'] ?? 0),
            'failed' => (int)($this->coreDb->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE webhook_id=:id AND status='failed'", ['id' => $webhookId])['c'] ?? 0),
            'succeeded' => (int)($this->coreDb->one("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE webhook_id=:id AND status='succeeded'", ['id' => $webhookId])['c'] ?? 0),
            'total' => (int)($this->coreDb->one('SELECT COUNT(*) AS c FROM webhook_deliveries WHERE webhook_id=:id', ['id' => $webhookId])['c'] ?? 0),
        ];
    }

    private function delivery(int $id): ?array
    {
        $row = $this->coreDb->one('SELECT id, webhook_id, outbox_event_id, delivery_id, event_topic, status, attempts, http_status, response_body, last_error, created_at, next_attempt_at, last_attempt_at, delivered_at FROM webhook_deliveries WHERE id=:id LIMIT 1', ['id' => $id]);
        return $row ? $this->deliveryRow($row) : null;
    }

    private function deliveryRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'webhook_id' => (int) $row['webhook_id'],
            'outbox_event_id' => (int) $row['outbox_event_id'],
            'delivery_id' => (string) $row['delivery_id'],
            'event_topic' => (string) $row['event_topic'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : null,
            'response_body' => $this->limited((string) ($row['response_body'] ?? ''), 2000),
            'last_error' => $row['last_error'] ?? null,
            'created_at' => (string) $row['created_at'],
            'next_attempt_at' => $row['next_attempt_at'] ?? null,
            'last_attempt_at' => $row['last_attempt_at'] ?? null,
            'delivered_at' => $row['delivered_at'] ?? null,
        ];
    }

    private function createSyntheticOutboxEvent(string $topic, array $payload): int
    {
        $now = now_utc();
        $siteId = isset($payload['data']['site_id']) ? (int) $payload['data']['site_id'] : null;
        $this->coreDb->run(
            "INSERT INTO outbox_events(
                event_id, event_type, schema_version, occurred_at, site_id, correlation_id,
                aggregate_type, aggregate_id, topic, payload_json, metadata_json, status, attempts,
                max_attempts, created_at, available_at, processed_at, updated_at
            ) VALUES(
                :event_id, :event_type, 1, :occurred_at, :site_id, :correlation_id,
                'webhook_endpoint', :aggregate_id, :topic, :payload, :metadata, 'processed', 1,
                1, :created_at, :available_at, :processed_at, :updated_at
            )",
            [
                'event_id' => 'evt_ping_' . bin2hex(random_bytes(16)),
                'event_type' => $topic,
                'occurred_at' => $now,
                'site_id' => $siteId && $siteId > 0 ? $siteId : null,
                'correlation_id' => Response::requestId(),
                'aggregate_id' => isset($payload['data']['webhook_id']) ? (string) $payload['data']['webhook_id'] : null,
                'topic' => $topic,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'metadata' => json_encode(['producer' => 'admin.webhook.ping'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'available_at' => $now,
                'processed_at' => $now,
                'updated_at' => $now,
            ]
        );
        return $this->coreDb->lastInsertId();
    }

    private function createAdminDelivery(int $webhookId, int $outboxEventId, string $deliveryId, string $topic, string $body): int
    {
        $this->coreDb->run(
            "INSERT INTO webhook_deliveries(webhook_id, outbox_event_id, delivery_id, event_topic, payload_json, status, attempts, created_at, next_attempt_at)
             VALUES(:webhook_id, :outbox_event_id, :delivery_id, :event_topic, :payload_json, 'processing', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            ['webhook_id' => $webhookId, 'outbox_event_id' => $outboxEventId, 'delivery_id' => $deliveryId, 'event_topic' => $topic, 'payload_json' => $body]
        );
        $this->coreDb->run('UPDATE webhook_endpoints SET last_attempt_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id', ['id' => $webhookId]);
        return $this->coreDb->lastInsertId();
    }

    private function markAdminDeliverySucceeded(int $deliveryPk, int $webhookId, int $status, string $responseBody): void
    {
        $this->coreDb->run(
            "UPDATE webhook_deliveries SET status='succeeded', http_status=:status, response_body=:body, last_error=NULL, last_attempt_at=CURRENT_TIMESTAMP, delivered_at=CURRENT_TIMESTAMP, next_attempt_at=CURRENT_TIMESTAMP WHERE id=:id",
            ['status' => $status, 'body' => $this->limited($responseBody, 4000), 'id' => $deliveryPk]
        );
        $this->coreDb->run('UPDATE webhook_endpoints SET last_attempt_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id', ['id' => $webhookId]);
    }

    private function markAdminDeliveryFailed(int $deliveryPk, int $webhookId, string $error, ?int $status, string $responseBody): void
    {
        $this->coreDb->run(
            "UPDATE webhook_deliveries SET status='failed', http_status=:status, response_body=:body, last_error=:error, last_attempt_at=CURRENT_TIMESTAMP, next_attempt_at=CURRENT_TIMESTAMP WHERE id=:id",
            ['status' => $status, 'body' => $this->limited($responseBody, 4000), 'error' => $this->limited($error, 1000), 'id' => $deliveryPk]
        );
        $this->coreDb->run('UPDATE webhook_endpoints SET last_attempt_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id', ['id' => $webhookId]);
    }

    /** @return list<string> */
    private function deliveryStatusList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $allowed = ['pending', 'processing', 'succeeded', 'failed'];
        $out = [];
        foreach ($items ?: [] as $item) {
            $status = trim((string) $item);
            if (in_array($status, $allowed, true)) $out[] = $status;
        }
        return array_values(array_unique($out));
    }

    private function tokenHasScope(string $scopes, string $required): bool
    {
        $items = preg_split('/[\s,]+/', $scopes) ?: [];
        $items = array_values(array_filter(array_map('trim', $items), fn(string $s): bool => $s !== ''));
        return in_array('*', $items, true) || in_array($required, $items, true);
    }

    private function nullableSiteId(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int)$value <= 0) return null;
        return (int)$value;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private function scopeString(mixed $value): string
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value);
        $out = [];
        foreach ($items ?: [] as $item) {
            $item = trim((string)$item);
            if ($item !== '' && preg_match('/^[a-z0-9:*_.-]+$/i', $item)) $out[] = $item;
        }
        return implode(' ', array_values(array_unique($out ?: ['headless:read'])));
    }

    /** @return list<string> */
    private function eventList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value);
        $out = [];
        foreach ($items ?: [] as $item) {
            $item = trim((string)$item);
            if ($item !== '' && preg_match('/^[a-z0-9:*_.-]+$/i', $item)) $out[] = $item;
        }
        return array_values(array_unique($out ?: ['*']));
    }

    /** @return list<string> */
    private function originList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value);
        $out = [];
        foreach ($items ?: [] as $item) {
            $origin = rtrim(trim((string)$item), '/');
            if ($origin === '*') { $out[] = '*'; continue; }
            if (preg_match('#^https?://[^/\s]+$#i', $origin) === 1) $out[] = $origin;
        }
        return array_values(array_unique($out));
    }

    private function isAllowedWebhookUrl(string $url): bool
    {
        return preg_match('#^https://[^\s]+$#i', $url) === 1 || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/?.*$#i', $url) === 1;
    }

    private function limited(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private function audit(string $action, string $resourceType, int $resourceId, array $context = []): void
    {
        $this->auth->audit((int)($this->auth->user()['id'] ?? 0) ?: null, $action, $resourceType, $resourceId, $context);
    }
}
