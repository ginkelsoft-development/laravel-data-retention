<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\ResolveExpiredRecords;
use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Ginkelsoft\DataRetention\Tests\Models\Client;
use Ginkelsoft\DataRetention\Tests\Models\SoftDeletedClient;
use Ginkelsoft\DataRetention\Tests\Models\UnpolicyedModel;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('audit_entries', function ($table): void {
        $table->id();
        $table->string('action');
        $table->timestamps();
    });

    Schema::create('clients', function ($table): void {
        $table->id();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('bsn')->nullable();
        $table->string('phone')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->timestamps();
    });

    Schema::create('soft_deleted_clients', function ($table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

it('selects records older than the retention period', function (): void {
    AuditEntry::insert([
        ['action' => 'fresh', 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()],
        ['action' => 'old',   'created_at' => now()->subYears(3), 'updated_at' => now()->subYears(3)],
        ['action' => 'on-the-edge', 'created_at' => now()->subYears(2)->subSecond(), 'updated_at' => now()],
    ]);

    $resolver = new ResolveExpiredRecords;
    $results = $resolver->query(AuditEntry::class)->get();

    expect($results->pluck('action')->all())->toEqualCanonicalizing(['old', 'on-the-edge']);
});

it('uses the configured "from" field, not always created_at', function (): void {
    Client::insert([
        // ended a long time ago, but created recently — should still expire on ended_at.
        ['first_name' => 'A', 'ended_at' => now()->subYears(10), 'created_at' => now(), 'updated_at' => now()],
        // active client, no ended_at — must NOT be picked up.
        ['first_name' => 'B', 'ended_at' => null, 'created_at' => now()->subYears(20), 'updated_at' => now()],
    ]);

    $resolver = new ResolveExpiredRecords;
    $results = $resolver->query(Client::class)->get();

    expect($results->pluck('first_name')->all())->toEqual(['A']);
});

it('respects the asOf parameter for deterministic tests', function (): void {
    AuditEntry::insert([
        ['action' => 'a', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
    ]);

    $resolver = new ResolveExpiredRecords;

    // asOf = 2021-06-01 -> 2 years before = 2019-06-01 -> 2020-01-01 NOT yet expired.
    expect($resolver->query(AuditEntry::class, new DateTimeImmutable('2021-06-01'))->count())
        ->toBe(0);

    // asOf = 2023-01-01 -> 2 years before = 2021-01-01 -> 2020-01-01 IS expired.
    expect($resolver->query(AuditEntry::class, new DateTimeImmutable('2023-01-01'))->count())
        ->toBe(1);
});

it('includes soft-deleted records when include_soft_deleted is true', function (): void {
    config()->set('data-retention.include_soft_deleted', true);

    $expired = SoftDeletedClient::create([
        'name' => 'soft-deleted',
        'ended_at' => now()->subYears(3),
        'created_at' => now()->subYears(3),
    ]);
    $expired->delete(); // soft delete

    SoftDeletedClient::create([
        'name' => 'active',
        'ended_at' => now()->subMonth(),
        'created_at' => now()->subMonth(),
    ]);

    $resolver = new ResolveExpiredRecords;
    $results = $resolver->query(SoftDeletedClient::class)->get();

    expect($results->pluck('name')->all())->toEqual(['soft-deleted']);
});

it('excludes soft-deleted records when include_soft_deleted is false', function (): void {
    config()->set('data-retention.include_soft_deleted', false);

    $expired = SoftDeletedClient::create([
        'name' => 'soft-deleted',
        'ended_at' => now()->subYears(3),
        'created_at' => now()->subYears(3),
    ]);
    $expired->delete();

    $resolver = new ResolveExpiredRecords;

    expect($resolver->query(SoftDeletedClient::class)->count())->toBe(0);
});

it('throws when the model has no retention policy', function (): void {
    expect(fn () => (new ResolveExpiredRecords)->query(UnpolicyedModel::class))
        ->toThrow(InvalidArgumentException::class, 'no retention policy');
});

it('skips records with NULL in the from field', function (): void {
    Client::insert([
        ['first_name' => 'null-from', 'ended_at' => null, 'created_at' => now()->subYears(20), 'updated_at' => now()],
    ]);

    $resolver = new ResolveExpiredRecords;

    expect($resolver->query(Client::class)->count())->toBe(0);
});
