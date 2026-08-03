<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Accounting\Services\AccountingChartService;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;
use InvalidArgumentException;
use PDOException;

final class AccountingAdminApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly AccountingChartService $charts,
    ) {}

    public function structure(): Response
    {
        [$site, $language] = $this->authorize('accounting.read');
        $periodId = isset($this->request->query['fiscal_period_id']) ? (int)$this->request->query['fiscal_period_id'] : null;
        return $this->success($this->charts->structure((int)$site['id'], $periodId), 'admin.accounting.structure.v1', $site, $language);
    }

    public function storeRule(): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId, array $payload): array {
            return ['rule'=>$this->charts->saveRule($siteId, null, $payload, $this->actorId()),'message'=>'Règle ajoutée.'];
        }, 'admin.accounting.rule.v1', 201);
    }

    public function updateRule(string|int $id): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId, array $payload) use ($id): array {
            return ['rule'=>$this->charts->saveRule($siteId, $this->id($id), $payload, $this->actorId()),'message'=>'Règle mise à jour.'];
        }, 'admin.accounting.rule.v1');
    }

    public function deleteRule(string|int $id): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId) use ($id): array {
            $resolved = $this->id($id);
            $this->charts->deleteRule($siteId, $resolved);
            return ['id'=>$resolved,'deleted'=>true];
        }, 'admin.accounting.rule.delete.v1');
    }

    public function storeCategory(): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId, array $payload): array {
            return ['category'=>$this->charts->saveCategory($siteId, null, $payload, $this->actorId()),'message'=>'Rubrique ajoutée.'];
        }, 'admin.accounting.category.v1', 201);
    }

    public function updateCategory(string|int $id): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId, array $payload) use ($id): array {
            return ['category'=>$this->charts->saveCategory($siteId, $this->id($id), $payload, $this->actorId()),'message'=>'Rubrique mise à jour.'];
        }, 'admin.accounting.category.v1');
    }

    public function deleteCategory(string|int $id): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId) use ($id): array {
            $resolved = $this->id($id);
            $this->charts->deleteCategory($siteId, $resolved);
            return ['id'=>$resolved,'deleted'=>true];
        }, 'admin.accounting.category.delete.v1');
    }

    public function storeAccount(): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId, array $payload): array {
            return ['account'=>$this->charts->saveAccount($siteId, null, $payload, $this->actorId()),'message'=>'Compte ajouté.'];
        }, 'admin.accounting.account.v1', 201);
    }

    public function updateAccount(string|int $id): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId, array $payload) use ($id): array {
            return ['account'=>$this->charts->saveAccount($siteId, $this->id($id), $payload, $this->actorId()),'message'=>'Compte mis à jour.'];
        }, 'admin.accounting.account.v1');
    }

    public function deleteAccount(string|int $id): Response
    {
        return $this->mutation('accounting.chart.manage', function (int $siteId) use ($id): array {
            return $this->charts->deleteAccount($siteId, $this->id($id), $this->actorId());
        }, 'admin.accounting.account.delete.v1');
    }

    public function storeFiscalPeriod(): Response
    {
        return $this->mutation('accounting.opening.manage', function (int $siteId, array $payload): array {
            return ['fiscal_period'=>$this->charts->createFiscalPeriod($siteId, $payload, $this->actorId()),'message'=>'Exercice créé.'];
        }, 'admin.accounting.fiscal-period.v1', 201);
    }

    public function saveOpeningBalances(string|int $id): Response
    {
        return $this->mutation('accounting.opening.manage', function (int $siteId, array $payload) use ($id): array {
            $balances = $payload['balances'] ?? null;
            if (!is_array($balances)) throw new InvalidArgumentException('balances doit être une liste.');
            return ['opening_balances'=>$this->charts->saveOpeningBalances($siteId, $this->id($id), $balances, $this->actorId()),'message'=>'Soldes d’ouverture enregistrés.'];
        }, 'admin.accounting.opening-balances.v1');
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function authorize(string $permission, array $payload = []): array
    {
        $this->auth->requireAuth();
        $requestedSite = $payload['site_id'] ?? $this->request->query['site_id'] ?? null;
        $site = AdminApiContract::siteContext($this->request, $this->sites, $requestedSite !== null ? (int)$requestedSite : null, $this->auth);
        $this->authorization->require($permission, (int)$site['id']);
        return [$site, AdminApiContract::language($this->request, $this->sites, $site, $payload)];
    }

    private function mutation(string $permission, callable $callback, string $contract, int $status = 200): Response
    {
        try {
            $payload = AdminApiContract::dataPayload($this->request, true);
            [$site, $language] = $this->authorize($permission, $payload);
            return $this->success($callback((int)$site['id'], $payload), $contract, $site, $language, $status);
        } catch (InvalidArgumentException $e) {
            return Response::validation(['accounting'=>[$e->getMessage()]], 'La configuration comptable contient des erreurs.');
        } catch (PDOException $e) {
            $message = str_contains(strtolower($e->getMessage()), 'unique')
                ? 'Ce numéro ou préfixe existe déjà.'
                : 'La modification est incompatible avec les données comptables existantes.';
            return Response::validation(['accounting'=>[$message]], 'La configuration comptable contient des erreurs.');
        }
    }

    private function success(mixed $data, string $contract, array $site, string $language, int $status = 200): Response
    {
        return Response::success($data, $contract, AdminApiContract::meta($site, $language), $status);
    }

    private function actorId(): ?int
    {
        $user = $this->auth->user();
        return isset($user['id']) ? (int)$user['id'] : null;
    }

    private function id(string|int $id): int
    {
        $resolved = (int)$id;
        if ($resolved < 1) throw new InvalidArgumentException('Identifiant invalide.');
        return $resolved;
    }
}
