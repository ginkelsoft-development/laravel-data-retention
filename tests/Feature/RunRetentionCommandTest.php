<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Ginkelsoft\DataRetention\Tests\Models\Client;
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
        $table->string('bsn', 128)->nullable();
        $table->string('phone')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->timestamps();
    });
});

it('runs across every configured model', function (): void {
    config()->set('data-retention.models', [AuditEntry::class, Client::class]);

    AuditEntry::create(['action' => 'old', 'created_at' => now()->subYears(3)]);
    AuditEntry::create(['action' => 'fresh', 'created_at' => now()->subMonth()]);
    Client::create([
        'first_name' => 'Wietse', 'last_name' => 'X', 'bsn' => 'A', 'phone' => 'B',
        'ended_at' => now()->subYears(6),
    ]);

    $this->artisan('retention:run')->assertExitCode(0);

    expect(AuditEntry::count())->toBe(1)
        ->and(AuditEntry::first()->action)->toBe('fresh')
        ->and(Client::first()->first_name)->toBe('[REDACTED]')
        ->and(RetentionLogEntry::count())->toBe(2);
});

it('changes nothing in --dry-run mode', function (): void {
    config()->set('data-retention.models', [AuditEntry::class]);

    AuditEntry::create(['action' => 'old', 'created_at' => now()->subYears(3)]);

    $this->artisan('retention:run', ['--dry-run' => true])->assertExitCode(0);

    expect(AuditEntry::count())->toBe(1)
        ->and(RetentionLogEntry::count())->toBe(0);
});

it('honors --model to scope the run to a single class', function (): void {
    config()->set('data-retention.models', [AuditEntry::class, Client::class]);

    AuditEntry::create(['action' => 'old', 'created_at' => now()->subYears(3)]);
    Client::create([
        'first_name' => 'X', 'last_name' => 'Y', 'bsn' => 'B', 'phone' => 'P',
        'ended_at' => now()->subYears(6),
    ]);

    $this->artisan('retention:run', ['--model' => AuditEntry::class])->assertExitCode(0);

    expect(AuditEntry::count())->toBe(0)
        ->and(Client::first()->first_name)->toBe('X');
});

it('is idempotent: a second run finds nothing to do', function (): void {
    config()->set('data-retention.models', [AuditEntry::class]);

    AuditEntry::create(['action' => 'old', 'created_at' => now()->subYears(3)]);

    $this->artisan('retention:run')->assertExitCode(0);
    $firstLogCount = RetentionLogEntry::count();

    $this->artisan('retention:run')->assertExitCode(0);

    expect(RetentionLogEntry::count())->toBe($firstLogCount);
});

it('warns when no models are configured', function (): void {
    config()->set('data-retention.models', []);

    $this->artisan('retention:run')
        ->expectsOutputToContain('No models configured for retention')
        ->assertExitCode(0);
});

it('rejects an invalid --chunk value', function (): void {
    $this->artisan('retention:run', ['--chunk' => '0'])
        ->expectsOutputToContain('--chunk must be a positive integer')
        ->assertExitCode(1);
});

it('processes records in chunks larger than chunk-size', function (): void {
    config()->set('data-retention.models', [AuditEntry::class]);

    for ($i = 0; $i < 25; $i++) {
        AuditEntry::create(['action' => "entry-{$i}", 'created_at' => now()->subYears(3)]);
    }

    $this->artisan('retention:run', ['--chunk' => 5])->assertExitCode(0);

    expect(AuditEntry::count())->toBe(0)
        ->and(RetentionLogEntry::count())->toBe(25);
});

it('skips models that are configured but lack a policy', function (): void {
    config()->set('data-retention.models', [
        UnpolicyedModel::class,
    ]);

    $this->artisan('retention:run')
        ->expectsOutputToContain('no retention policy declared')
        ->assertExitCode(0);
});
