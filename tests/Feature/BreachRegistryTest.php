<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\BreachRegistry;
use Ginkelsoft\DataRetention\Models\BreachEventLogEntry;
use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Ginkelsoft\DataRetention\Support\BreachDeadlines;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('registers a new breach and writes a registered event', function (): void {
    $registry = new BreachRegistry;

    $breach = $registry->register(
        reference: 'BREACH-2026-001',
        discoveredAt: Carbon::parse('2026-05-27 09:15', 'UTC'),
        description: 'Misdirected export of 42 client emails',
        severity: 'high',
        dataCategories: ['email', 'name'],
        subjectsAffected: 42,
        actor: 'ops@example.com',
    );

    expect($breach->reference)->toBe('BREACH-2026-001');
    expect($breach->status)->toBe('open');
    expect($breach->severity)->toBe('high');
    expect($breach->subjects_affected)->toBe(42);
    expect($breach->data_categories)->toBe(['email', 'name']);

    $event = BreachEventLogEntry::firstOrFail();
    expect($event->action)->toBe('registered');
    expect($event->breach_reference)->toBe('BREACH-2026-001');
    expect($event->actor)->toBe('ops@example.com');
});

it('rejects an unknown severity', function (): void {
    $registry = new BreachRegistry;

    expect(fn () => $registry->register(
        reference: 'BREACH-A',
        discoveredAt: Carbon::now(),
        description: 'test',
        severity: 'apocalyptic',
    ))->toThrow(InvalidArgumentException::class, 'Severity');
});

it('updates a breach and records the diff', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-A',
        discoveredAt: Carbon::parse('2026-05-27 09:15', 'UTC'),
        description: 'Initial',
        severity: 'medium',
    );

    $registry->update('BREACH-A', [
        'severity' => 'high',
        'mitigation' => 'Revoked tokens and notified support',
        'subjects_affected' => 50,
    ], actor: 'ops@example.com');

    $breach = BreachRegisterEntry::where('reference', 'BREACH-A')->firstOrFail();
    expect($breach->severity)->toBe('high');
    expect($breach->subjects_affected)->toBe(50);
    expect($breach->mitigation)->toContain('Revoked tokens');

    $update = BreachEventLogEntry::where('action', 'updated')->firstOrFail();
    expect($update->changes)->toHaveKey('severity');
    expect($update->changes['severity'])->toBe(['from' => 'medium', 'to' => 'high']);
    expect($update->changes)->toHaveKey('subjects_affected');
    expect($update->changes)->toHaveKey('mitigation');
});

it('treats an update with identical values as a no-op', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-A',
        discoveredAt: Carbon::now(),
        description: 'Initial',
        severity: 'medium',
    );

    $eventsBefore = BreachEventLogEntry::count();

    $registry->update('BREACH-A', ['severity' => 'medium']);

    expect(BreachEventLogEntry::count())->toBe($eventsBefore);
});

it('rejects updates on unknown columns', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-A',
        discoveredAt: Carbon::now(),
        description: 'x',
    );

    expect(fn () => $registry->update('BREACH-A', ['not_a_column' => 'value']))
        ->toThrow(InvalidArgumentException::class, 'writable column');
});

it('records reporting to the authority and to subjects', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-A',
        discoveredAt: Carbon::now(),
        description: 'x',
    );

    $registry->reportToAuthority('BREACH-A', notificationReference: 'AP-2026-9999');
    $registry->reportToSubjects('BREACH-A', channel: 'email');

    $breach = BreachRegisterEntry::where('reference', 'BREACH-A')->firstOrFail();
    expect($breach->isReportedToAuthority())->toBeTrue();
    expect($breach->reported_to_subjects_at)->not->toBeNull();

    $events = BreachEventLogEntry::where('breach_reference', 'BREACH-A')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($events)->toContain('reported_authority');
    expect($events)->toContain('reported_subjects');

    $authorityEvent = BreachEventLogEntry::where('action', 'reported_authority')->firstOrFail();
    expect($authorityEvent->changes['notification_reference'])->toBe('AP-2026-9999');
});

it('transitions status through contain and resolve', function (): void {
    $registry = new BreachRegistry;
    $registry->register(reference: 'BREACH-A', discoveredAt: Carbon::now(), description: 'x');

    $registry->contain('BREACH-A');
    expect(BreachRegisterEntry::where('reference', 'BREACH-A')->firstOrFail()->status)->toBe('contained');

    $registry->resolve('BREACH-A');
    expect(BreachRegisterEntry::where('reference', 'BREACH-A')->firstOrFail()->status)->toBe('resolved');

    $events = BreachEventLogEntry::where('breach_reference', 'BREACH-A')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($events)->toEqual(['registered', 'contained', 'resolved']);
});

it('produces a verifiable hash chain over the event log', function (): void {
    $registry = new BreachRegistry;
    $registry->register(reference: 'BREACH-A', discoveredAt: Carbon::now(), description: 'x');
    $registry->update('BREACH-A', ['severity' => 'high']);
    $registry->reportToAuthority('BREACH-A');
    $registry->resolve('BREACH-A');

    $entries = DB::table('breach_event_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('detects tampering on the event log', function (): void {
    $registry = new BreachRegistry;
    $registry->register(reference: 'BREACH-A', discoveredAt: Carbon::now(), description: 'x');
    $registry->update('BREACH-A', ['severity' => 'high']);

    DB::table('breach_event_log')->where('id', 1)->update(['action' => 'updated']);

    $entries = DB::table('breach_event_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeFalse();
});

it('forbids updating an event log entry through the model', function (): void {
    $registry = new BreachRegistry;
    $registry->register(reference: 'BREACH-A', discoveredAt: Carbon::now(), description: 'x');

    $event = BreachEventLogEntry::firstOrFail();
    $event->action = 'updated';

    expect(fn () => $event->save())->toThrow(RuntimeException::class, 'append-only');
});

it('flags overdue breaches via BreachDeadlines', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-OLD',
        discoveredAt: Carbon::now()->subHours(100),
        description: 'old',
    );
    $registry->register(
        reference: 'BREACH-FRESH',
        discoveredAt: Carbon::now()->subHour(),
        description: 'fresh',
    );

    $overdue = (new BreachDeadlines)->overdue();

    expect($overdue->pluck('reference')->all())->toEqual(['BREACH-OLD']);
});

it('flags approaching breaches within the warning window', function (): void {
    $registry = new BreachRegistry;
    // Discovered 60 hours ago: deadline is 72-60 = 12 hours from now,
    // which is within the default 24-hour warning window.
    $registry->register(
        reference: 'BREACH-WARN',
        discoveredAt: Carbon::now()->subHours(60),
        description: 'within warning window',
    );
    // Discovered 10 hours ago: deadline is 62 hours away, well outside
    // the warning window.
    $registry->register(
        reference: 'BREACH-CALM',
        discoveredAt: Carbon::now()->subHours(10),
        description: 'still calm',
    );

    $approaching = (new BreachDeadlines)->approaching();

    expect($approaching->pluck('reference')->all())->toEqual(['BREACH-WARN']);
});

it('does not flag breaches that have already been reported to the authority', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-DONE',
        discoveredAt: Carbon::now()->subHours(100),
        description: 'already reported',
    );
    $registry->reportToAuthority('BREACH-DONE');

    $overdue = (new BreachDeadlines)->overdue();
    expect($overdue)->toHaveCount(0);
});

it('exposes the authority notification deadline on the model', function (): void {
    $registry = new BreachRegistry;
    $discoveredAt = Carbon::parse('2026-05-27 09:00', 'UTC');
    $registry->register(
        reference: 'BREACH-A',
        discoveredAt: $discoveredAt,
        description: 'x',
    );

    $breach = BreachRegisterEntry::where('reference', 'BREACH-A')->firstOrFail();
    expect($breach->authorityNotificationDeadline()->equalTo($discoveredAt->copy()->addHours(72)))->toBeTrue();
});

it('does not leak personal data into the event log', function (): void {
    $registry = new BreachRegistry;
    $registry->register(
        reference: 'BREACH-A',
        discoveredAt: Carbon::now(),
        description: 'A misdirected export concerning UniqueSubject9999',
        cause: 'Operator mistake by SomeNamedEmployee1234',
    );

    foreach (DB::table('breach_event_log')->get() as $row) {
        foreach ((array) $row as $value) {
            if (is_string($value)) {
                expect($value)->not->toContain('UniqueSubject9999');
                expect($value)->not->toContain('SomeNamedEmployee1234');
            }
        }
    }
});

it('throws when targeting an unknown breach reference', function (): void {
    $registry = new BreachRegistry;

    expect(fn () => $registry->update('NOT-A-REAL-REF', ['severity' => 'high']))
        ->toThrow(InvalidArgumentException::class, 'No breach found');
});
