<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;

final class ProfileApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly AuthRepository $auth,
    ) {}

    public function show(): Response
    {
        $this->auth->requireAuth();

        return Response::success([
            'profile' => $this->profileContract($this->auth->currentUserProfile()),
            'schema' => $this->schema(),
            'actions' => [
                'update' => ['method' => 'PATCH', 'path' => '/admin/api/profile'],
                'change_password' => ['method' => 'PATCH', 'path' => '/admin/api/profile/password'],
            ],
        ], 'admin.profile.v1', ['contract_version' => AdminApiContract::VERSION]);
    }

    public function update(): Response
    {
        $this->auth->requireAuth();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $fields = $this->validate($payload);
        if ($fields !== []) {
            return AdminApiContract::validationResponse($fields, 'Le profil contient des erreurs.');
        }

        try {
            $profile = $this->auth->updateCurrentUserProfile([
                'email' => (string) ($payload['email'] ?? ''),
                'first_name' => (string) ($payload['first_name'] ?? ''),
                'last_name' => (string) ($payload['last_name'] ?? ''),
                'locale' => (string) ($payload['locale'] ?? ''),
            ]);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'EMAIL_ALREADY_USED') {
                return AdminApiContract::validationResponse(['email' => ['Cette adresse email est déjà utilisée.']], 'Le profil contient des erreurs.');
            }
            throw $exception;
        }

        return Response::success([
            'profile' => $this->profileContract($profile),
            'message' => 'Profil mis à jour.',
        ], 'admin.profile.v1', ['contract_version' => AdminApiContract::VERSION]);
    }



    public function changePassword(): Response
    {
        $this->auth->requireAuth();
        $payload = AdminApiContract::dataPayload($this->request, false);
        $fields = $this->validatePassword($payload);
        if ($fields !== []) {
            return AdminApiContract::validationResponse($fields, 'Le mot de passe contient des erreurs.');
        }

        try {
            $this->auth->changeCurrentUserPassword(
                (string) ($payload['current_password'] ?? ''),
                (string) ($payload['new_password'] ?? '')
            );
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'CURRENT_PASSWORD_INVALID') {
                return AdminApiContract::validationResponse(['current_password' => ['Le mot de passe actuel est incorrect.']], 'Le mot de passe contient des erreurs.');
            }
            throw $exception;
        }

        return Response::success([
            'message' => 'Mot de passe modifié. Les autres sessions du compte ont été révoquées.',
        ], 'admin.profile.password.v1', ['contract_version' => AdminApiContract::VERSION]);
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function profileContract(array $profile): array
    {
        $firstName = (string) ($profile['first_name'] ?? '');
        $lastName = (string) ($profile['last_name'] ?? '');
        $name = trim($firstName . ' ' . $lastName);

        return [
            'id' => (int) ($profile['id'] ?? 0),
            'email' => (string) ($profile['email'] ?? ''),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name !== '' ? $name : (string) ($profile['email'] ?? ''),
            'locale' => (string) ($profile['locale'] ?? 'fr-CH'),
            'is_active' => (bool) ($profile['is_active'] ?? true),
            'last_login_at' => $profile['last_login_at'] ?? null,
            'created_at' => (string) ($profile['created_at'] ?? ''),
            'updated_at' => (string) ($profile['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,list<string>> */
    private function validate(array $payload): array
    {
        $errors = [];
        $email = trim((string) ($payload['email'] ?? ''));
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $locale = trim((string) ($payload['locale'] ?? 'fr-CH'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Adresse email invalide.';
        }
        if (self::length($email) > 254) {
            $errors['email'][] = 'Adresse email trop longue.';
        }
        if ($firstName === '') {
            $errors['first_name'][] = 'Le prénom est obligatoire.';
        }
        if (self::length($firstName) > 120) {
            $errors['first_name'][] = 'Le prénom est trop long.';
        }
        if (self::length($lastName) > 120) {
            $errors['last_name'][] = 'Le nom est trop long.';
        }
        if ($locale === '' || !preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
            $errors['locale'][] = 'Locale invalide. Exemple attendu : fr-CH.';
        }

        return $errors;
    }



    /** @param array<string,mixed> $payload @return array<string,list<string>> */
    private function validatePassword(array $payload): array
    {
        $errors = [];
        $currentPassword = (string) ($payload['current_password'] ?? '');
        $newPassword = (string) ($payload['new_password'] ?? '');
        $confirmPassword = (string) ($payload['confirm_password'] ?? '');

        if ($currentPassword === '') {
            $errors['current_password'][] = 'Le mot de passe actuel est obligatoire.';
        }
        if (self::length($newPassword) < 12) {
            $errors['new_password'][] = 'Le nouveau mot de passe doit contenir au moins 12 caractères.';
        }
        if (self::length($newPassword) > 255) {
            $errors['new_password'][] = 'Le nouveau mot de passe est trop long.';
        }
        if ($newPassword !== '' && !preg_match('/[a-z]/', $newPassword)) {
            $errors['new_password'][] = 'Ajoutez au moins une lettre minuscule.';
        }
        if ($newPassword !== '' && !preg_match('/[A-Z]/', $newPassword)) {
            $errors['new_password'][] = 'Ajoutez au moins une lettre majuscule.';
        }
        if ($newPassword !== '' && !preg_match('/[0-9]/', $newPassword)) {
            $errors['new_password'][] = 'Ajoutez au moins un chiffre.';
        }
        if ($newPassword !== '' && $newPassword === $currentPassword) {
            $errors['new_password'][] = 'Le nouveau mot de passe doit être différent du mot de passe actuel.';
        }
        if ($confirmPassword === '' || $confirmPassword !== $newPassword) {
            $errors['confirm_password'][] = 'La confirmation ne correspond pas au nouveau mot de passe.';
        }

        return $errors;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        return [
            'version' => 'profile.schema.v1',
            'fields' => [
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 254],
                ['key' => 'first_name', 'label' => 'Prénom', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'last_name', 'label' => 'Nom', 'type' => 'text', 'required' => false, 'max' => 120],
                ['key' => 'locale', 'label' => 'Locale', 'type' => 'text', 'required' => true, 'pattern' => '^[a-z]{2}(?:-[A-Z]{2})?$'],
            ],
        ];
    }
}
