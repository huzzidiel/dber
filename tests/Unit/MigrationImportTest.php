<?php

declare(strict_types=1);

use Huzzidiel\Dber\Support\MigrationFileParser;
use Huzzidiel\Dber\Support\MigrationSchemaBuilder;

/**
 * @return array<string, array<string, mixed>>
 */
function migrationSchema(string $contents): array
{
    $parser = new MigrationFileParser();
    $builder = new MigrationSchemaBuilder();
    $operations = [];

    foreach ($parser->parse($contents) as $operation) {
        $operation['file'] = 'test.php';
        $operations[] = $operation;
    }

    return $builder->build($operations, $parser);
}

it('builds a table from a create migration', function (): void {
    $tables = migrationSchema(<<<'PHP'
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        PHP);

    $posts = $tables['posts'];

    expect($posts['primary_key'])->toBe(['id'])
        ->and($posts['fields']['id'])->toMatchArray([
            'type' => 'bigint',
            'unsigned' => true,
            'auto_increment' => true,
        ])
        ->and($posts['fields']['user_id']['references'])->toMatchArray([
            'table' => 'users',
            'field' => 'id',
            'on_delete' => 'cascade',
        ])
        ->and($posts['fields']['title']['type'])->toBe('string')
        ->and($posts['fields']['title']['length'])->toBe(255)
        ->and($posts['fields']['body']['nullable'])->toBeTrue()
        ->and($posts['fields']['status']['values'])->toBe(['draft', 'published'])
        ->and($posts['fields']['status']['default'])->toBe('draft')
        ->and($posts['fields']['price']['precision'])->toBe(10)
        ->and($posts['fields']['price']['scale'])->toBe(2)
        ->and($posts['fields']['is_active']['default'])->toBeTrue()
        ->and($posts['fields'])->toHaveKeys(['created_at', 'updated_at', 'deleted_at']);
});

it('applies alterations from Schema::table', function (): void {
    $contents = <<<'PHP'
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname')->nullable();
            $table->renameColumn('name', 'full_name');
            $table->dropColumn('nickname');
        });
        PHP;

    $tables = migrationSchema($contents);
    $fields = array_keys($tables['users']['fields']);

    expect($fields)->toBe(['id', 'full_name']);
});

it('supports explicit foreign keys and indexes', function (): void {
    $tables = migrationSchema(<<<'PHP'
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->string('number')->unique();
            $table->index(['user_id', 'number']);
        });
        PHP);

    $orders = $tables['orders'];

    expect($orders['fields']['user_id']['references'])->toMatchArray([
        'table' => 'users',
        'field' => 'id',
        'on_delete' => 'restrict',
    ])
        ->and($orders['fields']['number']['unique'])->toBeTrue()
        ->and($orders['indexes'])->toContain([
            'columns' => ['user_id', 'number'],
            'unique' => false,
        ]);
});

it('detects classic pivot tables', function (): void {
    $tables = migrationSchema(<<<'PHP'
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });
        PHP);

    expect($tables['role_user']['pivot'])->toBeTrue();
});

it('ignores the down method when parsing a migration class', function (): void {
    $tables = migrationSchema(<<<'PHP'
        return new class extends \Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                Schema::create('users', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('users');
            }
        };
        PHP);

    expect($tables)->toHaveKey('users');
});

it('handles morphs and remembered tokens', function (): void {
    $tables = migrationSchema(<<<'PHP'
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->rememberToken();
        });
        PHP);

    $comments = $tables['comments'];

    expect($comments['fields'])->toHaveKeys([
        'id',
        'commentable_type',
        'commentable_id',
        'remember_token',
    ])
        ->and($comments['fields']['commentable_type']['type'])->toBe('string')
        ->and($comments['fields']['commentable_id']['type'])->toBe('bigint')
        ->and($comments['fields']['remember_token']['nullable'])->toBeTrue();
});
