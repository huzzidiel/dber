<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function writeMigration(string $directory, string $name, string $contents): void
{
    File::ensureDirectoryExists($directory);
    File::put($directory.'/'.$name.'.php', $contents);
}

it('imports a schema from migrations via the command', function (): void {
    $directory = $this->tempDirectory.'/migrations';

    writeMigration($directory, '2024_01_01_000000_create_users_table', <<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends \Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                Schema::create('users', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }
        };
        PHP);

    writeMigration($directory, '2024_01_02_000000_create_posts_table', <<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends \Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                Schema::create('posts', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                    $table->string('title');
                    $table->timestamps();
                });
            }
        };
        PHP);

    $this->artisan('dber:import', [
        '--path' => $directory,
    ])->assertSuccessful();

    $stored = json_decode(
        File::get($this->app['config']->get('dber.path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($stored['tables'])->toHaveKeys(['users', 'posts'])
        ->and($stored['tables']['posts']['fields']['user_id']['references']['table'])->toBe('users')
        ->and($stored['tables']['users']['fields'])->toHaveKeys(['id', 'name', 'created_at', 'updated_at']);
});

it('imports via the endpoint and prunes removed columns', function (): void {
    $directory = $this->tempDirectory.'/migrations';
    $this->app['config']->set('dber.migrations_path', $directory);

    writeMigration($directory, '2024_01_01_000000_create_users_table', <<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends \Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                Schema::create('users', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                });
            }
        };
        PHP);

    $document = $this->minimalDocument();
    $document['tables']['users'] = [
        'model' => 'App\\Models\\User',
        'primary_key' => ['id'],
        'fields' => [
            'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'string', 'length' => 255],
            'nickname' => ['type' => 'string', 'length' => 255],
        ],
    ];
    $this->writeSchema($document);

    $response = $this->postJson('/dber/import', [
        'prune' => true,
    ])->assertOk();

    $users = $response->json('data.document.tables.users');

    expect(array_keys($users['fields']))->toBe(['id', 'name'])
        ->and($users['model'])->toBe('App\\Models\\User');

    // keep-extra conserva la columna eliminada.
    $response = $this->postJson('/dber/import', [
        'prune' => false,
    ])->assertOk();

    $users = $response->json('data.document.tables.users');

    expect(array_keys($users['fields']))->toContain('nickname');
});
