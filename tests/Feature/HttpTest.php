<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('renders the viewer shell', function (): void {
    $this->get('/dber')
        ->assertOk()
        ->assertSee('id="dber-app"', false)
        ->assertSee('/dber/assets/js/dber.js', false);
});

it('returns the compiled schema payload', function (): void {
    $hash = $this->writeSchema($this->baseStub());

    $response = $this->getJson('/dber/data')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.stats.tables', 6)
        ->assertJsonPath('data.stats.pivots', 1);

    expect($response->json('data.hash'))->toBe($hash)
        ->and($response->json('data.relations'))->toHaveCount(5)
        ->and($response->json('data.document.tables.users.fields.email.unique'))->toBeTrue();
});

it('returns an empty document when the file does not exist', function (): void {
    $this->getJson('/dber/data')
        ->assertOk()
        ->assertJsonPath('data.exists', false)
        ->assertJsonPath('data.document.tables', [])
        ->assertJsonPath('data.issues', []);
});

it('saves the schema and drops unknown keys', function (): void {
    $document = $this->minimalDocument();
    $hash = $this->writeSchema($document);

    $document['tables']['posts']['fields']['title']['length'] = 200;
    $document['tables']['users']['fields']['email']['unknown_key'] = 'drop me';

    $response = $this->putJson('/dber/data', [
        'document' => $document,
        'hash' => $hash,
    ])->assertOk();

    expect($response->json('data.document.tables.posts.fields.title.length'))->toBe(200)
        ->and($response->json('data.issues.0.severity'))->toBe('warning');

    $stored = json_decode(
        File::get($this->app['config']->get('dber.path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($stored['tables']['posts']['fields']['title']['length'])->toBe(200)
        ->and($stored['tables']['users']['fields']['email'])->not->toHaveKey('unknown_key');
});

it('rejects a stale save with a conflict', function (): void {
    $document = $this->minimalDocument();
    $oldHash = $this->writeSchema($document);
    $document['name'] = 'Changed externally';
    $newHash = $this->writeSchema($document);

    $response = $this->putJson('/dber/data', [
        'document' => $this->minimalDocument(),
        'hash' => $oldHash,
    ])->assertStatus(409);

    expect($response->json('data.hash'))->toBe($newHash);
});

it('overwrites a stale file when force is true', function (): void {
    $document = $this->minimalDocument();
    $oldHash = $this->writeSchema($document);
    $this->writeSchema($document);

    $document['name'] = 'Forced name';

    $this->putJson('/dber/data', [
        'document' => $document,
        'hash' => $oldHash,
        'force' => true,
    ])->assertOk();

    $stored = json_decode(
        File::get($this->app['config']->get('dber.path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($stored['name'])->toBe('Forced name');
});

it('rejects an invalid schema', function (): void {
    $document = $this->minimalDocument();
    $document['tables']['posts']['fields']['user_id']['references']['table'] = 'missing_table';
    $hash = $this->writeSchema($document);

    $response = $this->putJson('/dber/data', [
        'document' => $document,
        'hash' => $hash,
    ])->assertStatus(422);

    expect($response->json('errors'))->not->toBeEmpty();

    $paths = array_column($response->json('errors'), 'path');

    expect($paths)->toContain('$.tables.posts.fields.user_id.references.table');
});

it('blocks saving when the editor is disabled', function (): void {
    $document = $this->minimalDocument();
    $hash = $this->writeSchema($document);

    $this->app['config']->set('dber.editor', false);

    $this->putJson('/dber/data', [
        'document' => $document,
        'hash' => $hash,
    ])->assertStatus(403);
});

it('exports the AI context as markdown', function (): void {
    $this->writeSchema($this->baseStub());

    $response = $this->get('/dber/context.md')
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8');

    $contents = $response->getContent();

    expect($contents)->toContain('## Tables')
        ->and($contents)->toContain('## Relationships')
        ->and($contents)->toContain('role_has_permissions')
        ->and($contents)->toContain('many-to-many')
        ->and($contents)->toContain('### `roles`');
});

it('exports a mermaid diagram', function (): void {
    $this->writeSchema($this->baseStub());

    $response = $this->get('/dber/diagram.mmd')
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');

    $contents = $response->getContent();

    expect($contents)->toContain('erDiagram')
        ->and($contents)->toContain('users {')
        ->and($contents)->toContain('roles {');
});

it('refuses to export an invalid schema', function (): void {
    $document = $this->minimalDocument();
    $document['tables']['posts']['fields']['user_id']['references']['table'] = 'missing_table';
    $this->writeSchema($document);

    $this->getJson('/dber/context.md')->assertStatus(422);
    $this->getJson('/dber/diagram.mmd')->assertStatus(422);
});

it('returns 404 when exporting a missing schema', function (): void {
    $this->getJson('/dber/context.md')->assertStatus(404);
    $this->getJson('/dber/diagram.mmd')->assertStatus(404);
});

it('returns a clear error for invalid json', function (): void {
    File::put($this->app['config']->get('dber.path'), '{ invalid json');

    $response = $this->getJson('/dber/data')->assertStatus(422);

    expect($response->json('errors.0.path'))->toBe('$')
        ->and($response->json('message'))->toContain('invalid JSON');
});

it('serves package assets with the correct mime type', function (): void {
    $this->get('/dber/assets/js/dber.js')
        ->assertOk()
        ->assertHeader('content-type', 'text/javascript; charset=UTF-8');

    $this->get('/dber/assets/css/dber.css')
        ->assertOk()
        ->assertHeader('content-type', 'text/css; charset=UTF-8');

    $this->get('/dber/assets/favicon.svg')
        ->assertOk()
        ->assertHeader('content-type', 'image/svg+xml');
});

it('blocks asset traversal and unknown assets', function (): void {
    $this->get('/dber/assets/../composer.json')->assertNotFound();
    $this->get('/dber/assets/js/../../composer.json')->assertNotFound();
    $this->get('/dber/assets/unknown.txt')->assertNotFound();
});

it('serves the json schema', function (): void {
    $this->get('/dber/schema.json')
        ->assertOk()
        ->assertHeader('content-type', 'application/schema+json; charset=UTF-8');
});
