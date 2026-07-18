<?php

declare(strict_types=1);

namespace App\Application\Api\Admin;

use App\Application\Api\Admin\Contract\AdminApiContract;
use App\Application\Blueprint\BlueprintRepository;
use App\Application\Schema\NativeFieldBlueprintRegistry;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

final class BlockBlueprintApiController
{
    public function __construct(
        private readonly Request $request,
        private readonly SiteRepository $sites,
        private readonly AuthRepository $auth,
        private readonly Authorization $authorization,
        private readonly ?BlueprintRepository $blueprints = null,
    ) {}

    public function index(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);

        return Response::success([
            'schema_version' => 1,
            'source_of_truth' => $this->hasVersionedBlockBlueprints((int) $site['id']) ? 'blueprint_versions' : 'native_registry_fallback',
            'blueprints' => $this->listBlockBlueprints((int) $site['id']),
            'fieldsets' => NativeFieldBlueprintRegistry::fieldsets(),
            'field_types' => NativeFieldBlueprintRegistry::fieldTypes(),
        ], 'admin.block_blueprints.index.v1', AdminApiContract::meta($site, $lang));
    }

    public function show(string $type): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        $type = strtolower(trim($type));
        $blueprint = $this->blockBlueprint($type, (int) $site['id']);
        if ($blueprint === null) {
            return Response::error(ErrorCode::VALIDATION_FAILED, 'Blueprint de bloc introuvable.', 404, ['type' => $type]);
        }
        return Response::success([
            'schema_version' => 1,
            'source_of_truth' => $this->blockBlueprintFromVersions($type, (int) $site['id']) !== null ? 'blueprint_versions' : 'native_registry_fallback',
            'blueprint' => $blueprint,
            'fieldsets' => NativeFieldBlueprintRegistry::fieldsets(),
            'field_types' => NativeFieldBlueprintRegistry::fieldTypes(),
        ], 'admin.block_blueprints.show.v1', AdminApiContract::meta($site, $lang));
    }

    public function fieldTypes(): Response
    {
        $this->auth->requireAuth();
        $site = AdminApiContract::siteContext($this->request, $this->sites, isset($this->request->query['site_id']) ? (int) $this->request->query['site_id'] : null, $this->auth);
        $this->authorization->require('content.read', (int) $site['id']);
        $lang = AdminApiContract::language($this->request, $this->sites, $site);
        return Response::success([
            'schema_version' => 1,
            'field_types' => NativeFieldBlueprintRegistry::fieldTypes(),
        ], 'admin.field_types.index.v1', AdminApiContract::meta($site, $lang));
    }

    /** @return list<array<string,mixed>> */
    private function listBlockBlueprints(int $siteId): array
    {
        $blueprints = NativeFieldBlueprintRegistry::blockBlueprints();
        if ($this->blueprints !== null) {
            $rows = $this->blueprints->list('block', $siteId);
            foreach ($rows as $row) {
                $blueprint = $this->blockBlueprintFromVersions((string) ($row['blueprint_key'] ?? ''), $siteId);
                if ($blueprint !== null) {
                    $blueprints[(string) ($blueprint['key'] ?? $row['blueprint_key'])] = $blueprint;
                }
            }
        }
        ksort($blueprints);
        return array_values($blueprints);
    }

    /** @return array<string,mixed>|null */
    private function blockBlueprint(string $type, int $siteId): ?array
    {
        return $this->blockBlueprintFromVersions($type, $siteId) ?? NativeFieldBlueprintRegistry::blockBlueprint($type);
    }

    /** @return array<string,mixed>|null */
    private function blockBlueprintFromVersions(string $type, int $siteId): ?array
    {
        if ($this->blueprints === null || $type === '') {
            return null;
        }
        $version = $this->blueprints->activeVersion($type, 'block', $siteId);
        if (!$version) {
            return null;
        }
        $schema = is_array($version['schema'] ?? null) ? $version['schema'] : [];
        $block = is_array($schema['block'] ?? null) ? $schema['block'] : [];
        if ($block === []) {
            return null;
        }
        $block['schema_version'] = (int) ($schema['schema_version'] ?? $block['schema_version'] ?? 1);
        $block = $this->withNativeBlockFieldFallbacks($block, $type);
        $block['source'] = 'blueprint_versions';
        $block['version'] = (int) ($version['version'] ?? 1);
        return $block;
    }

    /**
     * Les blueprints versionnés restent la source de vérité éditoriale, mais les
     * versions historiques peuvent avoir été seedées avant l'ajout de certaines
     * configurations UI natives. On complète donc uniquement les champs natifs
     * manquants, sans écraser les personnalisations déjà présentes.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function withNativeBlockFieldFallbacks(array $block, string $type): array
    {
        $native = NativeFieldBlueprintRegistry::blockBlueprint($type);
        if ($native === null) {
            return $block;
        }

        $nativeSections = is_array($native['sections'] ?? null) ? $native['sections'] : [];
        $nativeFields = [];
        foreach ($nativeSections as $nativeSection) {
            foreach ((array) ($nativeSection['fields'] ?? []) as $nativeField) {
                if (!is_array($nativeField)) {
                    continue;
                }
                $handle = (string) ($nativeField['handle'] ?? '');
                if ($handle !== '') {
                    $nativeFields[$handle] = $nativeField;
                }
            }
        }
        if ($nativeFields === []) {
            return $block;
        }

        $sections = is_array($block['sections'] ?? null) ? $block['sections'] : [];
        $seen = [];
        foreach ($sections as $sectionIndex => $section) {
            if (!is_array($section)) {
                continue;
            }
            $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
            foreach ($fields as $fieldIndex => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $handle = (string) ($field['handle'] ?? '');
                if ($handle === '' || !isset($nativeFields[$handle])) {
                    continue;
                }
                $seen[$handle] = true;
                $fields[$fieldIndex] = $this->mergeNativeFieldFallback($field, $nativeFields[$handle]);
            }
            $sections[$sectionIndex]['fields'] = $fields;
        }

        $missingFields = [];
        foreach ($nativeFields as $handle => $nativeField) {
            if (!isset($seen[$handle])) {
                $missingFields[] = $nativeField;
            }
        }
        if ($missingFields !== []) {
            if ($sections === []) {
                $sections[] = ['key' => 'content', 'display' => (string) ($native['title'] ?? $type), 'fields' => []];
            }
            $firstFields = is_array($sections[0]['fields'] ?? null) ? $sections[0]['fields'] : [];
            $sections[0]['fields'] = array_values(array_merge($firstFields, $missingFields));
        }

        $block['sections'] = $sections;
        if (is_array($native['defaults'] ?? null)) {
            $block['defaults'] = array_replace_recursive($native['defaults'], is_array($block['defaults'] ?? null) ? $block['defaults'] : []);
        }
        return $block;
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $nativeField
     * @return array<string,mixed>
     */
    private function mergeNativeFieldFallback(array $field, array $nativeField): array
    {
        $merged = $field;
        foreach (['type', 'display', 'instructions', 'default', 'width', 'validate', 'config', 'interface_key'] as $key) {
            if (!array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === '' || $merged[$key] === []) {
                if (array_key_exists($key, $nativeField)) {
                    $merged[$key] = $nativeField[$key];
                }
            }
        }
        if (is_array($nativeField['config'] ?? null)) {
            $merged['config'] = array_replace_recursive($nativeField['config'], is_array($merged['config'] ?? null) ? $merged['config'] : []);
        }
        if (is_array($nativeField['validate'] ?? null)) {
            $merged['validate'] = array_replace_recursive($nativeField['validate'], is_array($merged['validate'] ?? null) ? $merged['validate'] : []);
        }
        return $merged;
    }

    private function hasVersionedBlockBlueprints(int $siteId): bool
    {
        return $this->blueprints !== null && $this->blueprints->list('block', $siteId) !== [];
    }
}
