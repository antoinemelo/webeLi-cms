<?php

declare(strict_types=1);

namespace App\Security;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\ApiException;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Repository\AuthRepository;
use App\Security\SessionManager;

final class AdminApiRequestGuard
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    /** Contract header required by validators and clients: X-Contract-Version. */
    private const HEADER_CONTRACT_VERSION = AdminApiContract::HEADER_CONTRACT_VERSION;
    /** Backward-compatible query parameter for local tools and explicit contract probing: contract_version. */
    private const QUERY_CONTRACT_VERSION = 'contract_version';
    /** Contract header required for mutating admin API requests: X-CSRF-Token. */
    private const HEADER_CSRF = AdminApiContract::HEADER_CSRF;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly Request $request,
        private readonly AuthRepository $auth,
        private readonly array $config = [],
    ) {}

    public function enforce(): void
    {
        if (!$this->isAdminApiRequest()) {
            return;
        }

        $this->auth->requireAuth();
        if (!SessionManager::enforceIdleTimeout((int) ($this->config['app']['session_idle_timeout'] ?? 3600))) {
            throw new ApiException(ErrorCode::AUTH_REQUIRED, ErrorCode::message(ErrorCode::AUTH_REQUIRED), ErrorCode::httpStatus(ErrorCode::AUTH_REQUIRED));
        }

        if (!$this->acceptsNativeContract()) {
            throw new ApiException(
                ErrorCode::CONTRACT_VERSION_UNSUPPORTED,
                ErrorCode::message(ErrorCode::CONTRACT_VERSION_UNSUPPORTED),
                ErrorCode::httpStatus(ErrorCode::CONTRACT_VERSION_UNSUPPORTED),
                ['supported' => [AdminApiContract::VERSION], 'received' => $this->requestedContractVersion()],
            );
        }

        if (!$this->isMutatingRequest()) {
            return;
        }

        if (!$this->hasSupportedWriteContentType()) {
            throw new ApiException(
                ErrorCode::JSON_CONTENT_TYPE_REQUIRED,
                ErrorCode::message(ErrorCode::JSON_CONTENT_TYPE_REQUIRED),
                ErrorCode::httpStatus(ErrorCode::JSON_CONTENT_TYPE_REQUIRED),
            );
        }

        $token = $this->request->header(self::HEADER_CSRF);
        if (!Csrf::verifyHeader($token)) {
            $this->auth->audit(
                (int) ($this->auth->user()['id'] ?? 0) ?: null,
                'security.csrf_rejected',
                'admin_api',
                null,
                [
                    'path' => $this->request->path,
                    'method' => $this->request->method,
                    'reason' => 'missing_or_invalid_x_csrf_token',
                ],
            );
            throw new ApiException(
                ErrorCode::CSRF_TOKEN_REJECTED,
                ErrorCode::message(ErrorCode::CSRF_TOKEN_REJECTED),
                ErrorCode::httpStatus(ErrorCode::CSRF_TOKEN_REJECTED),
            );
        }

        // Force JSON decoding at the perimeter so INVALID_JSON is emitted by
        // the API exception contract before any controller executes. Multipart
        // media upload is the only native admin API write that carries files.
        if ($this->hasJsonContentType()) {
            $this->request->json();
        }
    }

    private function isAdminApiRequest(): bool
    {
        return str_starts_with($this->request->path, '/admin/api');
    }

    private function isMutatingRequest(): bool
    {
        return in_array($this->request->method, self::MUTATING_METHODS, true);
    }

    private function hasSupportedWriteContentType(): bool
    {
        if ($this->hasJsonContentType()) {
            return true;
        }

        return $this->request->method === 'POST'
            && in_array($this->request->path, ['/admin/api/media', '/admin/api/imports-exports/imports/inspect'], true)
            && str_contains($this->request->contentType(), 'multipart/form-data');
    }

    private function hasJsonContentType(): bool
    {
        return str_contains($this->request->contentType(), 'application/json');
    }

    private function acceptsNativeContract(): bool
    {
        $version = $this->requestedContractVersion();
        return $version === null || $version === '' || $version === AdminApiContract::VERSION;
    }

    private function requestedContractVersion(): ?string
    {
        $headerVersion = $this->request->header(self::HEADER_CONTRACT_VERSION);
        if ($headerVersion !== null && $headerVersion !== '') {
            return $headerVersion;
        }

        if (isset($this->request->query[self::QUERY_CONTRACT_VERSION])) {
            return (string) $this->request->query[self::QUERY_CONTRACT_VERSION];
        }

        return null;
    }
}
