<?php

declare(strict_types=1);

it('returns a code generation plan without writing', function (): void {
    $document = $this->minimalDocument();
    $this->writeSchema($document);

    $response = $this->postJson('/dber/generate', [
        'write' => false,
    ])->assertOk();

    expect($response->json('data.migrations'))->toHaveCount(2)
        ->and($response->json('data.models'))->toHaveCount(2)
        ->and($response->json('data.migrations.0.contents'))->toContain('Schema::create');

    $paths = array_column($response->json('data.migrations'), 'path');

    foreach ($paths as $path) {
        expect(is_file($path))->toBeFalse();
    }
});

it('generates a dry run from the command', function (): void {
    $this->writeSchema($this->minimalDocument());

    $this->artisan('dber:generate', [
        '--dry-run' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('Migrations:');
});

it('blocks generation when the schema is invalid', function (): void {
    $document = $this->minimalDocument();
    $document['tables']['posts']['fields']['user_id']['references']['table'] = 'missing';
    $this->writeSchema($document);

    $this->postJson('/dber/generate', [
        'write' => false,
    ])->assertStatus(422);
});
