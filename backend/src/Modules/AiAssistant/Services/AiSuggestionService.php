<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use App\Modules\AiAssistant\Repositories\AiRepositoryBase;

/**
 * Cycle de vie contrôlé des suggestions IA.
 *
 * Transitions autorisées :
 * - draft -> proposed
 * - proposed -> accepted
 * - proposed -> rejected
 * - accepted -> applied
 * - proposed|accepted -> expired
 */
final class AiSuggestionService extends AiRepositoryBase
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PROPOSED, self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_APPLIED, self::STATUS_EXPIRED];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PROPOSED],
        self::STATUS_PROPOSED => [self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_EXPIRED],
        self::STATUS_ACCEPTED => [self::STATUS_APPLIED, self::STATUS_EXPIRED],
        self::STATUS_REJECTED => [],
        self::STATUS_APPLIED => [],
        self::STATUS_EXPIRED => [],
    ];

    public function __construct(?Database $db) { parent::__construct($db); }

    /** @return array<string,mixed> */
    public function create(array $payload): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $status = $this->normalizeStatus((string) ($payload['status'] ?? self::STATUS_DRAFT));
        $suggestion = $this->normalizeSuggestionPayload($payload['suggestion'] ?? new \stdClass(), $status, 'create', null);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO ai_suggestions(site_id, user_id, title, target_type, target_id, suggestion_type, status, source_field, target_field, suggestion_json, preview_text, reason, created_at)
             VALUES(:site_id, :user_id, :title, :target_type, :target_id, :suggestion_type, :status, :source_field, :target_field, :suggestion_json, :preview_text, :reason, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'site_id' => $payload['site_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'title' => (string) ($payload['title'] ?? $this->buildTitle($payload)),
            'target_type' => $this->normalizeKey((string) ($payload['target_type'] ?? 'content')),
            'target_id' => (string) ($payload['target_id'] ?? ''),
            'suggestion_type' => $this->normalizeKey((string) ($payload['suggestion_type'] ?? 'draft')),
            'status' => $status,
            'source_field' => $payload['source_field'] ?? null,
            'target_field' => $payload['target_field'] ?? null,
            'suggestion_json' => $this->encodeJson($suggestion),
            'preview_text' => (string) ($payload['preview_text'] ?? ''),
            'reason' => (string) ($payload['reason'] ?? ''),
        ]);
        return $this->find((int) $this->pdo()->lastInsertId()) ?? [];
    }

    /** @return array<string,mixed> */
    public function propose(array $payload): array
    {
        $payload['status'] = self::STATUS_PROPOSED;
        return $this->create($payload);
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 25, ?string $status = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $params = [];
        $where = '';
        if ($status !== null && trim($status) !== '') {
            $status = $this->normalizeStatus($status);
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }
        $stmt = $this->pdo()->prepare("SELECT * FROM ai_suggestions {$where} ORDER BY created_at DESC, id DESC LIMIT :limit");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $row): array => $this->map($row), $stmt->fetchAll());
    }

    public function proposeExisting(int $id): ?array
    {
        return $this->transition($id, self::STATUS_PROPOSED, 'Suggestion proposée à la relecture.');
    }

    public function accept(int $id): ?array
    {
        return $this->transition($id, self::STATUS_ACCEPTED, 'Suggestion acceptée.');
    }

    public function reject(int $id, string $reason = ''): ?array
    {
        return $this->transition($id, self::STATUS_REJECTED, $reason !== '' ? $reason : 'Suggestion rejetée.');
    }

    /**
     * Marque une suggestion comme appliquée après une vraie action externe
     * contrôlée par le système de révisions/brouillons.
     *
     * L’endpoint refuse l’application directe si aucun action_run_id n’est fourni.
     */
    public function apply(int $id, int $actionRunId, string $note = ''): ?array
    {
        if ($actionRunId <= 0) {
            throw new \InvalidArgumentException('Application directe désactivée : fournissez applied_action_run_id après création d’une révision ou d’un brouillon.');
        }
        return $this->transition($id, self::STATUS_APPLIED, $note !== '' ? $note : 'Suggestion appliquée via révision ou brouillon.', $actionRunId);
    }

    public function markApplied(int $id, int $actionRunId): ?array
    {
        return $this->apply($id, $actionRunId);
    }

    public function expire(int $id, string $reason = ''): ?array
    {
        return $this->transition($id, self::STATUS_EXPIRED, $reason !== '' ? $reason : 'Suggestion expirée.');
    }

    public function find(int $id): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM ai_suggestions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->map($row) : null;
    }

    /** @return array<string,mixed> */
    public function expireOld(int $days = 30, int $limit = 200): array
    {
        if (!$this->isAvailable()) {
            return ['expired' => 0, 'ids' => []];
        }
        $days = max(1, min(365, $days));
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM ai_suggestions
             WHERE status IN ('proposed','accepted')
               AND datetime(created_at) < datetime('now', :age)
             ORDER BY created_at ASC, id ASC
             LIMIT :limit"
        );
        $stmt->bindValue('age', '-' . $days . ' days');
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        foreach ($ids as $id) {
            $this->expire($id, 'Expiration automatique après ' . $days . ' jours.');
        }
        return ['expired' => count($ids), 'ids' => $ids];
    }

    private function transition(int $id, string $toStatus, string $note = '', ?int $actionRunId = null): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $current = $this->find($id);
        if (!$current) {
            throw new \RuntimeException('Suggestion IA introuvable.');
        }
        $fromStatus = (string) $current['status'];
        $toStatus = $this->normalizeStatus($toStatus);
        if (!in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true)) {
            throw new \InvalidArgumentException(sprintf('Transition de suggestion invalide : %s → %s.', $fromStatus, $toStatus));
        }
        $suggestionJson = $this->normalizeSuggestionPayload($current['suggestion'] ?? [], $toStatus, $note !== '' ? $note : $toStatus, $fromStatus);
        $sets = ['status = :status', 'suggestion_json = :suggestion_json'];
        $params = [
            'id' => $id,
            'status' => $toStatus,
            'suggestion_json' => $this->encodeJson($suggestionJson),
        ];
        if ($toStatus === self::STATUS_ACCEPTED) {
            $sets[] = 'accepted_at = CURRENT_TIMESTAMP';
        }
        if ($toStatus === self::STATUS_REJECTED) {
            $sets[] = 'rejected_at = CURRENT_TIMESTAMP';
        }
        if ($toStatus === self::STATUS_APPLIED) {
            $sets[] = 'applied_action_run_id = :run_id';
            $sets[] = 'applied_at = CURRENT_TIMESTAMP';
            $params['run_id'] = $actionRunId;
        }
        if ($toStatus === self::STATUS_EXPIRED) {
            $sets[] = 'expired_at = CURRENT_TIMESTAMP';
        }
        $sql = 'UPDATE ai_suggestions SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo()->prepare($sql)->execute($params);
        return $this->find($id);
    }

    private function buildTitle(array $payload): string
    {
        $type = (string) ($payload['suggestion_type'] ?? 'suggestion');
        $target = trim((string) ($payload['target_type'] ?? 'content') . ' #' . (string) ($payload['target_id'] ?? ''));
        return mb_substr('Suggestion IA · ' . $type . ($target !== ' #' ? ' · ' . $target : ''), 0, 180);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Statut de suggestion IA invalide : ' . $status);
        }
        return $status;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?: '';
        return trim($value, '_.-') ?: 'content';
    }

    private function normalizeSuggestionPayload(mixed $payload, string $status, string $event, ?string $fromStatus): array
    {
        $data = is_array($payload) ? $payload : ['value' => $payload];
        $history = is_array($data['_history'] ?? null) ? $data['_history'] : [];
        $history[] = [
            'from' => $fromStatus,
            'to' => $status,
            'event' => $event,
            'at' => gmdate('c'),
        ];
        $data['_history'] = array_slice($history, -20);
        return $data;
    }

    /** @return array<string,mixed> */
    private function map(array $row): array
    {
        $suggestion = $this->decodeJson((string) $row['suggestion_json'], []);
        $history = is_array($suggestion) && is_array($suggestion['_history'] ?? null) ? $suggestion['_history'] : [];
        return [
            'id' => (int) $row['id'],
            'site_id' => isset($row['site_id']) ? (int) $row['site_id'] : null,
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'title' => (string) ($row['title'] ?? 'Suggestion IA'),
            'target_type' => (string) $row['target_type'],
            'target_id' => (string) $row['target_id'],
            'target_label' => trim((string) $row['target_type'] . ' #' . (string) $row['target_id']),
            'suggestion_type' => (string) $row['suggestion_type'],
            'status' => (string) $row['status'],
            'source_field' => $row['source_field'],
            'target_field' => $row['target_field'],
            'suggestion' => $suggestion,
            'preview_text' => (string) $row['preview_text'],
            'reason' => (string) $row['reason'],
            'created_at' => (string) $row['created_at'],
            'accepted_at' => $row['accepted_at'] ?? null,
            'rejected_at' => $row['rejected_at'] ?? null,
            'applied_at' => $row['applied_at'] ?? null,
            'expired_at' => $row['expired_at'] ?? null,
            'applied_action_run_id' => isset($row['applied_action_run_id']) ? (int) $row['applied_action_run_id'] : null,
            'history' => $history,
            'allowed_transitions' => self::TRANSITIONS[(string) $row['status']] ?? [],
        ];
    }
}
