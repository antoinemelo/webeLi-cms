<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/src/Application/Blueprint/BlueprintSchemaCanonicalizer.php';
use App\Application\Blueprint\BlueprintSchemaCanonicalizer;

$c = new BlueprintSchemaCanonicalizer();
$base = [
    'schema_json' => ['fields' => [['field_key' => 'title', 'field_type' => 'text', 'required' => true]]],
    'ui_schema_json' => ['editor_tabs' => []],
    'validation_json' => [], 'seo_policy_json' => [], 'routing_policy_json' => [],
    'workflow_policy_json' => [], 'translation_policy_json' => [], 'permissions_policy_json' => [],
];
assert(strlen($c->checksum($base)) === 64);
$reordered = array_reverse($base, true);
assert($c->checksum($base) === $c->checksum($reordered));
try {
    $bad = $base;
    $bad['schema_json']['fields'][] = ['field_key' => 'title', 'field_type' => 'text'];
    $c->checksum($bad);
    throw new RuntimeException('duplicate key accepted');
} catch (InvalidArgumentException) {}
try {
    $bad = $base;
    $bad['schema_json']['fields'][0]['field_type'] = '';
    $c->checksum($bad);
    throw new RuntimeException('empty type accepted');
} catch (InvalidArgumentException) {}
echo "OK blueprint canonicalization\n";
