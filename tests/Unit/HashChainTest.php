<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Support\HashChain;

it('produces a 64-character hex SHA-256 hash', function (): void {
    $hash = HashChain::compute(
        payload: ['model_type' => 'App\\Models\\Client', 'action' => 'deleted'],
        previousHash: '',
        secret: 'test',
    );

    expect($hash)->toMatch('/^[a-f0-9]{64}$/');
});

it('is deterministic for the same input', function (): void {
    $payload = ['a' => 1, 'b' => 'two'];

    $first = HashChain::compute($payload, '', 'secret');
    $second = HashChain::compute($payload, '', 'secret');

    expect($first)->toBe($second);
});

it('is independent of associative key order', function (): void {
    $a = HashChain::compute(['x' => 1, 'y' => 2], '', 'secret');
    $b = HashChain::compute(['y' => 2, 'x' => 1], '', 'secret');

    expect($a)->toBe($b);
});

it('changes when the previous hash changes', function (): void {
    $payload = ['action' => 'deleted'];

    $a = HashChain::compute($payload, str_repeat('0', 64), 'secret');
    $b = HashChain::compute($payload, str_repeat('1', 64), 'secret');

    expect($a)->not->toBe($b);
});

it('changes when the secret changes', function (): void {
    $payload = ['action' => 'deleted'];

    $a = HashChain::compute($payload, '', 'one');
    $b = HashChain::compute($payload, '', 'two');

    expect($a)->not->toBe($b);
});

it('normalizes DateTimeInterface values consistently', function (): void {
    $instant = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

    $a = HashChain::compute(['ts' => $instant], '', 'secret');
    $b = HashChain::compute(['ts' => $instant->format(DATE_ATOM)], '', 'secret');

    expect($a)->toBe($b);
});

function chainBuild(array $payloads, string $secret): array
{
    $entries = [];
    $previous = '';

    foreach ($payloads as $payload) {
        $hash = HashChain::compute($payload, $previous, $secret);
        $entries[] = $payload + ['previous_hash' => $previous, 'hash' => $hash];
        $previous = $hash;
    }

    return $entries;
}

it('verifies an untampered chain', function (): void {
    $entries = chainBuild([
        ['model_type' => 'A', 'model_id' => '1', 'action' => 'deleted'],
        ['model_type' => 'A', 'model_id' => '2', 'action' => 'anonymized'],
        ['model_type' => 'B', 'model_id' => '3', 'action' => 'deleted'],
    ], 'secret');

    expect(HashChain::verify($entries, 'secret'))->toBeTrue();
});

it('detects tampering with payload content', function (): void {
    $entries = chainBuild([
        ['model_type' => 'A', 'model_id' => '1', 'action' => 'deleted'],
        ['model_type' => 'A', 'model_id' => '2', 'action' => 'anonymized'],
    ], 'secret');

    // Silently change a value without recomputing the hash.
    $entries[1]['action'] = 'deleted';

    expect(HashChain::verify($entries, 'secret'))->toBeFalse();
});

it('detects insertion of a forged entry mid-chain', function (): void {
    $entries = chainBuild([
        ['model_type' => 'A', 'model_id' => '1', 'action' => 'deleted'],
        ['model_type' => 'A', 'model_id' => '3', 'action' => 'deleted'],
    ], 'secret');

    // Inject a fake entry between the two valid ones.
    $forged = [
        'model_type' => 'A',
        'model_id' => '2',
        'action' => 'deleted',
        'previous_hash' => $entries[0]['hash'],
        'hash' => str_repeat('a', 64),
    ];

    $tampered = [$entries[0], $forged, $entries[1]];

    expect(HashChain::verify($tampered, 'secret'))->toBeFalse();
});

it('detects removal of an entry from the chain', function (): void {
    $entries = chainBuild([
        ['model_type' => 'A', 'model_id' => '1', 'action' => 'deleted'],
        ['model_type' => 'A', 'model_id' => '2', 'action' => 'deleted'],
        ['model_type' => 'A', 'model_id' => '3', 'action' => 'deleted'],
    ], 'secret');

    // Drop the middle entry; the third entry now has an unreachable previous_hash.
    $tampered = [$entries[0], $entries[2]];

    expect(HashChain::verify($tampered, 'secret'))->toBeFalse();
});

it('detects verification with the wrong secret', function (): void {
    $entries = chainBuild([
        ['model_type' => 'A', 'model_id' => '1', 'action' => 'deleted'],
    ], 'secret');

    expect(HashChain::verify($entries, 'different-secret'))->toBeFalse();
});
