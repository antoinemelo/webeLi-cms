<?php

declare(strict_types=1);

namespace App\Application\Seo;

final class RunSeoAudit
{
    public function __construct(private readonly SeoAuditIssueRepository $issues, private readonly array $config) {}

    public function score(string $title, string $description, string $slug, string $body = '', ?string $canonicalUrl = null, ?string $jsonLd = null): int
    {
        return (int) $this->explainScore($title, $description, $slug, $body, $canonicalUrl, $jsonLd)['score'];
    }

    /** @return array{score:int,checks:list<array<string,mixed>>} */
    public function explainScore(string $title, string $description, string $slug, string $body = '', ?string $canonicalUrl = null, ?string $jsonLd = null): array
    {
        $score = 100;
        $checks = [];
        $titleLength = mb_strlen(trim($title));
        $descriptionLength = mb_strlen(trim($description));
        $wordCount = str_word_count(strip_tags($body));

        $apply = static function (string $code, bool $passed, int $penalty, string $message, array $details = []) use (&$score, &$checks): void {
            if (!$passed) { $score -= $penalty; }
            $checks[] = ['code' => $code, 'passed' => $passed, 'penalty' => $passed ? 0 : $penalty, 'message' => $message, 'details' => $details];
        };

        $apply('meta.title.present', $titleLength > 0, 25, 'Titre SEO présent.', ['length' => $titleLength]);
        $apply('meta.title.length', $titleLength === 0 || ($titleLength >= (int) ($this->config['seo']['audit_rules']['warn_if_title_under'] ?? 25) && $titleLength <= 60), $titleLength > 60 ? 8 : 12, 'Longueur du titre SEO recommandée.', ['length' => $titleLength, 'target' => '25-60']);
        $apply('meta.description.present', $descriptionLength > 0, 25, 'Meta description présente.', ['length' => $descriptionLength]);
        $apply('meta.description.length', $descriptionLength === 0 || ($descriptionLength >= (int) ($this->config['seo']['audit_rules']['warn_if_meta_description_under'] ?? 80) && $descriptionLength <= 170), $descriptionLength > 170 ? 8 : 15, 'Longueur de meta description recommandée.', ['length' => $descriptionLength, 'target' => '80-170']);
        $apply('route.slug.descriptive', mb_strlen($slug) >= (int) ($this->config['seo']['audit_rules']['warn_if_slug_shorter_than'] ?? 3), 5, 'Slug descriptif.', ['slug' => $slug]);
        if ($wordCount > 0) { $apply('content.word_count', $wordCount >= 250, 10, 'Volume de contenu lisible.', ['words' => $wordCount]); }
        if ($canonicalUrl !== null) { $apply('canonical.present', trim($canonicalUrl) !== '', 5, 'Canonical présent.', ['canonical_url' => $canonicalUrl]); }
        if ($jsonLd !== null && trim($jsonLd) !== '') {
            json_decode($jsonLd, true);
            $apply('structured_data.json_ld_valid', json_last_error() === JSON_ERROR_NONE, 15, 'JSON-LD valide.', ['error' => json_last_error_msg()]);
        }

        return ['score' => max(0, min(100, $score)), 'checks' => $checks];
    }

    public function recordContentIssues(int $siteId, int $entryId, string $languageCode, array $loc, string $title, string $description, string $slug): void
    {
        foreach ($this->detectContentIssues($loc, $title, $description, $slug) as [$code, $severity, $message]) {
            $this->issues->addIssue($siteId, 'content_entry', $entryId, $languageCode, $code, $severity, $message);
        }
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public function detectContentIssues(array $loc, string $title, string $description, string $slug): array
    {
        $issues = [];
        $title = trim($title);
        $description = trim($description);
        if ($title === '') { $issues[] = ['require_title', 'high', 'Meta title manquant: ajoutez un titre SEO unique, précis et lisible.']; }
        if ($description === '') { $issues[] = ['require_meta_description', 'high', 'Meta description manquante: rédigez une synthèse claire de la page.']; }
        if (mb_strlen($title) > 0 && mb_strlen($title) < 30) { $issues[] = ['short_title', 'medium', 'Meta title trop court: visez environ 30 à 60 caractères.']; }
        if (mb_strlen($title) > 60) { $issues[] = ['long_title', 'low', 'Meta title potentiellement tronqué: raccourcissez-le sous 60 caractères.']; }
        if (mb_strlen($description) > 0 && mb_strlen($description) < 110) { $issues[] = ['short_meta_description', 'medium', 'Meta description trop courte: visez 110 à 160 caractères.']; }
        if (mb_strlen($description) > 160) { $issues[] = ['long_meta_description', 'low', 'Meta description potentiellement tronquée: raccourcissez-la sous 160 caractères.']; }
        if (mb_strlen($slug) < 3) { $issues[] = ['short_slug', 'low', 'Slug trop court: utilisez un chemin descriptif.']; }

        return $issues;
    }

    public function listIssues(?int $siteId = null, ?string $languageCode = null, bool $unresolvedOnly = false): array
    {
        return $this->issues->listIssues($siteId, $languageCode, $unresolvedOnly);
    }
}
