<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Database\Seeders\ConsentDemoSeeder;
use Ginkelsoft\DataRetention\Models\ConsentEntry;
use Ginkelsoft\DataRetention\Support\ConsentStatus;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('produces a valid hash-chained consent_log via the factory', function (): void {
    ConsentEntry::factory()->createOneAtATime(5);

    $entries = DB::table('consent_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('honours the granted() and withdrawn() factory states', function (): void {
    ConsentEntry::factory()->granted()->forSubject('alice')->forPurpose('newsletter')->create();
    ConsentEntry::factory()->withdrawn()->forSubject('alice')->forPurpose('newsletter')->create();

    $actions = ConsentEntry::query()->orderBy('id')->pluck('action')->all();
    expect($actions)->toEqual(['granted', 'withdrawn']);
});

it('honours forSubject, forPurpose, version, via and at states', function (): void {
    $past = Carbon::parse('2025-06-15 12:00:00', 'UTC');

    ConsentEntry::factory()
        ->forSubject('bob')
        ->forPurpose('analytics')
        ->version('v2')
        ->via('api')
        ->at($past)
        ->create();

    $entry = ConsentEntry::firstOrFail();

    expect($entry->subject_id)->toBe('bob');
    expect($entry->purpose)->toBe('analytics');
    expect($entry->version)->toBe('v2');
    expect($entry->source)->toBe('api');
    expect($entry->occurred_at->equalTo($past))->toBeTrue();
});

it('runs the demo seeder and ConsentStatus reports the expected active consents', function (): void {
    (new ConsentDemoSeeder)->run();

    $status = new ConsentStatus;

    expect($status->isGranted('alice-01', 'newsletter'))->toBeTrue();
    expect($status->isGranted('bob-02', 'newsletter'))->toBeTrue();
    expect($status->isGranted('bob-02', 'analytics'))->toBeTrue();
    expect($status->isGranted('carol-03', 'newsletter'))->toBeFalse(); // withdrew
    expect($status->isGranted('dan-04', 'profiling', version: '1'))->toBeTrue();
    expect($status->isGranted('dan-04', 'profiling', version: '2'))->toBeTrue();
    expect($status->isGranted('eve-05', 'marketing'))->toBeTrue(); // last event is re-grant

    expect(ConsentEntry::count())->toBe(10);
});

it('keeps the consent_log chain verifiable after the seeder', function (): void {
    (new ConsentDemoSeeder)->run();

    $entries = DB::table('consent_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});
