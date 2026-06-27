<?php

declare(strict_types=1);

namespace App\Modules\Business\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use RuntimeException;

abstract class BusinessRepositoryBase
{
    private const CRM_STATUSES = ['prospect', 'client', 'supplier', 'former_client', 'other'];
    private const CHANNELS = ['email', 'whatsapp', 'telegram'];

    public function __construct(protected readonly ?Database $db) {}

    public function isAvailable(): bool
    {
        return $this->db !== null;
    }

    protected function database(): Database
    {
        if ($this->db === null) {
            throw new RuntimeException('Base Business indisponible.');
        }
        return $this->db;
    }

    protected function requireSiteId(int $siteId): int
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException('business.site_id_invalid');
        }
        return $siteId;
    }

    protected function limit(int $limit, int $max = 200): int
    {
        return max(1, min($max, $limit));
    }

    protected function offset(int $offset): int
    {
        return max(0, $offset);
    }

    protected function text(mixed $value, string $field, int $max = 255, bool $required = true): ?string
    {
        if ($value === null) {
            if ($required) {
                throw new InvalidArgumentException('business.' . $field . '_required');
            }
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            if ($required) {
                throw new InvalidArgumentException('business.' . $field . '_required');
            }
            return null;
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException('business.' . $field . '_too_long');
        }
        return $text;
    }

    protected function nullableText(mixed $value, string $field, int $max = 255): ?string
    {
        return $this->text($value, $field, $max, false);
    }

    protected function normalizedName(string $value): string
    {
        return strtolower(trim($value));
    }

    protected function crmStatus(mixed $value): string
    {
        $status = trim((string) ($value ?: 'prospect'));
        if (!in_array($status, self::CRM_STATUSES, true)) {
            throw new InvalidArgumentException('business.status_invalid');
        }
        return $status;
    }

    protected function channel(mixed $value): string
    {
        $channel = trim((string) $value);
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('business.channel_invalid');
        }
        return $channel;
    }

    protected function email(mixed $value, bool $required = false): ?string
    {
        $email = $this->text($value, 'email', 255, $required);
        if ($email === null) {
            return null;
        }
        $email = strtolower($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('business.email_invalid');
        }
        return $email;
    }

    protected function phone(mixed $value, string $field = 'phone'): ?string
    {
        $phone = $this->nullableText($value, $field, 32);
        if ($phone === null) {
            return null;
        }
        if (!preg_match('/^[0-9 +().-]{3,32}$/', $phone)) {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return $phone;
    }

    protected function boolInt(mixed $value): int
    {
        return (int) (bool) $value;
    }

    /** @return array<string,mixed> */
    protected function castRow(array $row): array
    {
        foreach (['id', 'site_id', 'parent_id', 'brand_id', 'category_id', 'product_id', 'variant_id', 'option_id', 'option_value_id', 'media_id', 'company_id', 'contact_id', 'author_iam_user_id', 'created_by_iam_user_id', 'updated_by_iam_user_id', 'iam_user_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['is_system', 'is_primary', 'is_verified', 'is_enabled', 'is_default', 'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'track_stock', 'allow_backorder', 'tax_included'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (bool) $row[$key];
            }
        }
        return $row;
    }
}
