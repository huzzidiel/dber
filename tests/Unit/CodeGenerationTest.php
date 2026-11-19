<?php

declare(strict_types=1);

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Table;
use Huzzidiel\Dber\Support\CodeGenerator;
use Huzzidiel\Dber\Support\DiagramNormalizer;
use Huzzidiel\Dber\Support\DiagramValidator;
use Huzzidiel\Dber\Support\MigrationCodeGenerator;
use Huzzidiel\Dber\Support\MigrationFileParser;
use Huzzidiel\Dber\Support\MigrationImporter;
use Huzzidiel\Dber\Support\MigrationSchemaBuilder;
use Huzzidiel\Dber\Support\ModelCodeGenerator;
use Huzzidiel\Dber\Support\RelationResolver;
use Huzzidiel\Dber\Support\SchemaCompiler;
use Huzzidiel\Dber\Support\SchemaMerger;

function genCompiler(): SchemaCompiler
{
    return new SchemaCompiler(new DiagramNormalizer(), new DiagramValidator(), new RelationResolver());
}

/**
 * @return array{compiled: CompiledDiagram, table: Table}
 */
function genUsers(): array
{
    $compiled = genCompiler()->compile([
        'name' => 'Test',
        'tables' => [
            'users' => [
                'model' => 'App\\Models\\User',
                'primary_key' => ['id'],
                'fields' => [
                    'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true],
                    'name' => ['type' => 'string', 'length' => 255],
                    'email' => ['type' => 'string', 'length' => 255, 'unique' => true],
                    'role_id' => ['type' => 'bigint', 'unsigned' => true, 'references' => ['table' => 'roles', 'field' => 'id', 'on_delete' => 'cascade']],
                    'status' => ['type' => 'enum', 'values' => ['active', 'inactive'], 'default' => 'active'],
                    'created_at' => ['type' => 'timestamp', 'nullable' => true],
                    'updated_at' => ['type' => 'timestamp', 'nullable' => true],
                    'deleted_at' => ['type' => 'timestamp', 'nullable' => true],
                ],
            ],
        ],
    ]);

    return [
        'compiled' => $compiled,
        'table' => $compiled->diagram->tables['users'],
    ];
}

it('generates a create migration', function (): void {
    ['table' => $table] = genUsers();
    $contents = (new MigrationCodeGenerator())->createMigration($table);

    expect($contents)->toContain("Schema::create('users', function (Blueprint \$table)")
        ->and($contents)->toContain('$table->id();')
        ->and($contents)->toContain("\$table->string('name', 255);")
        ->and($contents)->toContain("\$table->string('email', 255)->unique();")
        ->and($contents)->toContain("\$table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');")
        ->and($contents)->toContain("\$table->enum('status', ['active', 'inactive'])->default('active');")
        ->and($contents)->toContain('$table->timestamps();')
        ->and($contents)->toContain('$table->softDeletes();')
        ->and($contents)->toContain("Schema::dropIfExists('users');");
});

it('generates an alter migration for missing fields', function (): void {
    ['table' => $table] = genUsers();
    $missing = [
        $table->fields['name'],
        $table->fields['email'],
    ];

    $contents = (new MigrationCodeGenerator())->alterMigration($table, $missing);

    expect($contents)->toContain("Schema::table('users', function (Blueprint \$table)")
        ->and($contents)->toContain("\$table->string('name', 255);")
        ->and($contents)->toContain("\$table->string('email', 255)->unique();")
        ->and($contents)->toContain("\$table->dropColumn(['name', 'email']);");
});

it('generates a new model', function (): void {
    ['table' => $table] = genUsers();
    $generator = new ModelCodeGenerator();
    $info = $generator->className($table);
    $contents = $generator->generateNew($table, $info['namespace'], $info['class']);

    expect($info['namespace'])->toBe('App\\Models')
        ->and($info['class'])->toBe('User')
        ->and($contents)->toContain('class User extends Model')
        ->and($contents)->toContain('use SoftDeletes;')
        ->and($contents)->toContain('use Illuminate\\Database\\Eloquent\\SoftDeletes;')
        ->and($contents)->toContain("'name',")
        ->and($contents)->toContain("'role_id',")
        ->and($contents)->toContain("'status' => 'string',")
        ->and($contents)->toContain("'role_id' => 'integer',");
});

it('merges fields and casts into an existing model', function (): void {
    ['table' => $table] = genUsers();
    $existing = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\n"
        ."class User extends Model\n{\n    protected \$fillable = ['name'];\n\n    public function custom(): void {}\n}\n";

    $updated = (new ModelCodeGenerator())->updateExisting($existing, $table, 'User');

    expect($updated)->toContain("'email',")
        ->and($updated)->toContain("'role_id',")
        ->and($updated)->toContain("'status' => 'string',")
        ->and($updated)->toContain('use SoftDeletes;')
        ->and($updated)->toContain('public function custom(): void {}');
});

it('plans migrations and models from the diagram', function (): void {
    $compiled = genCompiler()->compile([
        'name' => 'Test',
        'tables' => [
            'users' => [
                'model' => 'App\\Models\\User',
                'primary_key' => ['id'],
                'fields' => [
                    'id' => ['type' => 'bigint', 'unsigned' => true, 'auto_increment' => true],
                    'name' => ['type' => 'string', 'length' => 255],
                ],
            ],
        ],
    ]);

    $compiler = genCompiler();
    $importer = new MigrationImporter(new MigrationFileParser(), new MigrationSchemaBuilder(), new SchemaMerger(), $compiler);
    $generator = new CodeGenerator($importer, new MigrationCodeGenerator(), new ModelCodeGenerator());

    $migrations = sys_get_temp_dir().'/dber-gen-'.uniqid();
    $models = sys_get_temp_dir().'/dber-models-'.uniqid();
    mkdir($migrations);
    mkdir($models);

    try {
        $plan = $generator->plan($compiled, $migrations, $models);

        expect($plan['migrations'])->toHaveCount(1)
            ->and($plan['migrations'][0]['action'])->toBe('create')
            ->and($plan['models'])->toHaveCount(1)
            ->and($plan['models'][0]['action'])->toBe('create');

        $generator->write($plan);

        expect(is_file($plan['migrations'][0]['path']))->toBeTrue()
            ->and(is_file($plan['models'][0]['path']))->toBeTrue();
    } finally {
        array_map('unlink', glob($migrations.'/*.php') ?: []);
        array_map('unlink', glob($models.'/*.php') ?: []);
        @rmdir($migrations);
        @rmdir($models);
    }
});
