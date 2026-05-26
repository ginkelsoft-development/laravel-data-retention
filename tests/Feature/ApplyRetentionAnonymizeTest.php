<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\ApplyRetention;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Ginkelsoft\DataRetention\Tests\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
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

it('anonymizes configured fields and writes one audit row', function (): void {
    $client = Client::create([
        'first_name' => 'Wietse',
        'last_name' => 'van Ginkel',
        'bsn' => '123456789',
        'phone' => '+31612345678',
        'ended_at' => now()->subYears(6),
    ]);

    $log = (new ApplyRetention)->apply($client, RetentionConfig::for(Client::class));

    $client->refresh();

    expect($client->first_name)->toBe('[REDACTED]')
        ->and($client->last_name)->toBe('[REDACTED]')
        ->and($client->phone)->toBeNull()
        ->and($client->bsn)->toMatch('/^[a-f0-9]{64}$/'); // hash strategy

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('anonymized')
        ->and(RetentionLogEntry::count())->toBe(1);
});

it('makes no changes in dry-run mode', function (): void {
    $client = Client::create([
        'first_name' => 'Wietse',
        'last_name' => 'van Ginkel',
        'bsn' => '123456789',
        'phone' => '+31612345678',
        'ended_at' => now()->subYears(6),
    ]);

    (new ApplyRetention)->apply($client, RetentionConfig::for(Client::class), dryRun: true);

    $client->refresh();

    expect($client->first_name)->toBe('Wietse')
        ->and($client->bsn)->toBe('123456789')
        ->and(RetentionLogEntry::count())->toBe(0);
});

it('keeps the original record intact (does not delete) when anonymizing', function (): void {
    $client = Client::create([
        'first_name' => 'A',
        'last_name' => 'B',
        'bsn' => 'X',
        'phone' => 'Y',
        'ended_at' => now()->subYears(6),
    ]);

    (new ApplyRetention)->apply($client, RetentionConfig::for(Client::class));

    expect(Client::query()->count())->toBe(1);
});

it('does not leak the original field values into the audit log', function (): void {
    $client = Client::create([
        'first_name' => 'UniqueFirstName1234',
        'last_name' => 'UniqueLastName5678',
        'bsn' => 'unique-bsn-12345',
        'phone' => 'unique-phone-9876',
        'ended_at' => now()->subYears(6),
    ]);

    (new ApplyRetention)->apply($client, RetentionConfig::for(Client::class));

    $logRow = DB::table('retention_log')->first();

    foreach ((array) $logRow as $value) {
        if (is_string($value)) {
            expect($value)->not->toContain('UniqueFirstName1234');
            expect($value)->not->toContain('UniqueLastName5678');
            expect($value)->not->toContain('unique-bsn-12345');
            expect($value)->not->toContain('unique-phone-9876');
        }
    }
});

it('accepts a closure strategy', function (): void {
    $client = Client::create([
        'first_name' => 'Wietse',
        'last_name' => 'van Ginkel',
        'bsn' => '123456789',
        'phone' => '+31612345678',
        'ended_at' => now()->subYears(6),
    ]);

    $policy = new RetentionConfig(
        period: '5 years',
        from: 'ended_at',
        action: 'anonymize',
        anonymize: [
            'first_name' => fn (mixed $value): string => 'anon-'.substr(md5((string) $value), 0, 6),
        ],
    );

    (new ApplyRetention)->apply($client, $policy);
    $client->refresh();

    expect($client->first_name)->toStartWith('anon-')->toHaveLength(11);
});

it('rejects an unknown strategy id', function (): void {
    $client = Client::create([
        'first_name' => 'X',
        'ended_at' => now()->subYears(6),
    ]);

    $policy = new RetentionConfig(
        period: '5 years',
        from: 'ended_at',
        action: 'anonymize',
        anonymize: ['first_name' => 'flame-thrower'],
    );

    expect(fn () => (new ApplyRetention)->apply($client, $policy))
        ->toThrow(InvalidArgumentException::class, 'Unknown anonymize strategy');
});

it('produces a verifiable hash chain even with mixed actions', function (): void {
    Client::create([
        'first_name' => 'A', 'last_name' => 'B', 'bsn' => 'X', 'phone' => 'Y',
        'ended_at' => now()->subYears(6),
    ]);
    Client::create([
        'first_name' => 'C', 'last_name' => 'D', 'bsn' => 'Z', 'phone' => 'W',
        'ended_at' => now()->subYears(6),
    ]);

    $apply = new ApplyRetention;
    foreach (Client::query()->get() as $client) {
        $apply->apply($client, RetentionConfig::for(Client::class));
    }

    $entries = DB::table('retention_log')
        ->orderBy('id')
        ->get()
        ->map(fn ($row) => (array) $row)
        ->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});
