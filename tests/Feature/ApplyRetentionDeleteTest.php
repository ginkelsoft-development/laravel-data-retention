<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\ApplyRetention;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Ginkelsoft\DataRetention\Tests\Models\SoftDeletedClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('audit_entries', function ($table): void {
        $table->id();
        $table->string('action');
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

it('hard-deletes the record and writes one audit row', function (): void {
    $entry = AuditEntry::create(['action' => 'old', 'created_at' => now()->subYears(3)]);

    $log = (new ApplyRetention)->apply($entry, RetentionConfig::for(AuditEntry::class));

    expect(AuditEntry::query()->count())->toBe(0);
    expect(RetentionLogEntry::count())->toBe(1);
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('deleted');
    expect($log->model_type)->toBe(AuditEntry::class);
    expect($log->model_id)->toBe((string) $entry->getKey());
});

it('writes no audit row in dry-run mode', function (): void {
    $entry = AuditEntry::create(['action' => 'old', 'created_at' => now()->subYears(3)]);

    $log = (new ApplyRetention)->apply($entry, RetentionConfig::for(AuditEntry::class), dryRun: true);

    expect($log)->toBeNull();
    expect(AuditEntry::query()->count())->toBe(1);
    expect(RetentionLogEntry::count())->toBe(0);
});

it('chains successive log entries by previous_hash', function (): void {
    $a = AuditEntry::create(['action' => 'a', 'created_at' => now()->subYears(3)]);
    $b = AuditEntry::create(['action' => 'b', 'created_at' => now()->subYears(3)]);
    $c = AuditEntry::create(['action' => 'c', 'created_at' => now()->subYears(3)]);

    $apply = new ApplyRetention;
    $apply->apply($a, RetentionConfig::for(AuditEntry::class));
    $apply->apply($b, RetentionConfig::for(AuditEntry::class));
    $apply->apply($c, RetentionConfig::for(AuditEntry::class));

    $entries = RetentionLogEntry::query()->orderBy('id')->get();

    expect($entries->pluck('previous_hash')->toArray())->toEqual([
        '',
        $entries[0]->hash,
        $entries[1]->hash,
    ]);
});

it('produces a chain that HashChain::verify accepts', function (): void {
    $a = AuditEntry::create(['action' => 'a', 'created_at' => now()->subYears(3)]);
    $b = AuditEntry::create(['action' => 'b', 'created_at' => now()->subYears(3)]);

    $apply = new ApplyRetention;
    $apply->apply($a, RetentionConfig::for(AuditEntry::class));
    $apply->apply($b, RetentionConfig::for(AuditEntry::class));

    $entries = DB::table('retention_log')
        ->orderBy('id')
        ->get()
        ->map(fn ($row) => (array) $row)
        ->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('detects tampering after the fact', function (): void {
    $a = AuditEntry::create(['action' => 'a', 'created_at' => now()->subYears(3)]);
    (new ApplyRetention)->apply($a, RetentionConfig::for(AuditEntry::class));

    // Sneak past the model's append-only guard via the query builder.
    DB::table('retention_log')->where('id', 1)->update([
        'action' => 'anonymized',
    ]);

    $entries = DB::table('retention_log')
        ->orderBy('id')
        ->get()
        ->map(fn ($row) => (array) $row)
        ->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeFalse();
});

it('does not leak PII into the audit log', function (): void {
    $entry = AuditEntry::create(['action' => 'something-personal', 'created_at' => now()->subYears(3)]);

    (new ApplyRetention)->apply($entry, RetentionConfig::for(AuditEntry::class));

    $log = RetentionLogEntry::firstOrFail();

    // The log row must only reference the source by pointer.
    expect($log->toArray())->not->toHaveKey('action_value');
    foreach ($log->toArray() as $key => $value) {
        if (is_string($value)) {
            expect($value)->not->toContain('something-personal');
        }
    }
});

it('force-deletes soft-deleted models so PII does not linger', function (): void {
    config()->set('data-retention.include_soft_deleted', true);

    $model = SoftDeletedClient::create([
        'name' => 'old',
        'ended_at' => now()->subYears(3),
        'created_at' => now()->subYears(3),
    ]);

    // Switch the policy to delete for this test scenario via attribute reflection
    // would be invasive; instead, exercise via ApplyRetention directly with a
    // synthetic delete policy on this soft-deleted model.
    $policy = new RetentionConfig('1 year', 'ended_at', 'delete');

    (new ApplyRetention)->apply($model, $policy);

    expect(SoftDeletedClient::withTrashed()->count())->toBe(0);
});
