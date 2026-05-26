<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Models\RetentionLogEntry;

it('persists a retention log entry', function (): void {
    $entry = RetentionLogEntry::create([
        'model_type' => 'App\\Models\\Client',
        'model_id' => '01HXYZ',
        'action' => 'deleted',
        'retention_period' => '2 years',
        'retention_field' => 'created_at',
        'expired_at' => now()->subDay(),
        'performed_at' => now(),
        'previous_hash' => '',
        'hash' => str_repeat('a', 64),
    ]);

    expect($entry->exists)->toBeTrue();
    expect(RetentionLogEntry::count())->toBe(1);
});

it('forbids updating an existing entry', function (): void {
    $entry = RetentionLogEntry::create([
        'model_type' => 'App\\Models\\Client',
        'model_id' => '1',
        'action' => 'deleted',
        'retention_period' => '2 years',
        'retention_field' => 'created_at',
        'expired_at' => now()->subDay(),
        'performed_at' => now(),
        'previous_hash' => '',
        'hash' => str_repeat('a', 64),
    ]);

    $entry->action = 'anonymized';

    expect(fn () => $entry->save())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('forbids deleting an existing entry', function (): void {
    $entry = RetentionLogEntry::create([
        'model_type' => 'App\\Models\\Client',
        'model_id' => '1',
        'action' => 'deleted',
        'retention_period' => '2 years',
        'retention_field' => 'created_at',
        'expired_at' => now()->subDay(),
        'performed_at' => now(),
        'previous_hash' => '',
        'hash' => str_repeat('a', 64),
    ]);

    expect(fn () => $entry->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});
