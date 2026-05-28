<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Database\Seeders\BreachRegistryDemoSeeder;
use Ginkelsoft\DataRetention\Models\BreachEventLogEntry;
use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Ginkelsoft\DataRetention\Support\BreachDeadlines;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Facades\DB;

it('produces a register row with sensible defaults via the factory', function (): void {
    $breach = BreachRegisterEntry::factory()->create();

    expect($breach->reference)->toStartWith('BREACH-');
    expect($breach->status)->toBe('open');
    expect($breach->discovered_at)->not->toBeNull();
    expect($breach->data_categories)->toBeArray();
    expect(in_array($breach->severity, ['low', 'medium', 'high', 'critical'], true))->toBeTrue();
});

it('honours the overdue() factory state', function (): void {
    BreachRegisterEntry::factory()->overdue()->create();

    $overdue = (new BreachDeadlines)->overdue();
    expect($overdue)->toHaveCount(1);
});

it('honours the approaching() factory state', function (): void {
    BreachRegisterEntry::factory()->approaching()->create();

    $approaching = (new BreachDeadlines)->approaching();
    expect($approaching)->toHaveCount(1);
});

it('honours the reportedToAuthority() state and excludes from overdue', function (): void {
    BreachRegisterEntry::factory()->overdue()->reportedToAuthority()->create();

    $overdue = (new BreachDeadlines)->overdue();
    expect($overdue)->toHaveCount(0);
});

it('honours the contained() and resolved() states', function (): void {
    BreachRegisterEntry::factory()->contained()->create();
    BreachRegisterEntry::factory()->resolved()->create();

    expect(BreachRegisterEntry::query()->where('status', 'contained')->count())->toBe(1);
    expect(BreachRegisterEntry::query()->where('status', 'resolved')->count())->toBe(1);
});

it('runs the demo seeder and produces breaches in every lifecycle state', function (): void {
    (new BreachRegistryDemoSeeder)->run();

    expect(BreachRegisterEntry::count())->toBe(5);

    $deadlines = new BreachDeadlines;

    expect($deadlines->overdue()->pluck('reference')->all())->toEqual(['BREACH-DEMO-003']);
    expect($deadlines->approaching()->pluck('reference')->all())->toEqual(['BREACH-DEMO-002']);

    $resolved = BreachRegisterEntry::query()->where('status', 'resolved')->firstOrFail();
    expect($resolved->reference)->toBe('BREACH-DEMO-004');
    expect($resolved->isReportedToAuthority())->toBeTrue();
    expect($resolved->reported_to_subjects_at)->not->toBeNull();

    $contained = BreachRegisterEntry::query()->where('status', 'contained')->firstOrFail();
    expect($contained->reference)->toBe('BREACH-DEMO-005');
});

it('keeps the breach_event_log chain verifiable after the seeder', function (): void {
    (new BreachRegistryDemoSeeder)->run();

    $entries = DB::table('breach_event_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('produces a substantial event log for the fully-handled breach', function (): void {
    (new BreachRegistryDemoSeeder)->run();

    // BREACH-DEMO-004 has: registered + updated + reported_authority + reported_subjects + contained + resolved
    $events = BreachEventLogEntry::query()
        ->where('breach_reference', 'BREACH-DEMO-004')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($events)->toEqual([
        'registered',
        'updated',
        'reported_authority',
        'reported_subjects',
        'contained',
        'resolved',
    ]);
});
