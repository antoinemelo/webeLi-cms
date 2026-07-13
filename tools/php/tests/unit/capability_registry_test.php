<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Capability\ActionRunRepository;
use App\Application\Capability\BlueprintActionContextService;
use App\Application\Capability\CapabilityDefinition;
use App\Application\Capability\CapabilityExecutor;
use App\Application\Capability\CapabilityRegistry;
use App\Core\Database;
use App\Module\ModuleRegistry;
use App\Modules\Sale\SaleModuleProvider;
use App\Repository\AuthRepository;

$h = new TestHarness();
$dir = sys_get_temp_dir() . '/amcms-capability-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0775, true);
$db = new Database($dir . '/core.sqlite');

try {
    $db->run('CREATE TABLE iam_roles(id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT NOT NULL UNIQUE, name TEXT NOT NULL)');
    $db->run('CREATE TABLE iam_user_roles(user_id INTEGER NOT NULL, role_id INTEGER NOT NULL)');
    $db->run("INSERT INTO iam_roles(role_key, name) VALUES('super_admin', 'Super admin')");
    $db->run('INSERT INTO iam_user_roles(user_id, role_id) VALUES(1, 1)');
    $_SESSION['admin_user'] = ['id' => 1, 'email' => 'capability@example.test', 'session_secret' => 'test'];

    $modules = new ModuleRegistry($db, [
        'modules' => [
            'system_manifest_paths' => [$dir . '/none.json'],
            'local_modules_config' => $dir . '/none-local.json',
        ],
    ]);
    $registry = new CapabilityRegistry($modules, new BlueprintActionContextService($db));

    $known = $registry->knownCapabilities();
    foreach (['cart.validate', 'checkout.validate', 'payment.provider', 'order.after_place'] as $key) {
        $h->assertTrue(isset($known[$key]), 'capability key is known: ' . $key);
    }

    $h->expectException(
        fn() => $registry->register(new CapabilityDefinition(
            key: 'unknown.demo',
            label: 'Unknown',
            module: 'test',
            permission: 'sale.read',
            version: '1.0',
            type: 'workflow',
            contract: 'unknown.demo.v1',
        )),
        InvalidArgumentException::class,
        'unknown capability key is rejected'
    );

    $cart = new CapabilityDefinition(
        key: 'cart.validate',
        label: 'Validate cart',
        module: 'test',
        permission: 'sale.orders.manage',
        version: '1.0',
        type: 'validator',
        contract: 'sale.cart_validator.v1',
        active: false,
        priority: 20,
        inputSchema: [
            'type' => 'object',
            'properties' => ['cart_id' => ['type' => 'integer']],
            'required' => ['cart_id'],
            'additionalProperties' => false,
        ],
    );
    $registry->register($cart);
    $h->expectException(
        fn() => $registry->register($cart),
        LogicException::class,
        'duplicate capability key is rejected'
    );

    $registry->register(new CapabilityDefinition(
        key: 'checkout.validate',
        label: 'Validate checkout',
        module: 'test',
        permission: 'sale.orders.manage',
        version: '1.0',
        type: 'validator',
        contract: 'sale.checkout_validator.v1',
        priority: 5,
        inputSchema: [
            'type' => 'object',
            'properties' => ['cart_id' => ['type' => 'integer']],
            'required' => ['cart_id'],
            'additionalProperties' => false,
        ],
    ));
    $registry->register(new CapabilityDefinition(
        key: 'payment.provider',
        label: 'Payment provider',
        module: 'test',
        permission: 'sale.payments.manage',
        version: '1.0',
        type: 'provider',
        contract: 'sale.payment_provider.v1',
        priority: 50,
        inputSchema: [
            'type' => 'object',
            'properties' => ['provider' => ['type' => 'string'], 'contract' => ['type' => 'string']],
            'required' => ['provider'],
            'additionalProperties' => false,
        ],
    ));

    $orderedKeys = array_map(static fn(CapabilityDefinition $definition): string => $definition->key, $registry->all());
    $h->assertSame('checkout.validate', $orderedKeys[0] ?? null, 'capabilities use deterministic priority order');
    $h->assertTrue(array_search('cart.validate', $orderedKeys, true) < array_search('payment.provider', $orderedKeys, true), 'registered priorities are respected');

    $executor = new CapabilityExecutor($registry, new ActionRunRepository($db), new AuthRepository($db));
    $inactive = $executor->execute('cart.validate', ['cart_id' => 1], 'dry_run', 1);
    $h->assertSame('inactive', $inactive->status, 'inactive capability is refused before execution');
    $applyValidator = $executor->execute('checkout.validate', ['cart_id' => 1], 'apply', 1, true);
    $h->assertSame('validator_apply_forbidden', $applyValidator->status, 'validator cannot apply mutations');
    $wrongContract = $executor->execute('payment.provider', ['provider' => 'demo', 'contract' => 'sale.payment_provider.v0'], 'dry_run', 1);
    $h->assertSame('incompatible_contract', $wrongContract->status, 'provider contract mismatch is refused before execution');

    $sale = new SaleModuleProvider();
    $saleCapabilities = [];
    foreach ($sale->capabilities() as $capability) {
        $definition = CapabilityDefinition::fromArray($capability);
        $saleCapabilities[$definition->key] = $definition;
        $h->assertSame([], $definition->config['foreign_tables'] ?? null, 'sale capability avoids direct foreign tables: ' . $definition->key);
    }
    $h->assertTrue(isset($saleCapabilities['cart.validate']), 'sale declares cart validator capability');
    $h->assertSame('validator', $saleCapabilities['checkout.validate']->type ?? null, 'sale checkout extension is a validator');
    $h->assertSame('outbox', $saleCapabilities['order.after_place']->config['transport'] ?? null, 'sale after-order extension goes through outbox');
} finally {
    unset($_SESSION['admin_user']);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT capability registry'));
