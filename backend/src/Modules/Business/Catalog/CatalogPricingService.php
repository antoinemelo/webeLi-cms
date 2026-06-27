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

    public function calculateFinalSalePrice(int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null): CatalogMoney
    {
        $snapshot = $this->repository->pricingSnapshot($variantId, $channel, $at);
        return $this->finalSalePriceFromSnapshot($snapshot);
    }

    public function calculateMargin(int $variantId, ?string $channel = null): CatalogMarginResult
    {
        $snapshot = $this->repository->pricingSnapshot($variantId, $channel);
        return $this->marginFromSnapshot($snapshot);
    }

    /** @return array<string,mixed> */
    public function pricingSummary(int $variantId, ?string $channel = null, ?DateTimeImmutable $at = null): array
    {
        $snapshot = $this->repository->pricingSnapshot($variantId, $channel, $at);
        $variant = $snapshot['variant'];
        $purchase = $this->regularPriceFromSnapshot($snapshot, 'purchase');
        $sale = $this->regularPriceFromSnapshot($snapshot, 'sale');
        $finalSale = $this->finalSalePriceFromSnapshot($snapshot);
        $margin = $this->marginFromSnapshot($snapshot);
        $activeDiscount = $this->bestDiscount($snapshot, $sale);

        return [
            'variant_id' => $variant['variant_id'],
            'product_id' => $variant['product_id'],
            'sku' => $variant['sku'],
            'currency' => $purchase->currency(),
            'base_purchase_price' => $this->moneyOrNull($variant['base_purchase_price'], $purchase->currency()),
            'purchase_adjustment_type' => $snapshot['adjustments']['purchase']['adjustment_type'],
            'purchase_adjustment_value' => $snapshot['adjustments']['purchase']['adjustment_value'],
            'regular_purchase_price' => $purchase->formatted(),
            'base_sale_price' => $this->moneyOrNull($variant['base_sale_price'], $sale->currency()),
            'sale_adjustment_type' => $snapshot['adjustments']['sale']['adjustment_type'],
            'sale_adjustment_value' => $snapshot['adjustments']['sale']['adjustment_value'],
            'regular_sale_price' => $sale->formatted(),
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
        $regularSale = $this->regularPriceFromSnapshot($snapshot, 'sale');
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

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function bestDiscount(array $snapshot, CatalogMoney $regularSale): ?array
    {
        foreach ($snapshot['offers'] as $offer) {
            $type = (string) ($offer['discount_type'] ?? $offer['offer_type']);
            $value = (float) ($offer['discount_value'] ?? $offer['offer_value']);
            $this->validator->discountedSalePrice($regularSale->amount(), $type, $value);
            return [
                'id' => $offer['id'],
                'name' => $offer['name'],
                'type' => $type,
                'value' => $value,
                'channel' => $offer['channel'],
                'scope' => $offer['scope_type'],
                'scope_id' => $offer['scope_id'] ?? null,
                'priority' => $offer['priority'] ?? null,
            ];
        }
        return null;
    }

    private function moneyOrNull(mixed $amount, string $currency): ?string
    {
        return $amount === null ? null : CatalogMoney::from($amount, $currency)->formatted();
    }
}
