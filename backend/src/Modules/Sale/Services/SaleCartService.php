<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;

final class SaleCartService
{
    public function __construct(
        private readonly SaleCartRepository $carts,
        private readonly SaleChannelRepository $channels,
        private readonly SaleCatalogSnapshotService $catalog,
        private readonly SalePricingService $pricing,
        private readonly SaleInventoryService $inventory,
        private readonly SaleEventService $events,
        private readonly SaleIdempotencyService $idempotency
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createCart(int $siteId, int $channelId, array $payload = []): array
    {
        $channel = $this->channels->requireChannel($siteId, $channelId);
        $expectedKind=match((string)($channel['type']??'admin')){'storefront'=>'web','pos'=>'pos',default=>'admin'};
        $kind=(string)($payload['cart_kind']??$expectedKind);
        if ($kind!==$expectedKind) throw new SaleValidationException('sale.cart_kind_channel_mismatch');
        if ($kind==='pos' && empty($payload['iam_user_id'])) throw new SaleValidationException('sale.cart_pos_auth_required');
        if ($kind!=='pos' && !empty($payload['register_session_id'])) throw new SaleValidationException('sale.cart_register_session_forbidden');
        $cart = $this->carts->create($siteId, $channelId, [
            'currency'=>$channel['currency']??'CHF','locale'=>$payload['locale']??$channel['default_locale']??'fr','cart_kind'=>$kind,
            'expires_at'=>$kind==='web'?($payload['expires_at']??gmdate('Y-m-d H:i:s',time()+2592000)):null,
        ] + $payload);
        $this->events->emit($siteId, 'sale.cart.created', 'cart', (int) $cart['id'], [
            'site_id' => $siteId,
            'cart_id' => (int) $cart['id'],
            'channel_id' => $channelId,
            'source' => $payload['source'] ?? 'admin',
            'iam_user_id' => $payload['iam_user_id'] ?? null,
        ], $payload['iam_user_id'] ?? null);
        return $cart;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function addLine(int $cartId, int $businessVariantId, int $quantity, array $payload = []): array
    {
        if ($quantity < 1) {
            throw new SaleValidationException('sale.quantity_invalid');
        }
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $request = ['cart_id'=>$cartId,'sellable_id'=>$businessVariantId,'quantity'=>$quantity,'options'=>$payload['options']??[],'personalization'=>$payload['personalization']??[]];
        $replayed = $this->idempotency->completed((int) $cart['site_id'], 'cart.add_line', $payload['idempotency_key'] ?? null, $request);
        if ($replayed !== null) {
            return $replayed;
        }
        $channel = $this->channels->requireChannel((int) $cart['site_id'], (int) $cart['channel_id']);
        $snapshot = $this->catalog->snapshotForVariant(
            (int) $cart['site_id'],
            $businessVariantId,
            $this->channels->channelCodeForCatalog($channel),
            true,
            [
                'currency' => (string) ($cart['currency'] ?? $channel['currency'] ?? 'CHF'),
                'customer_segment' => $payload['customer_segment'] ?? null,
            ]
        );
        $snapshot['line_options']=$this->validatedObject($payload['options']??[],'sale.cart_options_invalid');
        $snapshot['personalization']=$this->validatedObject($payload['personalization']??[],'sale.cart_personalization_invalid');
        $snapshot['fulfillment_class']=match((string)($snapshot['product_type']??'physical')){'service'=>'none','gift_card','digital'=>'digital',default=>'shipping'};
        $snapshot['availability_state']=(string)($snapshot['availability']['status']??'available');
        if ($snapshot['availability_state']==='in_stock') $snapshot['availability_state']='available';
        if (!in_array($snapshot['availability_state'],['available','backorder','unavailable','contact_us'],true)) $snapshot['availability_state']='available';
        $snapshot['calculation_version']=1;
        $amounts = $this->pricing->lineAmounts($snapshot);
        $this->carts->claimVersion($cartId, isset($payload['expected_version']) ? (int) $payload['expected_version'] : null);
        $this->inventory->reserveForCart((int) $cart['site_id'], $cartId, $snapshot, $quantity);
        $line = $this->carts->addOrIncrementLine($cartId, $snapshot, $quantity, $amounts);
        $this->carts->markCheckoutDirty($cartId);
        $totals = $this->carts->recalculateTotals($cartId);
        $response = ['cart' => $this->carts->cartWithLines($cartId), 'line' => $line, 'totals' => $totals];
        $this->events->emit((int) $cart['site_id'], 'sale.cart.line_added', 'cart', $cartId, [
            'site_id' => (int) $cart['site_id'],
            'cart_id' => $cartId,
            'line_id' => (int) $line['id'],
            'business_variant_id' => $businessVariantId, 'sellable_id'=>$businessVariantId,
            'quantity' => $quantity,
            'sku' => $line['sku'] ?? $snapshot['sku'] ?? null,
            'unit_price_minor' => (int) $line['unit_price_minor'],
            'currency' => (string) $line['currency'],
            'iam_user_id' => $payload['iam_user_id'] ?? null,
        ], $payload['iam_user_id'] ?? null);
        $this->idempotency->complete((int) $cart['site_id'], 'cart.add_line', $payload['idempotency_key'] ?? null, $request, $response);
        return $response;
    }

    /** @return array<string,mixed> */
    public function updateLineQuantity(int $cartId, int $lineId, int $quantity, ?int $expectedVersion = null): array
    {
        if ($quantity < 1) {
            throw new SaleValidationException('sale.quantity_invalid');
        }
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $existing = $this->carts->requireLine($lineId);
        if ((int) $existing['cart_id'] !== $cartId) {
            throw new SaleValidationException('sale.cart_line_not_found');
        }
        $metadata = json_decode((string) ($existing['metadata_json'] ?? '{}'), true);
        $snapshot = is_array($metadata) && is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $allowBackorder = (bool) ($snapshot['allow_backorder'] ?? $snapshot['metadata']['allow_backorder'] ?? false);
        $channel=$this->channels->requireChannel((int)$cart['site_id'],(int)$cart['channel_id']);
        $fresh=$this->catalog->snapshotForVariant(
            (int) $cart['site_id'],
            (int) ($existing['sellable_id'] ?? $existing['business_variant_id']),
            $this->channels->channelCodeForCatalog($channel),
            true,
            ['currency' => (string) $cart['currency']]
        );
        $fresh['availability_state'] = $this->availabilityState($fresh);
        $this->carts->claimVersion($cartId, $expectedVersion);
        if ($quantity > (int) $existing['quantity']) {
            $this->inventory->syncCartLineReservation((int) $cart['site_id'], $cartId, (int) $existing['business_variant_id'], $quantity, $allowBackorder);
        }
        $this->carts->refreshLineSnapshot($lineId,$fresh,$this->pricing->lineAmounts($fresh));
        $line = $this->carts->updateLineQuantity($cartId, $lineId, $quantity);
        if ($quantity < (int) $existing['quantity']) {
            $this->inventory->syncCartLineReservation((int) $cart['site_id'], $cartId, (int) $line['business_variant_id'], $quantity, $allowBackorder);
        }
        $this->carts->markCheckoutDirty($cartId);
        return $line;
    }

    public function deleteLine(int $cartId, int $lineId, ?int $expectedVersion = null): void
    {
        $cart = $this->carts->requireCart($cartId);
        if ((string) $cart['status'] !== 'active') {
            throw new SaleValidationException('sale.cart_not_active');
        }
        $line = $this->carts->requireLine($lineId);
        if ((int) $line['cart_id'] !== $cartId) {
            throw new SaleValidationException('sale.cart_line_not_found');
        }
        $this->carts->claimVersion($cartId, $expectedVersion);
        $this->carts->deleteLine($cartId, $lineId);
        $this->carts->markCheckoutDirty($cartId);
        $this->inventory->releaseCartVariantReservations($cartId, (int) $line['business_variant_id'], 'cart line deleted');
    }

    /** @return array<string,mixed> */
    public function recalculate(int $cartId, ?int $expectedVersion = null): array
    {
        $cart=$this->carts->requireCart($cartId); if ((string)$cart['status']!=='active') throw new SaleValidationException('sale.cart_not_active');
        $snapshots=[];
        foreach ($this->carts->lines($cartId) as $line) {
            $channel=$this->channels->requireChannel((int)$cart['site_id'],(int)$cart['channel_id']);
            $fresh=$this->catalog->snapshotForVariant(
                (int) $cart['site_id'],
                (int) ($line['sellable_id'] ?? $line['business_variant_id']),
                $this->channels->channelCodeForCatalog($channel),
                true,
                ['currency' => (string) $cart['currency']]
            );
            $fresh['availability_state'] = $this->availabilityState($fresh);
            $snapshots[] = [(int) $line['id'], $fresh, $this->pricing->lineAmounts($fresh)];
        }
        $this->carts->claimVersion($cartId, $expectedVersion);
        foreach ($snapshots as [$lineId, $fresh, $amounts]) {
            $this->carts->refreshLineSnapshot($lineId, $fresh, $amounts);
        }
        $this->carts->recalculateTotals($cartId); return $this->carts->cartWithLines($cartId);
    }

    /** Merge an anonymous web cart into an authenticated account cart. */
    public function mergeGuestIntoAccount(int $guestCartId,int $accountCartId,int $customerRefId,?int $expectedTargetVersion=null): array
    {
        if ($guestCartId===$accountCartId || $customerRefId<1) throw new SaleValidationException('sale.cart_merge_invalid');
        $guest=$this->carts->requireCart($guestCartId); $target=$this->carts->requireCart($accountCartId);
        foreach ([$guest,$target] as $cart) if (($cart['cart_kind']??null)!=='web'||($cart['status']??null)!=='active') throw new SaleValidationException('sale.cart_merge_forbidden');
        if ((int)$guest['site_id']!==(int)$target['site_id']||(int)$guest['channel_id']!==(int)$target['channel_id']||(string)$guest['currency']!==(string)$target['currency']) throw new SaleValidationException('sale.cart_merge_context_mismatch');
        if (!empty($guest['customer_ref_id'])||(!empty($target['customer_ref_id'])&&(int)$target['customer_ref_id']!==$customerRefId)) throw new SaleValidationException('sale.cart_merge_identity_mismatch');
        if ($expectedTargetVersion!==null && (int)$target['version']!==$expectedTargetVersion) throw new SaleValidationException('sale.cart_version_conflict');
        foreach ($this->carts->lines($guestCartId) as $line) {
            $this->addLine($accountCartId,(int)($line['sellable_id']??$line['business_variant_id']),(int)$line['quantity'],[
                'options'=>json_decode((string)($line['options_json']??'{}'),true)?:[],
                'personalization'=>json_decode((string)($line['personalization_json']??'{}'),true)?:[],
                'idempotency_key'=>'merge-'.$guestCartId.'-'.$line['id'],
            ]);
        }
        $this->inventory->releaseCartReservations($guestCartId,'guest cart merged');
        $this->carts->rawDatabase()->run("UPDATE sale_carts SET status='abandoned',abandoned_at=CURRENT_TIMESTAMP,customer_ref_id=? WHERE id=?",[$customerRefId,$guestCartId]);
        $this->carts->rawDatabase()->run('UPDATE sale_carts SET customer_ref_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$customerRefId,$accountCartId]);
        return $this->carts->cartWithLines($accountCartId);
    }

    /** @return array<string,mixed> */
    private function validatedObject(mixed $value,string $error): array
    {
        if (!is_array($value)) throw new SaleValidationException($error);
        foreach ($value as $key=>$item) if (!is_string($key)||(!is_scalar($item)&&$item!==null)) throw new SaleValidationException($error);
        if (strlen(json_encode($value)?:'')>4096) throw new SaleValidationException($error);
        return $value;
    }

    /** @param array<string,mixed> $snapshot */
    private function availabilityState(array $snapshot): string
    {
        $state = (string) ($snapshot['availability_state'] ?? $snapshot['availability']['status'] ?? 'available');
        if ($state === 'in_stock') {
            return 'available';
        }
        return in_array($state, ['available', 'backorder', 'unavailable', 'contact_us'], true) ? $state : 'available';
    }
}
