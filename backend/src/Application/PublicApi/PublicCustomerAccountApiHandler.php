<?php

declare(strict_types=1);

namespace App\Application\PublicApi;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Services\SaleCustomerAccountService;
use App\Repository\SiteRepository;
use Throwable;

final class PublicCustomerAccountApiHandler
{
    private const COOKIE = 'amcms_customer_session';

    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly SaleCustomerAccountService $accounts,
    ) {}

    public function register(): Response
    {
        return $this->run(function (int $siteId, array $payload): Response {
            $result = $this->accounts->registerWithProof($siteId, (string) ($payload['proof_token'] ?? ''), (string) ($payload['password'] ?? ''));
            $token = (string) $result['session_token'];
            unset($result['session_token']);
            return $this->ok($result, 'public.customer.accounts.register.v1', 201, $this->cookie($token));
        });
    }

    public function login(): Response
    {
        return $this->run(function (int $siteId, array $payload): Response {
            $result = $this->accounts->login($siteId, (string) ($payload['email'] ?? ''), (string) ($payload['password'] ?? ''));
            $token = (string) $result['session_token'];
            unset($result['session_token']);
            return $this->ok($result, 'public.customer.login.v1', 200, $this->cookie($token));
        });
    }

    public function logout(): Response
    {
        return $this->run(function (int $siteId): Response {
            $token = (string) ($this->request->cookies[self::COOKIE] ?? '');
            if ($token !== '') $this->accounts->logout($siteId, $token);
            return $this->ok(['logged_out' => true], 'public.customer.logout.v1', 200, $this->cookie('', 0));
        });
    }

    public function me(): Response
    {
        return $this->run(function (int $siteId): Response {
            $user = $this->auth($siteId);
            return $this->ok(['user' => $this->accounts->customerForAccount($siteId, (int) $user['user_id'])], 'public.customer.me.v1');
        });
    }

    public function updateProfile(): Response
    {
        return $this->run(function (int $siteId, array $payload): Response {
            $user = $this->auth($siteId);
            if (isset($payload['new_email'])) {
                $this->accounts->changeEmail($siteId, (int) $user['user_id'], (string) ($payload['current_password'] ?? ''), (string) $payload['new_email']);
            }
            $payload['iam_user_id'] = (int) $user['user_id'];
            return $this->ok($this->accounts->createOrUpdateProfile($siteId, $payload), 'public.customer.me.update.v1');
        });
    }

    public function claimOrder(): Response
    {
        return $this->run(function (int $siteId, array $payload): Response {
            $user = $this->auth($siteId);
            return $this->ok(['order' => $this->accounts->claimForAuthenticatedAccount($siteId, (int) $user['user_id'], (string) ($payload['proof_token'] ?? ''))], 'public.customer.orders.claim.v1');
        });
    }

    public function orders(): Response
    {
        return $this->run(function (int $siteId): Response {
            $user = $this->auth($siteId);
            return $this->ok(['orders' => $this->accounts->orders($siteId, (int) $user['user_id'])], 'public.customer.orders.index.v1');
        });
    }

    public function order(string|int $id): Response
    {
        return $this->run(function (int $siteId) use ($id): Response {
            $user = $this->auth($siteId);
            return $this->ok(['order' => $this->accounts->order($siteId, (int) $user['user_id'], (int) $id)], 'public.customer.orders.show.v1');
        });
    }

    public function guestTracking(string $token): Response
    {
        return $this->run(function (int $siteId) use ($token): Response {
            return $this->ok(['order' => $this->accounts->guestOrderByProof($siteId, $token)], 'public.customer.order_tracking.show.v1');
        });
    }

    public function addresses(): Response
    {
        return $this->run(function (int $siteId): Response {
            $user = $this->auth($siteId);
            return $this->ok(['addresses' => $this->accounts->addresses($siteId, (int) $user['user_id'])], 'public.customer.addresses.index.v1');
        });
    }

    public function storeAddress(): Response
    {
        return $this->run(function (int $siteId, array $payload): Response {
            $user = $this->auth($siteId);
            $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
            return $this->ok(['address' => $this->accounts->saveAddress($siteId, (int) $user['user_id'], (string) ($payload['label'] ?? ''), (string) ($payload['type'] ?? 'both'), $address, ($payload['is_default'] ?? false) === true)], 'public.customer.addresses.store.v1', 201);
        });
    }

    public function requestReturn(string|int $id): Response
    {
        return $this->run(function (int $siteId, array $payload) use ($id): Response {
            $user = $this->auth($siteId);
            $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
            return $this->ok(['return' => $this->accounts->requestReturn($siteId, (int) $user['user_id'], (int) $id, $lines, isset($payload['reason']) ? (string) $payload['reason'] : null, $this->request->header('Idempotency-Key'))], 'public.customer.orders.returns.store.v1', 201);
        });
    }

    /** @param callable(int,array<string,mixed>):Response $callback */
    private function run(callable $callback): Response
    {
        try {
            $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
            $payload = $this->request->json();
            if ($payload === []) $payload = $this->request->post;
            $payload = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            return $callback((int) $site['id'], $payload);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'authentication_required') || str_contains($message, 'invalid_credentials') ? 401 : (str_contains($message, 'not_found') ? 404 : 422);
            return Response::error($status === 401 ? 'AUTH_REQUIRED' : 'VALIDATION_FAILED', $message, $status, [], ['Cache-Control' => 'no-store']);
        }
    }

    /** @return array<string,mixed> */
    private function auth(int $siteId): array
    {
        return $this->accounts->authenticate($siteId, isset($this->request->cookies[self::COOKIE]) ? (string) $this->request->cookies[self::COOKIE] : null);
    }

    /** @param array<string,string> $headers */
    private function ok(array $data, string $contract, int $status = 200, array $headers = []): Response
    {
        return Response::success($data, $contract, [], $status, $headers + ['Cache-Control' => 'no-store']);
    }

    /** @return array<string,string> */
    private function cookie(string $token, int $maxAge = 2592000): array
    {
        $secure = (($this->request->server['HTTPS'] ?? '') !== '' && ($this->request->server['HTTPS'] ?? '') !== 'off') ? '; Secure' : '';
        return ['Set-Cookie' => self::COOKIE . '=' . rawurlencode($token) . '; Path=/; Max-Age=' . $maxAge . '; HttpOnly; SameSite=Lax' . $secure];
    }
}
