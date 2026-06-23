<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Blueprint\GetBlueprintEditorSchema;
use App\Core\Database;
use App\Infrastructure\Persistence\Sql\SqlBlueprintRepository;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}

/** @return array<string,mixed> */
function characterization_design(
    string $key,
    string $label,
    array $fields = [],
    array $fieldsets = [],
    ?int $legacyContentTypeId = null,
): array {
    $nativeFields = [
        [
            'field_handle' => 'title',
            'field_type' => 'text',
            'label' => 'Titre',
            'field_purpose' => 'system',
            'width' => 100,
            'is_required' => true,
            'is_localized' => true,
            'is_system' => true,
            'is_deletable' => false,
            'config' => ['field_scope' => 'system_editable'],
        ],
        [
            'field_handle' => 'slug',
            'field_type' => 'slug',
            'label' => 'Slug',
            'field_purpose' => 'system',
            'width' => 100,
            'is_required' => true,
            'is_localized' => true,
            'is_system' => true,
            'is_deletable' => false,
            'config' => ['field_scope' => 'system_editable'],
        ],
    ];

    return [
        'blueprint_key' => $key,
        'resource_type' => 'content_type',
        'label' => $label,
        'description' => 'Fixture de caractérisation isolée',
        'legacy_content_type_id' => $legacyContentTypeId,
        'sections' => [[
            'section_key' => 'content',
            'label' => 'Contenu',
            'layout' => 'tab',
            'sort_order' => 10,
            'fields' => array_merge($nativeFields, $fields),
            'fieldsets' => $fieldsets,
        ]],
    ];
}

/** @param list<array<string,mixed>> $fields */
function characterization_has_field(array $fields, string $handle): bool
{
    foreach ($fields as $field) {
        $candidate = (string) ($field['field_key'] ?? $field['field_handle'] ?? $field['key'] ?? '');
        if ($candidate === $handle) {
            return true;
        }
    }
    return false;
}

$h = new TestHarness();
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/blueprint_designer.sql');
$db = null;
try {
    $db = new Database($path, 1000);
    $pdo = $db->pdo();
    // Every row created below belongs to this disposable database. Disabling
    // foreign keys keeps the fixture focused on blueprint behavior instead of
    // reproducing the complete editorial publication graph.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $repository = new SqlBlueprintRepository($db);

    $body = [
        'field_handle' => 'body',
        'field_type' => 'markdown',
        'label' => 'Corps',
        'field_purpose' => 'content',
        'width' => 100,
        'is_required' => false,
        'is_localized' => true,
        'is_system' => false,
        'is_deletable' => true,
        'config' => [],
    ];

    // Saving a design creates or replaces the current draft without changing
    // the active contract consumed by content editors.
    $repository->saveDesign('article_model', characterization_design('article_model', 'Article', [$body]));
    $versions = $repository->versions('article_model', 'content_type');
    $h->assertSame(1, count($versions), 'first design save creates one version');
    $h->assertSame('draft', $versions[0]['status'], 'first design save creates a draft');
    $h->assertTrue(!$versions[0]['is_active'], 'first design draft is not active');
    $h->assertSame(null, $repository->activeVersion('article_model', 'content_type'), 'draft save does not create an active editor schema');

    // A second draft save replaces the current draft instead of creating an
    // uncontrolled version stream.
    $updatedBody = $body;
    $updatedBody['help_text'] = 'Texte principal';
    $repository->saveDesign('article_model', characterization_design('article_model', 'Article modifié', [$updatedBody]));
    $versions = $repository->versions('article_model', 'content_type');
    $h->assertSame(1, count($versions), 'second design save replaces the current draft');
    $h->assertSame(1, $versions[0]['version'], 'draft keeps its version number while it is replaced');
    $h->assertSame('draft', $versions[0]['status'], 'replaced design remains draft');
    $h->assertTrue(!$versions[0]['is_active'], 'replaced draft is still inactive');

    // Explicit activation validates and atomically updates the canonical active pointer.
    $repository->activate('article_model', 1, null, 'content_type');
    $active = $repository->activeVersion('article_model', 'content_type');
    $h->assertSame(1, $active['version'], 'activation updates canonical active_version_id');
    $h->assertSame('active', $active['status'], 'activated draft becomes active');
    $h->assertSame(64, strlen((string) $active['checksum_sha256']), 'design version stores its SHA-256 checksum');

    // Saving after activation creates one new draft and leaves the active
    // version available to the content editor until the draft is activated.
    $nextBody = $body;
    $nextBody['label'] = 'Corps révisé';
    $repository->saveDesign('article_model', characterization_design('article_model', 'Article brouillon suivant', [$nextBody]));
    $versions = $repository->versions('article_model', 'content_type');
    $h->assertSame(2, count($versions), 'draft after activation creates one pending version');
    $h->assertSame(2, $versions[0]['version'], 'pending draft uses the next version');
    $h->assertSame('draft', $versions[0]['status'], 'pending version is a draft');
    $h->assertSame(1, $repository->activeVersion('article_model', 'content_type')['version'], 'content editor keeps using the previous active version');
    $repository->activate('article_model', 2, null, 'content_type');
    $versions = $repository->versions('article_model', 'content_type');
    $h->assertSame(2, $repository->activeVersion('article_model', 'content_type')['version'], 'explicit activation promotes the draft');
    $h->assertSame('archived', $versions[1]['status'], 'previous active design is archived and available for rollback');
    $repository->activate('article_model', 1, null, 'content_type');
    $h->assertSame(1, $repository->activeVersion('article_model', 'content_type')['version'], 'older archived version can be reactivated as rollback');
    $repository->activate('article_model', 2, null, 'content_type');

    // The lower-level version contract supports a draft and explicit activation.
    $sourceVersion = $repository->activeVersion('article_model', 'content_type');
    $draft = $repository->createVersion('article_model', [
        'resource_type' => 'content_type',
        'status' => 'draft',
        'schema_json' => $sourceVersion['schema'],
        'ui_schema_json' => $sourceVersion['ui_schema'],
        'validation_json' => $sourceVersion['validation'],
        'seo_policy_json' => $sourceVersion['seo_policy'],
        'routing_policy_json' => $sourceVersion['routing_policy'],
        'workflow_policy_json' => $sourceVersion['workflow_policy'],
        'translation_policy_json' => $sourceVersion['translation_policy'],
        'permissions_policy_json' => $sourceVersion['permissions_policy'],
    ]);
    $h->assertSame('draft', $draft['status'], 'repository can create an inactive draft');
    $h->assertTrue(!$draft['is_active'], 'draft does not replace the active version');
    $repository->activate('article_model', (int) $draft['version'], null, 'content_type');
    $h->assertSame((int) $draft['version'], $repository->activeVersion('article_model', 'content_type')['version'], 'draft can be activated explicitly');

    // Protected system fields cannot be removed from an existing design.
    $withoutTitle = characterization_design('article_model', 'Article sans titre', [$body]);
    $withoutTitle['sections'][0]['fields'] = array_values(array_filter(
        $withoutTitle['sections'][0]['fields'],
        static fn(array $field): bool => $field['field_handle'] !== 'title',
    ));
    $h->expectException(
        fn() => $repository->saveDesign('article_model', $withoutTitle),
        InvalidArgumentException::class,
        'protected title cannot be removed',
    );

    // CURRENT BEHAVIOR: changing the payload key creates a second blueprint;
    // it is not an atomic rename of the resource addressed by the URL key.
    $repository->saveDesign('rename_source', characterization_design('rename_source', 'Source', [$body]));
    $repository->saveDesign('rename_source', characterization_design('rename_target', 'Cible', [$body]));
    $h->assertTrue($repository->findByKey('rename_source', 'content_type') !== null, 'source remains after payload key change');
    $h->assertTrue($repository->findByKey('rename_target', 'content_type') !== null, 'payload key change creates a second blueprint');

    // Global and site-specific blueprints with the same key can coexist. A
    // site-scoped lookup currently resolves the local override first.
    $repository->createBlueprint(['blueprint_key' => 'shared_key', 'resource_type' => 'content_type', 'label' => 'Global']);
    $repository->createBlueprint(['blueprint_key' => 'shared_key', 'resource_type' => 'content_type', 'site_id' => 42, 'label' => 'Local']);
    $sameKey = array_values(array_filter(
        $repository->list('content_type', 42),
        static fn(array $row): bool => $row['blueprint_key'] === 'shared_key',
    ));
    $h->assertSame(2, count($sameKey), 'global and local rows coexist in the site list');
    $h->assertSame(42, $repository->findByKey('shared_key', 'content_type', 42)['site_id'], 'site lookup resolves local override first');

    // The admin editor can address both rows explicitly without changing its
    // route or persisted identity.
    $repository->saveDesign('shared_key', characterization_design('shared_key', 'Global explicite', [$body]), 42, 'global');
    $repository->saveDesign('shared_key', characterization_design('shared_key', 'Locale explicite', [$body]), 42, 'site');
    $h->assertSame('Global explicite', $repository->design('shared_key', 'content_type', 42, 'global')['blueprint']['label'], 'global scope selects the global design');
    $h->assertSame('Locale explicite', $repository->design('shared_key', 'content_type', 42, 'site')['blueprint']['label'], 'site scope selects the local override');
    $repository->saveDesign('shared_key', characterization_design('shared_key', 'Global modifié seul', [$body]), 42, 'global');
    $h->assertSame('Locale explicite', $repository->design('shared_key', 'content_type', 42, 'site')['blueprint']['label'], 'global write never mutates local override');
    $repository->saveDesign('shared_key', characterization_design('shared_key', 'Locale modifiée seule', [$body]), 42, 'site');
    $h->assertSame('Global modifié seul', $repository->design('shared_key', 'content_type', 42, 'global')['blueprint']['label'], 'local write never mutates global design');

    // A mounted fieldset exposes exact used_by information.
    $repository->saveFieldset(null, [
        'fieldset_key' => 'shared_meta',
        'label' => 'Métadonnées partagées',
        'fieldset_purpose' => 'metadata',
        'fields' => [[
            'field_handle' => 'teaser',
            'field_type' => 'textarea',
            'label' => 'Accroche',
            'field_purpose' => 'metadata',
            'width' => 100,
            'config' => [],
        ]],
    ]);
    $mount = [[
        'fieldset_key' => 'shared_meta',
        'mount_handle' => 'shared_meta',
        'label' => 'Métadonnées partagées',
        'sort_order' => 10,
        'config' => [],
        'conditions' => [],
    ]];
    $repository->saveDesign('fieldset_consumer', characterization_design('fieldset_consumer', 'Consommateur', [$body], $mount));
    $fieldset = $repository->fieldset('shared_meta');
    $h->assertSame(1, $fieldset['usage_count'], 'fieldset detail reports one usage');
    $h->assertSame('fieldset_consumer', $fieldset['used_by'][0]['blueprint_key'], 'fieldset detail exposes exact used_by blueprint');
    $h->assertSame('content_type', $fieldset['used_by'][0]['resource_type'], 'fieldset used_by exposes consumer resource type');
    $h->assertSame('global', $fieldset['used_by'][0]['scope'], 'fieldset used_by exposes consumer scope');
    $h->expectException(
        fn() => $repository->deleteFieldset('shared_meta'),
        InvalidArgumentException::class,
        'used fieldset cannot be deleted',
    );

    $repository->saveFieldset(null, [
        'fieldset_key' => 'unused_group',
        'label' => 'Groupe inutilisé',
        'fieldset_purpose' => 'content',
        'fields' => [[
            'field_handle' => 'unused_note',
            'field_type' => 'text',
            'label' => 'Note inutilisée',
            'field_purpose' => 'content',
            'width' => 100,
            'config' => [],
        ]],
    ]);

    // Fieldset summary and detail expose coherent usage counters.
    $overviewFieldset = array_values(array_filter(
        $repository->modelOverview()['fieldsets'],
        static fn(array $row): bool => $row['fieldset_key'] === 'shared_meta',
    ))[0];
    $h->assertSame(1, $overviewFieldset['usage_count'], 'fieldset summary reports the same usage_count as detail');
    $h->assertSame('fieldset_consumer', $overviewFieldset['used_by'][0]['blueprint_key'], 'fieldset summary exposes exact used_by information');
    $unusedOverview = array_values(array_filter(
        $repository->modelOverview()['fieldsets'],
        static fn(array $row): bool => $row['fieldset_key'] === 'unused_group',
    ))[0];
    $h->assertSame(0, $unusedOverview['usage_count'], 'unused fieldset reports zero usage');
    $h->assertSame([], $unusedOverview['used_by'], 'unused fieldset exposes an empty used_by list');

    // Mounted fieldsets are projected into the active schema and editor schema
    // without storing duplicate direct blueprint fields.
    $consumerVersion = $repository->versions('fieldset_consumer', 'content_type')[0]['version'];
    $repository->activate('fieldset_consumer', (int) $consumerVersion);
    $activeVersion = $repository->activeVersion('fieldset_consumer', 'content_type');
    $h->assertTrue(characterization_has_field($activeVersion['schema']['fields'], 'teaser'), 'active schema projects mounted fieldset fields');
    $editorSchema = (new GetBlueprintEditorSchema($repository, $db))->execute('fieldset_consumer');
    $h->assertTrue(characterization_has_field($editorSchema['fields'], 'teaser'), 'mounted fieldset field joins editor schema fields');
    $tabFields = $editorSchema['editor_tabs'][0]['fields'] ?? [];
    $h->assertTrue(in_array('teaser', $tabFields, true), 'editor tabs expose mounted fieldset field handles');
    $h->assertTrue(!in_array('@shared_meta', $tabFields, true), 'unresolved fieldset mount marker is not exposed to the editor');
    $directRows = $pdo->query("SELECT COUNT(*) AS n FROM blueprint_fields WHERE field_handle='teaser'")->fetch(PDO::FETCH_ASSOC);
    $h->assertSame(0, (int) ($directRows['n'] ?? 0), 'mounted fieldset field is not duplicated as a direct blueprint field');

    // Editing a shared fieldset does not change the active editor contract
    // until the consuming blueprint saves and activates a new draft projection.
    $repository->saveFieldset('shared_meta', [
        'fieldset_key' => 'shared_meta',
        'label' => 'Métadonnées partagées',
        'fieldset_purpose' => 'metadata',
        'fields' => [[
            'field_handle' => 'summary',
            'field_type' => 'textarea',
            'label' => 'Résumé',
            'field_purpose' => 'metadata',
            'width' => 100,
            'config' => [],
        ]],
    ]);
    $editorSchema = (new GetBlueprintEditorSchema($repository, $db))->execute('fieldset_consumer');
    $h->assertTrue(characterization_has_field($editorSchema['fields'], 'teaser'), 'active editor schema keeps the previous fieldset snapshot before activation');
    $h->assertTrue(!characterization_has_field($editorSchema['fields'], 'summary'), 'new shared fieldset field is not visible before consuming blueprint activation');
    $repository->saveDesign('fieldset_consumer', characterization_design('fieldset_consumer', 'Consommateur', [$body], $mount));
    $editorSchema = (new GetBlueprintEditorSchema($repository, $db))->execute('fieldset_consumer');
    $h->assertTrue(characterization_has_field($editorSchema['fields'], 'teaser'), 'saving a draft still does not modify the active editor schema');
    $repository->activate('fieldset_consumer', 2, null, 'content_type');
    $editorSchema = (new GetBlueprintEditorSchema($repository, $db))->execute('fieldset_consumer');
    $h->assertTrue(!characterization_has_field($editorSchema['fields'], 'teaser'), 'removed shared fieldset field leaves editor schema after activation');
    $h->assertTrue(characterization_has_field($editorSchema['fields'], 'summary'), 'new shared fieldset field joins editor schema after activation');

    // Creating a variant is a separate fieldset and does not change existing consumers.
    $variant = $repository->saveFieldset(null, [
        'fieldset_key' => 'shared_meta_variant',
        'label' => 'Métadonnées partagées variante',
        'fieldset_purpose' => 'metadata',
        'fields' => [[
            'field_handle' => 'variant_note',
            'field_type' => 'text',
            'label' => 'Note variante',
            'field_purpose' => 'metadata',
            'width' => 100,
            'config' => [],
        ]],
    ]);
    $h->assertSame(0, $variant['usage_count'], 'new fieldset variant starts unused');

    // Published data protects both field deletion and blueprint deletion.
    $pdo->exec("INSERT INTO content_types(type_key,name,singular_label,plural_label) VALUES('published_type','Published','Published','Published')");
    $contentTypeId = (int) $pdo->lastInsertId();
    $repository->saveDesign('published_model', characterization_design('published_model', 'Publié', [$body], [], $contentTypeId));
    $pdo->exec("INSERT INTO content_entries(site_id,content_type_id,entry_key,status,workflow_state,published_at) VALUES(1,{$contentTypeId},'published-entry','published','published',CURRENT_TIMESTAMP)");
    $entryId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO content_entry_field_values(entry_id,content_type_id,field_id,field_key,language_code,source_revision_id,value_text) VALUES({$entryId},{$contentTypeId},999,'body','fr',999,'Contenu publié')");
    $h->expectException(
        fn() => $repository->saveDesign('published_model', characterization_design('published_model', 'Publié', [], [], $contentTypeId)),
        InvalidArgumentException::class,
        'published field value prevents destructive field removal',
    );
    $h->expectException(
        fn() => $repository->deleteBlueprint('published_model', 'content_type'),
        InvalidArgumentException::class,
        'published entries prevent blueprint deletion',
    );

    // System fieldsets are protected independently of the frontend.
    $pdo->exec("INSERT INTO fieldsets(fieldset_key,label,fieldset_purpose,is_system,is_deletable) VALUES('system_group','Système','system',1,0)");
    $h->expectException(
        fn() => $repository->saveFieldset('system_group', ['fieldset_key' => 'system_group', 'label' => 'Altéré', 'fields' => []]),
        InvalidArgumentException::class,
        'system fieldset cannot be edited',
    );
} finally {
    unset($pdo, $repository, $editorSchema, $active, $draft);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('INTEGRATION blueprint designer characterization'));
