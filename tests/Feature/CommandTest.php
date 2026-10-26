<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('validates a correct schema from the command line', function (): void {
    $this->writeSchema($this->baseStub());

    $this->artisan('dber:validate')->assertSuccessful();
});

it('fails validation for dangling relations', function (): void {
    $document = $this->minimalDocument();
    $document['tables']['posts']['fields']['user_id']['references']['table'] = 'missing';
    $this->writeSchema($document);

    $this->artisan('dber:validate')->assertFailed();
});

it('exports context and json to a path', function (): void {
    $this->writeSchema($this->baseStub());
    $context = $this->tempDirectory.'/context.md';
    $json = $this->tempDirectory.'/schema.json';

    $this->artisan('dber:export', [
        'format' => 'context',
        '--path' => $context,
    ])->assertSuccessful();

    $this->artisan('dber:export', [
        'format' => 'json',
        '--path' => $json,
    ])->assertSuccessful();

    expect(File::get($context))->toContain('## Relationships')
        ->and(json_decode(File::get($json), true, 512, JSON_THROW_ON_ERROR))
        ->toHaveKey('tables');
});

it('installs the schema files', function (): void {
    File::delete($this->app['config']->get('dber.path'));
    $this->app->useConfigPath($this->tempDirectory.'/config');
    File::ensureDirectoryExists($this->tempDirectory.'/config');

    $this->artisan('dber:install')->assertSuccessful();

    expect(File::exists($this->app['config']->get('dber.path')))->toBeTrue()
        ->and(File::exists($this->tempDirectory.'/schema.json'))->toBeTrue()
        ->and(File::exists($this->tempDirectory.'/AGENTS.md'))->toBeTrue();

    $document = json_decode(
        File::get($this->app['config']->get('dber.path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($document['tables'])->toHaveKey('users');
});

it('installs a yaml schema when the configured path uses yaml', function (): void {
    $path = $this->tempDirectory.'/base.yml';
    $this->app['config']->set('dber.path', $path);
    $this->app->useConfigPath($this->tempDirectory.'/config');
    File::ensureDirectoryExists($this->tempDirectory.'/config');
    File::delete($path);

    $this->artisan('dber:install')->assertSuccessful();

    $document = Yaml::parse(File::get($path));

    expect($document['tables'])->toHaveKey('users')
        ->and($document['$schema'])->toBe('./schema.json');
});
