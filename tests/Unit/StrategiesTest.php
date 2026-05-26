<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Strategies\HashStrategy;
use Ginkelsoft\DataRetention\Strategies\NullStrategy;
use Ginkelsoft\DataRetention\Strategies\PlaceholderStrategy;
use Ginkelsoft\DataRetention\Tests\Models\Client;

it('NullStrategy always returns null', function (): void {
    $strategy = new NullStrategy;
    $model = new Client;

    expect($strategy->apply('anything', 'first_name', $model))->toBeNull()
        ->and($strategy->apply(null, 'first_name', $model))->toBeNull()
        ->and($strategy->apply(42, 'first_name', $model))->toBeNull();
});

it('HashStrategy returns a SHA-256 hex digest', function (): void {
    config()->set('data-retention.log_secret', 'unit-secret');

    $strategy = new HashStrategy;
    $model = new Client;

    $hash = $strategy->apply('123456789', 'bsn', $model);

    expect($hash)->toMatch('/^[a-f0-9]{64}$/');
});

it('HashStrategy returns null for a null input', function (): void {
    $strategy = new HashStrategy;
    $model = new Client;

    expect($strategy->apply(null, 'bsn', $model))->toBeNull();
});

it('HashStrategy is contextual: same value across fields hashes differently', function (): void {
    config()->set('data-retention.log_secret', 'unit-secret');

    $strategy = new HashStrategy;
    $model = new Client;

    $a = $strategy->apply('shared-value', 'bsn', $model);
    $b = $strategy->apply('shared-value', 'phone', $model);

    expect($a)->not->toBe($b);
});

it('HashStrategy is deterministic for the same input', function (): void {
    config()->set('data-retention.log_secret', 'unit-secret');

    $strategy = new HashStrategy;
    $model = new Client;

    expect($strategy->apply('abc', 'bsn', $model))
        ->toBe($strategy->apply('abc', 'bsn', $model));
});

it('PlaceholderStrategy uses the configured default', function (): void {
    config()->set('data-retention.placeholders.string', '[GONE]');

    $strategy = new PlaceholderStrategy;
    $model = new Client;

    expect($strategy->apply('Wietse', 'first_name', $model))->toBe('[GONE]');
});

it('PlaceholderStrategy uses an injected value when provided', function (): void {
    $strategy = new PlaceholderStrategy('custom-value');
    $model = new Client;

    expect($strategy->apply('Wietse', 'first_name', $model))->toBe('custom-value');
});
