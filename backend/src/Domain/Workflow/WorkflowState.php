<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

final class WorkflowState
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';
    public const SCHEDULED = 'scheduled';

    /** @var list<string> */
    private const ALLOWED = [self::DRAFT, self::REVIEW, self::PUBLISHED, self::ARCHIVED, self::SCHEDULED];

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException(sprintf('État de workflow inconnu : %s.', $value));
        }
        $this->value = $value;
    }

    public static function draft(): self { return new self(self::DRAFT); }
    public static function review(): self { return new self(self::REVIEW); }
    public static function published(): self { return new self(self::PUBLISHED); }
    public static function archived(): self { return new self(self::ARCHIVED); }
    public static function scheduled(): self { return new self(self::SCHEDULED); }

    public function canTransitionTo(self $target): bool
    {
        if ($this->value === $target->value) {
            return true;
        }

        return match ($this->value) {
            self::DRAFT => in_array($target->value, [self::REVIEW, self::PUBLISHED, self::SCHEDULED, self::ARCHIVED], true),
            self::REVIEW => in_array($target->value, [self::DRAFT, self::PUBLISHED, self::ARCHIVED], true),
            self::SCHEDULED => in_array($target->value, [self::DRAFT, self::PUBLISHED, self::ARCHIVED], true),
            self::PUBLISHED => in_array($target->value, [self::DRAFT, self::ARCHIVED], true),
            self::ARCHIVED => $target->value === self::DRAFT,
            default => false,
        };
    }

    public function isDraft(): bool { return $this->value === self::DRAFT; }
    public function isPublished(): bool { return $this->value === self::PUBLISHED; }
    public function isArchived(): bool { return $this->value === self::ARCHIVED; }
    public function blocksPublicRoute(): bool { return !$this->isPublished(); }

    public function __toString(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public static function allowedValues(): array
    {
        return self::ALLOWED;
    }
}
