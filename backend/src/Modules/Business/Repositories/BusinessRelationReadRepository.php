<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

final class BusinessRelationReadRepository
{
    public function __construct(
        private readonly BusinessRelationRepository $relations,
        private readonly BusinessMemoRepository $memos,
    ) {}

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int,total:int} */
    public function list(int $siteId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        if (($filters['kind'] ?? '') !== '' && ($filters['type'] ?? '') === '') {
            $filters['type'] = $filters['kind'];
        }
        return $this->relations->list($siteId, $filters, $limit, $offset);
    }

    public function find(int $siteId, string $type, int $id, bool $includeArchived = false): ?array
    {
        return $this->relations->find($siteId, $type, $id, $includeArchived);
    }

    /** @return array{items:list<array<string,mixed>>,limit:int,offset:int} */
    public function memos(int $siteId, string $type, int $id, int $limit = 50, int $offset = 0, bool $includeArchived = false): array
    {
        $relation = $this->find($siteId, $type, $id);
        if ($relation === null) {
            throw new \InvalidArgumentException('business.relation_not_found');
        }
        $filters = $type === 'company' ? ['company_relation_id' => $id] : ['contact_id' => $id];
        return $this->memos->list($siteId, $filters, $limit, $offset, $includeArchived);
    }

    /** @return list<array<string,mixed>> */
    public function comments(int $siteId, string $type, int $id, int $limit = 100, int $offset = 0, bool $includeArchived = false): array
    {
        $memoResult = $this->memos($siteId, $type, $id, 200, 0, $includeArchived);
        $comments = [];
        foreach ($memoResult['items'] as $memo) {
            foreach ($this->memos->comments($siteId, (int) $memo['id'], $includeArchived) as $comment) {
                $comment['memo_id'] = (int) $memo['id'];
                $comment['memo_title'] = (string) ($memo['title'] ?? '');
                $comments[] = $comment;
            }
        }
        usort($comments, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)));
        return array_slice($comments, max(0, $offset), max(1, min(200, $limit)));
    }
}
