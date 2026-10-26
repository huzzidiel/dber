<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Tests;

use Huzzidiel\Dber\DberServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base de las pruebas del paquete.
 */
abstract class TestCase extends OrchestraTestCase
{
    protected string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = sys_get_temp_dir().'/dber-tests-'.Str::random(10);
        File::ensureDirectoryExists($this->tempDirectory);

        $this->app['config']->set('dber.path', $this->tempDirectory.'/base.json');
        $this->app['config']->set('dber.max_size', 2097152);
        $this->app['config']->set('dber.editor', true);
        $this->app['config']->set('dber.poll_interval', 0);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DberServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('dber.enabled', true);
        $app['config']->set('dber.route.middleware', []);
        $app['config']->set('dber.route.prefix', 'dber');
        $app['config']->set('dber.editor', true);
        $app['config']->set('dber.poll_interval', 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseStub(): array
    {
        $contents = file_get_contents(__DIR__.'/../resources/stubs/base.json');

        $this->assertIsString($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function writeSchema(array $document): string
    {
        $path = $this->app['config']->get('dber.path');
        $encoded = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        file_put_contents($path, $encoded."\n");

        return hash('sha256', $encoded."\n");
    }

    /**
     * @return array<string, mixed>
     */
    protected function minimalDocument(): array
    {
        return [
            '$schema' => './schema.json',
            'schema_version' => 1,
            'name' => 'Test schema',
            'database' => 'mysql',
            'tables' => [
                'users' => [
                    'model' => 'App\\Models\\User',
                    'primary_key' => [
                        'id',
                    ],
                    'fields' => [
                        'id' => [
                            'type' => 'bigint',
                            'unsigned' => true,
                            'auto_increment' => true,
                        ],
                        'email' => [
                            'type' => 'string',
                            'length' => 255,
                            'unique' => true,
                        ],
                    ],
                ],
                'posts' => [
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
                            'references' => [
                                'table' => 'users',
                                'field' => 'id',
                                'on_delete' => 'cascade',
                            ],
                        ],
                        'title' => [
                            'type' => 'string',
                            'length' => 255,
                        ],
                    ],
                ],
            ],
        ];
    }
}
