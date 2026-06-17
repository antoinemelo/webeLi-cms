<?php

declare(strict_types=1);

namespace App\Application\Iam;

use App\Core\Database;

/**
 * Validateur central des references IAM stockees dans core.sqlite.
 *
 * Les bases core.sqlite et iam.sqlite restent separees. SQLite ne peut donc pas
 * garantir nativement une foreign key de content_entries vers iam_users. Toute
 * ecriture editoriale doit passer par ce validateur avant de persister un
 * *_iam_user_id dans core.sqlite.
 */
final class UserReferenceValidator
{
    public function __construct(private readonly Database $iamDb) {}

    /** @return array<string,mixed> */
    public function requireExistingUser(int $userId, string $context = 'iam_user'): array
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException(sprintf('%s: identifiant utilisateur IAM invalide.', $context));
        }

        $user = $this->iamDb->one('SELECT id, email, is_active, disabled_at FROM iam_users WHERE id = :id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException(sprintf('%s: utilisateur IAM %d introuvable.', $context, $userId));
        }

        return $user;
    }

    /** @return array<string,mixed> */
    public function requireActiveUser(int $userId, string $context = 'iam_user'): array
    {
        $user = $this->requireExistingUser($userId, $context);
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException(sprintf('%s: utilisateur IAM %d desactive.', $context, $userId));
        }

        return $user;
    }

    public function assertCanCreateDraft(int $userId): void
    {
        $this->requireActiveUser($userId, 'content.draft.create');
    }

    public function assertCanUpdateDraft(int $userId): void
    {
        $this->requireActiveUser($userId, 'content.revisions.save');
    }

    public function assertCanPublish(int $userId): void
    {
        $this->requireActiveUser($userId, 'content.publish');
    }

    public function assertCanUnpublish(int $userId): void
    {
        $this->requireActiveUser($userId, 'content.unpublish');
    }
}
