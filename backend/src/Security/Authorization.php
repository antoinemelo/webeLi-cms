<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\ApiException;
use App\Core\ErrorCode;
use App\Repository\AuthRepository;

final class Authorization
{
    public function __construct(private readonly AuthRepository $auth) {}

    public function require(string $permission, ?int $siteId = null): void
    {
        if (!$this->auth->hasPermission($permission, $siteId)) {
            throw new ApiException(ErrorCode::AUTHZ_FORBIDDEN, ErrorCode::message(ErrorCode::AUTHZ_FORBIDDEN), ErrorCode::httpStatus(ErrorCode::AUTHZ_FORBIDDEN));
        }
    }

    public function requireSiteAccess(int $siteId): void
    {
        if (!$this->auth->canAccessSite($siteId)) {
            throw new ApiException(ErrorCode::AUTHZ_FORBIDDEN, ErrorCode::message(ErrorCode::AUTHZ_FORBIDDEN), ErrorCode::httpStatus(ErrorCode::AUTHZ_FORBIDDEN), [
                'site_id' => $siteId,
            ]);
        }
    }
}
