<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\RecordConsent;
use Ginkelsoft\DataRetention\Models\ConsentEntry;
use Ginkelsoft\DataRetention\Support\ConsentStatus;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('records a granted event and reports consent as active', function (): void {
    (new RecordConsent)->grant('alice', 'newsletter');

    expect((new ConsentStatus)->isGranted('alice', 'newsletter'))->toBeTrue();
    expect(ConsentEntry::count())->toBe(1);
    expect(ConsentEntry::first()->action)->toBe('granted');
});

it('reports no consent when no event has been recorded', function (): void {
    expect((new ConsentStatus)->isGranted('alice', 'newsletter'))->toBeFalse();
    expect((new ConsentStatus)->latest('alice', 'newsletter'))->toBeNull();
});

it('reports withdrawal as not granted and keeps the prior grant in history', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter');
    $consent->withdraw('alice', 'newsletter');

    $status = new ConsentStatus;

    expect($status->isGranted('alice', 'newsletter'))->toBeFalse();
    expect($status->history('alice', 'newsletter'))->toHaveCount(2);
    expect(ConsentEntry::count())->toBe(2);
});

it('treats a fresh grant after a withdrawal as active again', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter');
    $consent->withdraw('alice', 'newsletter');
    $consent->grant('alice', 'newsletter');

    expect((new ConsentStatus)->isGranted('alice', 'newsletter'))->toBeTrue();
    expect(ConsentEntry::count())->toBe(3);
});

it('keeps consent for different purposes independent', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter');
    $consent->grant('alice', 'analytics');
    $consent->withdraw('alice', 'newsletter');

    $status = new ConsentStatus;

    expect($status->isGranted('alice', 'newsletter'))->toBeFalse();
    expect($status->isGranted('alice', 'analytics'))->toBeTrue();
});

it('scopes version: a grant on v2 does not imply consent on v1 when version-checked', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter', version: '1');
    $consent->withdraw('alice', 'newsletter', version: '1');
    $consent->grant('alice', 'newsletter', version: '2');

    $status = new ConsentStatus;

    expect($status->isGranted('alice', 'newsletter', version: '1'))->toBeFalse();
    expect($status->isGranted('alice', 'newsletter', version: '2'))->toBeTrue();
});

it('returns activeFor as a map of granted purposes only', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter');
    $consent->grant('alice', 'analytics');
    $consent->grant('alice', 'profiling');
    $consent->withdraw('alice', 'profiling');

    $active = (new ConsentStatus)->activeFor('alice');

    expect(array_keys($active))->toEqualCanonicalizing(['newsletter', 'analytics']);
});

it('respects an explicit occurredAt for backfilled events', function (): void {
    $past = Carbon::parse('2024-01-01 12:00:00', 'UTC');

    (new RecordConsent)->grant('alice', 'newsletter', occurredAt: $past);

    $entry = ConsentEntry::firstOrFail();

    expect($entry->occurred_at->equalTo($past))->toBeTrue();
});

it('persists optional source and metadata fields', function (): void {
    (new RecordConsent)->grant(
        'alice',
        'newsletter',
        source: 'web',
        metadata: ['ip' => '203.0.113.5', 'form' => 'signup-v3'],
    );

    $entry = ConsentEntry::firstOrFail();

    expect($entry->source)->toBe('web');
    expect($entry->metadata)->toBe(['ip' => '203.0.113.5', 'form' => 'signup-v3']);
});

it('rejects an empty subject identifier', function (): void {
    expect(fn () => (new RecordConsent)->grant('', 'newsletter'))
        ->toThrow(InvalidArgumentException::class, 'Subject');
});

it('rejects an empty purpose', function (): void {
    expect(fn () => (new RecordConsent)->grant('alice', ''))
        ->toThrow(InvalidArgumentException::class, 'purpose');
});

it('chains successive consent entries by previous_hash', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter');
    $consent->grant('bob', 'analytics');
    $consent->withdraw('alice', 'newsletter');

    $entries = ConsentEntry::query()->orderBy('id')->get();

    expect($entries->pluck('previous_hash')->all())->toEqual([
        '',
        $entries[0]->hash,
        $entries[1]->hash,
    ]);
});

it('produces a verifiable hash chain over consent_log', function (): void {
    $consent = new RecordConsent;
    $consent->grant('alice', 'newsletter');
    $consent->grant('bob', 'analytics', source: 'api');
    $consent->withdraw('alice', 'newsletter');

    $entries = DB::table('consent_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('detects tampering with a consent_log row', function (): void {
    (new RecordConsent)->grant('alice', 'newsletter');
    (new RecordConsent)->withdraw('alice', 'newsletter');

    DB::table('consent_log')->where('id', 1)->update(['action' => 'withdrawn']);

    $entries = DB::table('consent_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeFalse();
});

it('forbids updating an existing consent entry through the model', function (): void {
    (new RecordConsent)->grant('alice', 'newsletter');

    $entry = ConsentEntry::firstOrFail();
    $entry->action = 'withdrawn';

    expect(fn () => $entry->save())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('forbids deleting a consent entry through the model', function (): void {
    (new RecordConsent)->grant('alice', 'newsletter');

    $entry = ConsentEntry::firstOrFail();

    expect(fn () => $entry->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});
