<?php

declare(strict_types=1);

namespace App\Core;

final class ErrorCode
{
    public const API_ERROR = 'API_ERROR';
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const AUTHZ_FORBIDDEN = 'AUTHZ_FORBIDDEN';
    public const CONTENT_ENTRY_NOT_FOUND = 'CONTENT_ENTRY_NOT_FOUND';
    public const CONTENT_TYPE_NOT_FOUND = 'CONTENT_TYPE_NOT_FOUND';
    public const CONTRACT_VERSION_UNSUPPORTED = 'CONTRACT_VERSION_UNSUPPORTED';
    public const CSRF_TOKEN_REJECTED = 'CSRF_TOKEN_REJECTED';
    public const INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
    public const INVALID_JSON = 'INVALID_JSON';
    public const JSON_CONTENT_TYPE_REQUIRED = 'JSON_CONTENT_TYPE_REQUIRED';
    public const JSON_ENCODING_FAILED = 'JSON_ENCODING_FAILED';
    public const LANGUAGE_NOT_ENABLED = 'LANGUAGE_NOT_ENABLED';
    public const MEDIA_NOT_FOUND = 'MEDIA_NOT_FOUND';
    public const MEDIA_UPLOAD_FAILED = 'MEDIA_UPLOAD_FAILED';
    public const MENU_NOT_FOUND = 'MENU_NOT_FOUND';
    public const PREVIEW_NOT_FOUND = 'PREVIEW_NOT_FOUND';
    public const PUBLIC_CONTENT_NOT_FOUND = 'PUBLIC_CONTENT_NOT_FOUND';
    public const PUBLICATION_CONFLICT = 'PUBLICATION_CONFLICT';
    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    public const RESOURCE_LOCKED = 'RESOURCE_LOCKED';
    public const REVISION_CONFLICT = 'REVISION_CONFLICT';
    public const ROUTE_CONFLICT = 'ROUTE_CONFLICT';
    public const ROUTE_NOT_FOUND = 'ROUTE_NOT_FOUND';
    public const SITE_NOT_FOUND = 'SITE_NOT_FOUND';
    public const TAXONOMY_NOT_FOUND = 'TAXONOMY_NOT_FOUND';
    public const TAXONOMY_TERM_NOT_FOUND = 'TAXONOMY_TERM_NOT_FOUND';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public static function all(): array
    {
        return [
            self::API_ERROR, self::AUTH_REQUIRED, self::AUTHZ_FORBIDDEN,
            self::CONTENT_ENTRY_NOT_FOUND, self::CONTENT_TYPE_NOT_FOUND,
            self::CONTRACT_VERSION_UNSUPPORTED, self::CSRF_TOKEN_REJECTED,
            self::INTERNAL_SERVER_ERROR, self::INVALID_JSON, self::JSON_CONTENT_TYPE_REQUIRED,
            self::JSON_ENCODING_FAILED, self::LANGUAGE_NOT_ENABLED, self::MEDIA_NOT_FOUND,
            self::MEDIA_UPLOAD_FAILED, self::MENU_NOT_FOUND, self::PREVIEW_NOT_FOUND,
            self::PUBLIC_CONTENT_NOT_FOUND, self::PUBLICATION_CONFLICT, self::RATE_LIMIT_EXCEEDED, self::RESOURCE_LOCKED,
            self::REVISION_CONFLICT, self::ROUTE_CONFLICT, self::ROUTE_NOT_FOUND,
            self::SITE_NOT_FOUND, self::TAXONOMY_NOT_FOUND, self::TAXONOMY_TERM_NOT_FOUND, self::VALIDATION_FAILED,
        ];
    }

    public static function isKnown(string $code): bool { return in_array($code, self::all(), true); }

    public static function message(string $code): string
    {
        return match ($code) {
            self::API_ERROR => 'Erreur API.',
            self::AUTH_REQUIRED => 'Authentification requise.',
            self::AUTHZ_FORBIDDEN => 'Action non autorisée.',
            self::CONTENT_ENTRY_NOT_FOUND => 'L’entrée demandée est introuvable.',
            self::CONTENT_TYPE_NOT_FOUND => 'Le type de contenu demandé est introuvable.',
            self::CONTRACT_VERSION_UNSUPPORTED => 'La version de contrat demandée n’est pas supportée.',
            self::CSRF_TOKEN_REJECTED => 'Le jeton de sécurité est invalide ou expiré.',
            self::INTERNAL_SERVER_ERROR => 'Erreur interne du serveur.',
            self::INVALID_JSON => 'Le corps de la requête n’est pas un JSON valide.',
            self::JSON_CONTENT_TYPE_REQUIRED => 'Les écritures admin API doivent utiliser Content-Type: application/json.',
            self::JSON_ENCODING_FAILED => 'La réponse JSON n’a pas pu être sérialisée.',
            self::LANGUAGE_NOT_ENABLED => 'La langue demandée n’est pas active pour le site courant.',
            self::MEDIA_NOT_FOUND => 'Le média demandé est introuvable.',
            self::MEDIA_UPLOAD_FAILED => 'Le média n’a pas pu être téléversé.',
            self::MENU_NOT_FOUND => 'Le menu demandé est introuvable.',
            self::PREVIEW_NOT_FOUND => 'L’aperçu demandé est introuvable.',
            self::PUBLIC_CONTENT_NOT_FOUND => 'Le contenu demandé est introuvable.',
            self::PUBLICATION_CONFLICT => 'La publication est en conflit avec l’état courant du contenu.',
            self::RATE_LIMIT_EXCEEDED => 'Trop de requêtes. Réessayez plus tard.',
            self::RESOURCE_LOCKED => 'La ressource est verrouillée.',
            self::REVISION_CONFLICT => 'La révision demandée n’est plus la révision de travail courante.',
            self::ROUTE_CONFLICT => 'La route demandée est déjà utilisée.',
            self::ROUTE_NOT_FOUND => 'La route demandée est introuvable.',
            self::SITE_NOT_FOUND => 'Le site demandé est introuvable.',
            self::TAXONOMY_NOT_FOUND => 'La taxonomie demandée est introuvable.',
            self::TAXONOMY_TERM_NOT_FOUND => 'Le terme demandé est introuvable.',
            self::VALIDATION_FAILED => 'Le contenu contient des erreurs.',
            default => 'Une erreur est survenue.',
        };
    }

    public static function httpStatus(string $code): int
    {
        return match ($code) {
            self::AUTH_REQUIRED => 401,
            self::AUTHZ_FORBIDDEN, self::CSRF_TOKEN_REJECTED => 403,
            self::CONTENT_ENTRY_NOT_FOUND, self::CONTENT_TYPE_NOT_FOUND, self::MEDIA_NOT_FOUND,
            self::MENU_NOT_FOUND, self::PREVIEW_NOT_FOUND, self::PUBLIC_CONTENT_NOT_FOUND,
            self::ROUTE_NOT_FOUND, self::SITE_NOT_FOUND, self::TAXONOMY_NOT_FOUND, self::TAXONOMY_TERM_NOT_FOUND => 404,
            self::CONTRACT_VERSION_UNSUPPORTED, self::JSON_CONTENT_TYPE_REQUIRED => 415,
            self::RATE_LIMIT_EXCEEDED => 429,
            self::INVALID_JSON, self::VALIDATION_FAILED => 422,
            self::LANGUAGE_NOT_ENABLED, self::PUBLICATION_CONFLICT, self::RESOURCE_LOCKED, self::REVISION_CONFLICT, self::ROUTE_CONFLICT => 409,
            self::INTERNAL_SERVER_ERROR, self::JSON_ENCODING_FAILED => 500,
            default => 400,
        };
    }
}
