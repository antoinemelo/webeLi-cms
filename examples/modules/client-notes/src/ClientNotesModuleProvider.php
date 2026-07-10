<?php

declare(strict_types=1);

namespace ClientModules\ClientNotes;

use App\Module\ModuleProvider;

/**
 * Minimal non-production example of a client module provider.
 *
 * This provider is not registered by default. Copy the structure to
 * local/modules/<module-key>/ before using it on a real instance.
 */
final class ClientNotesModuleProvider implements ModuleProvider
{
    public function key(): string { return 'client-notes'; }

    public function name(): string { return 'Client Notes Example'; }

    public function version(): string { return '0.1.0'; }

    public function description(): string
    {
        return 'Minimal example module showing a dedicated client SQLite database and migration.';
    }

    /** @return list<string> */
    public function dependencies(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function databases(): array
    {
        return [[
            'key' => 'client_notes',
            'path' => 'storage/database/client_notes.sqlite',
            'schema' => 'examples/modules/client-notes/database/schema.sql',
            'migrations' => 'examples/modules/client-notes/database/migrations',
        ]];
    }

    /** @return list<array<string,string>> */
    public function permissions(): array
    {
        return [[
            'key' => 'client_notes.read',
            'name' => 'Lire les notes client',
            'description' => 'Autorise la consultation des notes du module client exemple.',
        ]];
    }

    /** @return array<string,mixed> */
    public function settingsSchema(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function blueprints(): array
    {
        return [[
            'module' => 'client-notes',
            'resource' => 'note',
            'blueprint_key' => 'client_notes_note',
            'resource_type' => 'module_resource',
            'storage' => ['database' => 'client_notes', 'table' => 'client_notes', 'primary_key' => 'id'],
            'capabilities' => ['admin' => false, 'headless' => false, 'public' => false],
            'permissions' => ['read' => 'client_notes.read'],
            'fields' => [
                ['field_key' => 'title', 'label' => 'Titre', 'field_type' => 'text', 'is_required' => true],
                ['field_key' => 'body', 'label' => 'Note', 'field_type' => 'textarea', 'is_required' => false],
            ],
        ]];
    }

    /** @return list<array<string,mixed>> */
    public function contentTypes(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function adminNavigation(): array { return []; }

    /** @return list<array{0:string,1:string,2:string}> */
    public function adminRoutes(): array { return []; }

    /** @return list<array{0:string,1:string,2:string}> */
    public function apiRoutes(): array { return []; }

    /** @return list<array{0:string,1:string,2:string}> */
    public function publicHeadlessRoutes(): array { return []; }

    /** @return array<string,list<callable|string>> */
    public function hooks(): array { return []; }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    public function migrations(): array { return []; }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    public function seeds(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function apiContracts(): array { return []; }
}
