<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('reads and writes yaml schemas', function (): void {
    $path = $this->tempDirectory.'/base.yaml';
    $this->app['config']->set('dber.path', $path);
    File::put($path, Yaml::dump($this->minimalDocument(), 12, 2));

    $response = $this->getJson('/dber/data')
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.stats.tables', 2);

    $payload = $response->json('data');
    $document = $payload['document'];
    $document['name'] = 'YAML schema';

    $this->putJson('/dber/data', [
        'document' => $document,
        'hash' => $payload['hash'],
    ])->assertOk();

    $stored = Yaml::parse(File::get($path));

    expect($stored['name'])->toBe('YAML schema')
        ->and($stored['tables']['users']['primary_key'])->toBe(['id']);
});

it('rejects a non empty top level list', function (): void {
    File::put(
        $this->app['config']->get('dber.path'),
        json_encode([['name' => 'not a document']], JSON_THROW_ON_ERROR),
    );

    $this->getJson('/dber/data')->assertStatus(422);
});

it('serves the schema file published next to the diagram', function (): void {
    $path = $this->tempDirectory.'/schema.json';
    File::put($path, '{"title":"Custom schema"}');

    $response = $this->get('/dber/schema.json')
        ->assertOk()
        ->assertHeader('content-type', 'application/schema+json; charset=UTF-8');

    expect(realpath((string) $response->baseResponse->getFile()?->getPathname()))
        ->toBe(realpath($path));
});
