<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Core\Database;
use App\Modules\Sale\Exceptions\SaleValidationException;

final class SaleFulfillmentService
{
    public function __construct(private readonly SaleDatabaseConnection $connection) {}

    /** @return list<array<string,mixed>> */
    public function availableMethods(int $siteId, string $language = 'fr'): array
    {
        $label = strtolower($language) === 'en' ? 'label_en' : 'label_fr';
        return array_map(static fn(array $row): array => [
            'code' => (string) $row['code'],
            'label' => (string) $row[$label],
            'type' => (string) $row['fulfillment_type'],
            'flat_rate_minor' => (int) $row['flat_rate_minor'],
            'free_above_minor' => $row['free_above_minor'] === null ? null : (int) $row['free_above_minor'],
            'requires_shipping_address' => (bool) $row['requires_shipping_address'],
        ], $this->activeRows($siteId));
    }

    /** @param list<array<string,mixed>> $lines @param array<string,mixed> $address @return array<string,mixed> */
    public function quote(int $siteId, array $lines, array $address, string $code, string $language = 'fr'): array
    {
        $code = strtolower(trim($code));
        $method = null;
        foreach ($this->activeRows($siteId) as $candidate) {
            if ((string) $candidate['code'] === $code) { $method = $candidate; break; }
        }
        if ($method === null || $lines === []) {
            throw new SaleValidationException('sale.checkout.fulfillment_method_invalid');
        }
        $physical = array_filter($lines, static fn(array $line): bool => in_array((string) ($line['product_type'] ?? 'physical'), ['physical','bundle','other'], true));
        $hasPhysical = $physical !== [];
        $type = (string) $method['fulfillment_type'];
        if (($hasPhysical && $type === 'none') || (!$hasPhysical && $type !== 'none' && !(bool) $method['allow_non_physical'])) {
            throw new SaleValidationException('sale.checkout.fulfillment_method_invalid_for_cart');
        }
        if ((bool) $method['requires_shipping_address']) {
            $this->validateAddress($address);
            $this->assertZone($method, $address);
        }
        $itemsTotal = array_sum(array_map(static fn(array $line): int => max(0, (int) ($line['line_total_minor'] ?? 0)), $lines));
        $freeAbove = $method['free_above_minor'] === null ? null : (int) $method['free_above_minor'];
        $amount = $freeAbove !== null && $itemsTotal >= $freeAbove ? 0 : (int) $method['flat_rate_minor'];
        $labelKey = strtolower($language) === 'en' ? 'label_en' : 'label_fr';
        return [
            'method_id' => (int) $method['id'], 'code' => $code, 'label' => (string) $method[$labelKey],
            'type' => $type, 'amount_minor' => $amount, 'currency' => strtoupper((string) ($lines[0]['currency'] ?? 'CHF')),
            'requires_shipping_address' => (bool) $method['requires_shipping_address'],
            'zone' => $method['zone_code'] === null ? null : ['code' => $method['zone_code'], 'name' => $method['zone_name']],
            'pricing_rule' => ['flat_rate_minor' => (int) $method['flat_rate_minor'], 'free_above_minor' => $freeAbove, 'items_total_minor' => $itemsTotal],
            'snapshot_version' => 1,
        ];
    }

    /** @return array{zones:list<array<string,mixed>>,methods:list<array<string,mixed>>} */
    public function configuration(int $siteId): array
    {
        return ['zones' => $this->db()->all('SELECT * FROM sale_fulfillment_zones WHERE site_id=? ORDER BY code', [$siteId]), 'methods' => $this->db()->all('SELECT * FROM sale_fulfillment_methods WHERE site_id=? ORDER BY sort_order,code', [$siteId])];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveMethod(int $siteId, array $payload): array
    {
        $code = strtolower(trim((string) ($payload['code'] ?? '')));
        $type = (string) ($payload['fulfillment_type'] ?? 'shipping');
        if (!preg_match('/^[a-z0-9_-]+$/', $code) || !in_array($type, ['shipping','pickup','none'], true)) throw new SaleValidationException('sale.fulfillment.method_invalid');
        $params = [$siteId,$payload['zone_id']??null,$code,trim((string)($payload['label_fr']??$code)),trim((string)($payload['label_en']??$code)),$type,max(0,(int)($payload['flat_rate_minor']??0)),isset($payload['free_above_minor'])?(int)$payload['free_above_minor']:null,($payload['requires_shipping_address']??$type==='shipping')?1:0,($payload['allow_non_physical']??false)?1:0,(string)($payload['status']??'active'),$payload['active_from']??null,$payload['active_until']??null,(int)($payload['sort_order']??0)];
        $this->db()->run('INSERT INTO sale_fulfillment_methods(site_id,zone_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,free_above_minor,requires_shipping_address,allow_non_physical,status,active_from,active_until,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(site_id,code) DO UPDATE SET zone_id=excluded.zone_id,label_fr=excluded.label_fr,label_en=excluded.label_en,fulfillment_type=excluded.fulfillment_type,flat_rate_minor=excluded.flat_rate_minor,free_above_minor=excluded.free_above_minor,requires_shipping_address=excluded.requires_shipping_address,allow_non_physical=excluded.allow_non_physical,status=excluded.status,active_from=excluded.active_from,active_until=excluded.active_until,sort_order=excluded.sort_order,updated_at=CURRENT_TIMESTAMP',$params);
        return $this->db()->one('SELECT * FROM sale_fulfillment_methods WHERE site_id=? AND code=?',[$siteId,$code])??[];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveZone(int $siteId, array $payload): array
    {
        $code=strtolower(trim((string)($payload['code']??''))); $name=trim((string)($payload['name']??''));
        $countries=array_values(array_unique(array_map(static fn(mixed $v):string=>strtoupper(trim((string)$v)),is_array($payload['country_codes']??null)?$payload['country_codes']:[])));
        $prefixes=array_values(array_filter(array_map(static fn(mixed $v):string=>trim((string)$v),is_array($payload['postal_prefixes']??null)?$payload['postal_prefixes']:[])));
        if(!preg_match('/^[a-z0-9_-]+$/',$code)||$name===''||array_filter($countries,static fn(string $v):bool=>!preg_match('/^[A-Z]{2}$/',$v))) throw new SaleValidationException('sale.fulfillment.zone_invalid');
        $this->db()->run('INSERT INTO sale_fulfillment_zones(site_id,code,name,country_codes_json,postal_prefixes_json,status,active_from,active_until) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(site_id,code) DO UPDATE SET name=excluded.name,country_codes_json=excluded.country_codes_json,postal_prefixes_json=excluded.postal_prefixes_json,status=excluded.status,active_from=excluded.active_from,active_until=excluded.active_until,updated_at=CURRENT_TIMESTAMP',[$siteId,$code,$name,json_encode($countries),json_encode($prefixes),(string)($payload['status']??'active'),$payload['active_from']??null,$payload['active_until']??null]);
        return $this->db()->one('SELECT * FROM sale_fulfillment_zones WHERE site_id=? AND code=?',[$siteId,$code])??[];
    }

    /** @return list<array<string,mixed>> */
    private function activeRows(int $siteId): array
    {
        return $this->db()->all("SELECT m.*,z.code AS zone_code,z.name AS zone_name,z.country_codes_json,z.postal_prefixes_json FROM sale_fulfillment_methods m LEFT JOIN sale_fulfillment_zones z ON z.id=m.zone_id WHERE m.site_id=? AND m.status='active' AND (m.active_from IS NULL OR m.active_from<=CURRENT_TIMESTAMP) AND (m.active_until IS NULL OR m.active_until>CURRENT_TIMESTAMP) AND (z.id IS NULL OR (z.status='active' AND (z.active_from IS NULL OR z.active_from<=CURRENT_TIMESTAMP) AND (z.active_until IS NULL OR z.active_until>CURRENT_TIMESTAMP))) ORDER BY m.sort_order,m.code",[$siteId]);
    }

    /** @param array<string,mixed> $method @param array<string,mixed> $address */
    private function assertZone(array $method, array $address): void
    {
        if ($method['zone_code'] === null) return;
        $countries = json_decode((string) $method['country_codes_json'], true) ?: [];
        $prefixes = json_decode((string) $method['postal_prefixes_json'], true) ?: [];
        $country = strtoupper(trim((string) ($address['country_code'] ?? ''))); $postal = trim((string) ($address['postal_code'] ?? ''));
        if ($countries !== [] && !in_array($country, $countries, true)) throw new SaleValidationException('sale.checkout.fulfillment_address_outside_zone');
        if ($prefixes !== [] && !array_filter($prefixes, static fn(string $prefix): bool => str_starts_with($postal, $prefix))) throw new SaleValidationException('sale.checkout.fulfillment_address_outside_zone');
    }

    /** @param array<string,mixed> $address */
    private function validateAddress(array $address): void
    {
        foreach (['line1','postal_code','city','country_code'] as $field) if (trim((string)($address[$field]??''))==='') throw new SaleValidationException('sale.checkout.shipping_address_invalid');
        if (!preg_match('/^[A-Z]{2}$/',strtoupper((string)$address['country_code']))) throw new SaleValidationException('sale.checkout.shipping_address_invalid');
    }

    private function db(): Database { return $this->connection->database() ?? throw new SaleValidationException('sale.database_unavailable'); }
}
