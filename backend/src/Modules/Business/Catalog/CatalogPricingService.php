<?php

declare(strict_types=1);

namespace App\Modules\Business\Catalog;

use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class CatalogPricingService
{
    public function __construct(
        private readonly BusinessCatalogPricingRepository $repository,
        private readonly BusinessCatalogValidator $validator = new BusinessCatalogValidator()
    ) {}

    public function calculateRegularPrice(int $variantId, string $priceKind): CatalogMoney
    {
        if (!in_array($priceKind, BusinessCatalogDefinitions::PRICE_KINDS, true)) {
            throw new InvalidArgumentException('business.catalog.price_kind_invalid');
        }
        $snapshot = $this->repository->pricingSnapshot($variantId);
        return $this->regularPriceFromSnapshot($snapshot, $priceKind);
    }

    /** @param array<string,mixed> $context */
    public function calculateFinalSalePrice(int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null, array $context = []): CatalogMoney
    {
        $snapshot = $this->repository->pricingSnapshot($variantId, $channel, $at, $context);
        return $this->finalSalePriceFromSnapshot($snapshot);
    }

    /** @param array<string,mixed> $context */
    public function calculateMargin(int $variantId, ?string $channel = null, array $context = []): CatalogMarginResult
    {
        $snapshot = $this->repository->pricingSnapshot($variantId, $channel, null, $context);
        return $this->marginFromSnapshot($snapshot);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function pricingSummary(int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null, array $context = []): array
    {
        $snapshot = $this->repository->pricingSnapshot($variantId, $channel, $at, $context);
        $variant = $snapshot['variant'];
        $purchase = $this->regularPriceFromSnapshot($snapshot, 'purchase');
        $catalogSale = $this->regularPriceFromSnapshot($snapshot, 'sale');
        $sale = $this->resolvedSalePriceFromSnapshot($snapshot);
        $finalSale = $this->finalSalePriceFromSnapshot($snapshot);
        $margin = $this->marginFromSnapshot($snapshot);
        $activeDiscount = $this->bestDiscount($snapshot, $sale);
        $priceRule = $this->bestPriceRule($snapshot);

        return [
            'variant_id' => $variant['variant_id'],
            'product_id' => $variant['product_id'],
            'sku' => $variant['sku'],
            'currency' => $sale->currency(),
            'base_purchase_price' => $this->moneyOrNull($variant['base_purchase_price'], $purchase->currency()),
            'purchase_adjustment_type' => $snapshot['adjustments']['purchase']['adjustment_type'],
            'purchase_adjustment_value' => $snapshot['adjustments']['purchase']['adjustment_value'],
            'regular_purchase_price' => $purchase->formatted(),
            'base_sale_price' => $this->moneyOrNull($variant['base_sale_price'], $catalogSale->currency()),
            'sale_adjustment_type' => $snapshot['adjustments']['sale']['adjustment_type'],
            'sale_adjustment_value' => $snapshot['adjustments']['sale']['adjustment_value'],
            'regular_sale_price' => $sale->formatted(),
            'catalog_regular_sale_price' => $catalogSale->formatted(),
            'active_price_rule' => $priceRule,
            'compare_at_price' => $priceRule === null || $priceRule['compare_at_amount'] === null
                ? null
                : CatalogMoney::from($priceRule['compare_at_amount'], $sale->currency())->formatted(),
            'active_discount' => $activeDiscount,
            'final_sale_price' => $finalSale->formatted(),
            'gross_margin_amount' => $margin->amount()->formatted(),
            'gross_margin_percent' => $margin->percent(),
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    public function publicPricingPayload(array $summary): array
    {
        unset(
            $summary['base_purchase_price'],
            $summary['purchase_adjustment_type'],
            $summary['purchase_adjustment_value'],
            $summary['regular_purchase_price'],
            $summary['gross_margin_amount'],
            $summary['gross_margin_percent']
        );
        return $summary;
    }

    /** @param array<string,mixed> $snapshot */
    private function regularPriceFromSnapshot(array $snapshot, string $priceKind): CatalogMoney
    {
        $variant = $snapshot['variant'];
        $currency = (string) ($variant[$priceKind . '_currency'] ?? 'CHF');
        $base = $priceKind === 'purchase' ? $variant['base_purchase_price'] : $variant['base_sale_price'];
        $adjustment = $snapshot['adjustments'][$priceKind] ?? ['adjustment_type' => 'none', 'adjustment_value' => null];
        $price = $this->validator->effectivePrice(
            $base === null ? null : (float) $base,
            (string) $adjustment['adjustment_type'],
            $adjustment['adjustment_value'] === null ? null : (float) $adjustment['adjustment_value']
        );
        return $price === null ? CatalogMoney::zero($currency) : CatalogMoney::from($price, $currency);
    }

    /** @param array<string,mixed> $snapshot */
    private function finalSalePriceFromSnapshot(array $snapshot): CatalogMoney
    {
        $regularSale = $this->resolvedSalePriceFromSnapshot($snapshot);
        $discount = $this->bestDiscount($snapshot, $regularSale);
        if ($discount === null) {
            return $regularSale;
        }
        $amount = $this->validator->discountedSalePrice(
            $regularSale->amount(),
            (string) $discount['type'],
            (float) $discount['value']
        );
        return CatalogMoney::from($amount, $regularSale->currency());
    }

    /** @param array<string,mixed> $snapshot */
    private function marginFromSnapshot(array $snapshot): CatalogMarginResult
    {
        $purchase = $this->regularPriceFromSnapshot($snapshot, 'purchase');
        $finalSale = $this->finalSalePriceFromSnapshot($snapshot);
        $amount = round($finalSale->amount() - $purchase->amount(), 2);
        $percent = $finalSale->amount() <= 0.0 ? null : round(($amount / $finalSale->amount()) * 100, 2);
        return new CatalogMarginResult(CatalogMoney::from($amount, $finalSale->currency()), $percent);
    }

    /** @param array<string,mixed> $snapshot */
    private function resolvedSalePriceFromSnapshot(array $snapshot): CatalogMoney
    {
        $regular = $this->regularPriceFromSnapshot($snapshot, 'sale');
        $rule = $this->bestPriceRule($snapshot);
        if ($rule === null) {
            return $regular;
        }
        $value = (float) $rule['adjustment_value'];
        $amount = match ((string) $rule['adjustment_type']) {
            'fixed' => $value,
            'amount_delta' => $regular->amount() + $value,
            'percent_delta' => $regular->amount() + ($regular->amount() * ($value / 100)),
            default => throw new InvalidArgumentException('business.pricing.adjustment_type_invalid'),
        };
        return CatalogMoney::from(max(0.0, round($amount, 2)), (string) ($rule['currency'] ?? $regular->currency()));
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function bestPriceRule(array $snapshot): ?array
    {
        $rules = $snapshot['price_rules'] ?? [];
        if ($rules === []) {
            return null;
        }
        $winner = $rules[0];
        if (isset($rules[1]) && $this->priceRuleRank($winner) === $this->priceRuleRank($rules[1])) {
            $winnerEffect = [(string) $winner['adjustment_type'], (float) $winner['adjustment_value'], $winner['compare_at_amount'] ?? null];
            $otherEffect = [(string) $rules[1]['adjustment_type'], (float) $rules[1]['adjustment_value'], $rules[1]['compare_at_amount'] ?? null];
            if ($winnerEffect !== $otherEffect) {
                throw new InvalidArgumentException('business.pricing.ambiguous_price_rule');
            }
        }
        return [
            'price_list_id' => (int) $winner['price_list_id'],
            'price_list_name' => (string) $winner['price_list_name'],
            'item_id' => (int) $winner['id'],
            'adjustment_type' => (string) $winner['adjustment_type'],
            'adjustment_value' => (float) $winner['adjustment_value'],
            'compare_at_amount' => $winner['compare_at_amount'] === null ? null : (float) $winner['compare_at_amount'],
            'currency' => (string) $winner['currency'],
            'channel' => (string) $winner['channel'],
            'customer_segment' => $winner['customer_segment'] ?? null,
            'priority' => (int) $winner['list_priority'],
        ];
    }

    /** @param array<string,mixed> $rule */
    private function priceRuleRank(array $rule): string
    {
        return implode(':', [
            (int) ($rule['target_rank'] ?? 1),
            (int) ($rule['segment_rank'] ?? 1),
            (int) ($rule['channel_rank'] ?? 1),
            (int) ($rule['list_priority'] ?? 100),
            (int) ($rule['priority'] ?? 100),
        ]);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function bestDiscount(array $snapshot, CatalogMoney $regularSale): ?array
    {
        $offers = $snapshot['offers'] ?? [];
        if ($offers === []) {
            return null;
        }
        $offer = $offers[0];
        $type = (string) ($offer['discount_type'] ?? $offer['offer_type']);
        $value = (float) ($offer['discount_value'] ?? $offer['offer_value']);
        if (isset($offers[1]) && $this->discountRank($offer) === $this->discountRank($offers[1])) {
            $otherType = (string) ($offers[1]['discount_type'] ?? $offers[1]['offer_type']);
            $otherValue = (float) ($offers[1]['discount_value'] ?? $offers[1]['offer_value']);
            if ($type !== $otherType || $value !== $otherValue) {
                throw new InvalidArgumentException('business.pricing.ambiguous_discount_rule');
            }
        }
        $this->validator->discountedSalePrice($regularSale->amount(), $type, $value);
        return [
            'id' => $offer['id'],
            'name' => $offer['name'],
            'type' => $type,
            'value' => $value,
            'channel' => $offer['channel'],
            'customer_segment' => $offer['customer_segment'] ?? null,
            'scope' => $offer['scope_type'],
            'scope_id' => $offer['scope_id'] ?? null,
            'priority' => $offer['priority'] ?? null,
        ];
    }

    /** @param array<string,mixed> $offer */
    private function discountRank(array $offer): string
    {
        $scopeRank = ['variant' => 0, 'product' => 1, 'category' => 2, 'brand' => 3][(string) ($offer['scope_type'] ?? 'brand')] ?? 4;
        return implode(':', [
            $scopeRank,
            ($offer['customer_segment'] ?? null) === null ? 1 : 0,
            ($offer['channel'] ?? 'all') === 'all' ? 1 : 0,
            (int) ($offer['priority'] ?? 100),
        ]);
    }

    private function moneyOrNull(mixed $amount, string $currency): ?string
    {
        return $amount === null ? null : CatalogMoney::from($amount, $currency)->formatted();
    }
}
