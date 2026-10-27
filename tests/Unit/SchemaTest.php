<?php

declare(strict_types=1);

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Relation;
use Huzzidiel\Dber\Support\DiagramNormalizer;
use Huzzidiel\Dber\Support\DiagramValidator;
use Huzzidiel\Dber\Support\MarkdownExporter;
use Huzzidiel\Dber\Support\MermaidExporter;
use Huzzidiel\Dber\Support\RelationResolver;
use Huzzidiel\Dber\Support\SchemaCompiler;
use Huzzidiel\Dber\Support\TypeRegistry;

/**
 * @param array<string, mixed> $document
 */
function dberCompile(array $document): CompiledDiagram
{
    $compiler = new SchemaCompiler(
        new DiagramNormalizer(),
        new DiagramValidator(),
        new RelationResolver(),
    );

    return $compiler->compile($document);
}

/**
 * @return array<string, mixed>
 */
function dberBase(): array
{
    $contents = file_get_contents(__DIR__.'/../../resources/stubs/base.json');

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

it('compiles the example schema without issues', function (): void {
    $compiled = dberCompile(dberBase());

    expect($compiled->issues)->toBeEmpty()
        ->and($compiled->hasErrors())->toBeFalse()
        ->and(array_keys($compiled->diagram->tables))->toBe([
            'users',
            'roles',
            'permissions',
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
        ])
        ->and($compiled->diagram->fieldCount())->toBe(27)
        ->and($compiled->relations)->toHaveCount(5)
        ->and($compiled->pivotTables)->toBe(['role_has_permissions']);
});

it('derives one-to-one and many-to-many relations', function (): void {
    $document = dberBase();
    $document['tables']['profiles'] = [
        'model' => 'App\\Models\\Profile',
        'primary_key' => [
            'id',
        ],
        'fields' => [
            'id' => [
                'type' => 'bigint',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type' => 'bigint',
                'unsigned' => true,
                'unique' => true,
                'references' => [
                    'table' => 'users',
                    'field' => 'id',
                    'on_delete' => 'cascade',
                ],
            ],
        ],
    ];

    $compiled = dberCompile($document);
    $kinds = array_map(static fn (Relation $relation): string => $relation->kind, $compiled->relations);

    expect($kinds)->toContain(Relation::ONE_TO_ONE)
        ->and($kinds)->toContain(Relation::MANY_TO_MANY)
        ->and($kinds)->toContain(Relation::MANY_TO_ONE);

    $profileRelation = array_values(array_filter(
        $compiled->relations,
        static fn (Relation $relation): bool => $relation->fromTable === 'profiles',
    ))[0];

    expect($profileRelation->kind)->toBe(Relation::ONE_TO_ONE)
        ->and($profileRelation->toTable)->toBe('users');
});

it('normalizes variable shapes and warns about unknown keys', function (): void {
    $document = dberBase();
    $document['tables']['users']['primary_key'] = 'id';
    $document['tables']['users']['fields']['email']['comment'] = 'not part of the schema';
    $document['tables']['users']['fields']['email']['nullable'] = 'false';

    $compiled = dberCompile($document);

    expect($compiled->document['tables']['users']['primary_key'])->toBe(['id'])
        ->and($compiled->document['tables']['users']['fields']['email'])->not->toHaveKey('comment')
        ->and($compiled->document['tables']['users']['fields']['email'])->not->toHaveKey('nullable');

    $messages = array_map(static fn ($issue): string => $issue->message, $compiled->issues);

    expect(implode(' ', $messages))->toContain('Unknown property "comment"');
});

it('rejects unknown field types', function (): void {
    $document = dberBase();
    $document['tables']['users']['fields']['email']['type'] = 'varchar';

    $compiled = dberCompile($document);
    $errors = $compiled->errors();

    expect($compiled->hasErrors())->toBeTrue()
        ->and($errors[0]->path)->toBe('$.tables.users.fields.email.type');
});

it('rejects references to missing tables and fields', function (): void {
    $document = dberBase();
    $document['tables']['model_has_roles']['fields']['role_id']['references']['table'] = 'missing';
    $document['tables']['model_has_roles']['fields']['role_id']['references']['field'] = 'nope';

    $compiled = dberCompile($document);
    $paths = array_map(static fn ($issue): string => $issue->path, $compiled->errors());

    expect($paths)->toContain('$.tables.model_has_roles.fields.role_id.references.table');
});

it('rejects primary keys that do not exist', function (): void {
    $document = dberBase();
    $document['tables']['users']['primary_key'] = ['uuid'];

    $compiled = dberCompile($document);
    $paths = array_map(static fn ($issue): string => $issue->path, $compiled->errors());

    expect($paths)->toContain('$.tables.users.primary_key');
});

it('warns when a pivot table has fewer than two foreign keys', function (): void {
    $document = dberBase();
    unset($document['tables']['role_has_permissions']['fields']['role_id']['references']);

    $compiled = dberCompile($document);
    $warnings = array_values(array_filter(
        $compiled->issues,
        static fn ($issue): bool => $issue->severity === 'warning',
    ));

    expect($compiled->hasErrors())->toBeFalse()
        ->and(array_map(static fn ($issue): string => $issue->path, $warnings))
        ->toContain('$.tables.role_has_permissions.pivot');
});

it('keeps explicit null defaults distinct from no default', function (): void {
    $document = dberBase();
    $document['tables']['users']['fields']['remember_token']['default'] = null;

    $compiled = dberCompile($document);
    $field = $compiled->diagram->tables['users']->field('remember_token');
    $named = $compiled->diagram->tables['users']->field('name');

    expect($field)->not->toBeNull()
        ->and($field->hasDefault)->toBeTrue()
        ->and($field->default)->toBeNull()
        ->and($named)->not->toBeNull()
        ->and($named->hasDefault)->toBeFalse();
});

it('supports composite primary keys', function (): void {
    $compiled = dberCompile(dberBase());
    $pivot = $compiled->diagram->tables['role_has_permissions'];

    expect($pivot->primaryKey)->toBe(['permission_id', 'role_id'])
        ->and($pivot->pivot)->toBeTrue();
});

it('keeps lifecycle timestamps at the end of the field list', function (): void {
    $document = dberBase();
    $document['tables']['users']['fields'] = array_reverse(
        $document['tables']['users']['fields'],
        true,
    );

    $compiled = dberCompile($document);
    $names = array_keys($compiled->document['tables']['users']['fields']);

    expect(array_slice($names, -3))->toBe([
        'created_at',
        'updated_at',
        'deleted_at',
    ]);
});

it('exports markdown with tables and relationships', function (): void {
    $compiled = dberCompile(dberBase());
    $markdown = (new MarkdownExporter())->export($compiled, 'diagram-er/base.json');

    expect($markdown)->toContain('# Database schema: Laravel application')
        ->and($markdown)->toContain('## How to read and edit this schema')
        ->and($markdown)->toContain('### `users`')
        ->and($markdown)->toContain('many-to-many via `role_has_permissions`')
        ->and($markdown)->toContain('`model_has_roles.role_id` → `roles.id`');
});

it('exports valid mermaid syntax', function (): void {
    $compiled = dberCompile(dberBase());
    $mermaid = (new MermaidExporter())->export($compiled);

    expect($mermaid)->toStartWith('erDiagram')
        ->and($mermaid)->toContain('users {')
        ->and($mermaid)->toContain('BIGINT id PK')
        ->and($mermaid)->toContain('roles ||--o{ model_has_roles : "role_id"')
        ->and($mermaid)->toContain('Many-to-many via role_has_permissions');
});

it('maps types to laravel migration methods', function (): void {
    expect(TypeRegistry::laravelMethod('bigint'))->toBe('bigInteger')
        ->and(TypeRegistry::laravelMethod('datetime'))->toBe('dateTime')
        ->and(TypeRegistry::sqlType('string'))->toBe('VARCHAR')
        ->and(TypeRegistry::supportsLength('string'))->toBeTrue()
        ->and(TypeRegistry::supportsLength('integer'))->toBeFalse()
        ->and(TypeRegistry::supportsValues('enum'))->toBeTrue();
});
