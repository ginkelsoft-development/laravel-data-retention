<?php

declare(strict_types=1);

use Ginkelsoft\ComplianceCore\Support\HashChain;
use Ginkelsoft\DataRetention\Database\Seeders\RetentionDemoSeeder;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Ginkelsoft\DataRetention\Tests\Models\Client;
use Illuminate\Support\Facades\DB;
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

it('builds a Client with realistic PII via the factory', function (): void {
    $client = Client::factory()->create();

    expect($client->first_name)->toBeString()->not->toBeEmpty()
        ->and($client->last_name)->toBeString()->not->toBeEmpty()
        ->and($client->bsn)->toBeString()->toHaveLength(9)
        ->and($client->phone)->toBeString()->not->toBeEmpty();
});

it('uses the expired() state to produce records past the retention window', function (): void {
    $client = Client::factory()->expired()->create();
    $audit = AuditEntry::factory()->expired()->create();

    expect($client->ended_at)->not->toBeNull()
        ->and($client->ended_at->lt(now()->subYears(5)))->toBeTrue();

    expect($audit->created_at->lt(now()->subYears(2)))->toBeTrue();
});

it('runs the demo seeder and processes only the expired subset', function (): void {
    config()->set('data-retention.models', [AuditEntry::class, Client::class]);

    (new RetentionDemoSeeder)->run();

    expect(AuditEntry::count())->toBe(20)
        ->and(Client::count())->toBe(15);

    $this->artisan('retention:run')->assertExitCode(0);

    // 10 expired audit entries deleted; 10 fresh remain.
    expect(AuditEntry::count())->toBe(10);

    // 5 expired clients anonymized (still present, but redacted); 10 untouched.
    expect(Client::count())->toBe(15);

    $anonymized = Client::query()->where('first_name', '[REDACTED]')->count();
    expect($anonymized)->toBe(5);

    // Log: 10 deletes + 5 anonymizations = 15 entries.
    expect(RetentionLogEntry::count())->toBe(15);

    // Chain still verifies.
    $entries = DB::table('retention_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();
    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('produces a stable chain across a large factory-driven dataset', function (): void {
    config()->set('data-retention.models', [AuditEntry::class]);

    AuditEntry::factory()->count(50)->expired()->create();

    $this->artisan('retention:run', ['--chunk' => 7])->assertExitCode(0);

    expect(RetentionLogEntry::count())->toBe(50);

    $entries = DB::table('retention_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('dry-run on factory data preserves every record and writes no log', function (): void {
    config()->set('data-retention.models', [AuditEntry::class, Client::class]);

    AuditEntry::factory()->count(5)->expired()->create();
    Client::factory()->count(3)->expired()->create();

    $this->artisan('retention:run', ['--dry-run' => true])->assertExitCode(0);

    expect(AuditEntry::count())->toBe(5)
        ->and(Client::query()->where('first_name', '[REDACTED]')->count())->toBe(0)
        ->and(RetentionLogEntry::count())->toBe(0);
});
