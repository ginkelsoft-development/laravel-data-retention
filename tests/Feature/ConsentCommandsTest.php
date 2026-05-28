<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Models\ConsentEntry;

it('records a grant via the artisan command', function (): void {
    $this->artisan('retention:consent:grant', ['subject' => 'alice', 'purpose' => 'newsletter'])
        ->expectsOutputToContain('Recorded GRANTED consent')
        ->assertExitCode(0);

    expect(ConsentEntry::count())->toBe(1);
    expect(ConsentEntry::first()->action)->toBe('granted');
    expect(ConsentEntry::first()->subject_id)->toBe('alice');
});

it('records a withdrawal via the artisan command', function (): void {
    $this->artisan('retention:consent:grant', ['subject' => 'alice', 'purpose' => 'newsletter'])
        ->assertExitCode(0);
    $this->artisan('retention:consent:withdraw', ['subject' => 'alice', 'purpose' => 'newsletter'])
        ->expectsOutputToContain('Recorded WITHDRAWN consent')
        ->assertExitCode(0);

    expect(ConsentEntry::count())->toBe(2);
    expect(ConsentEntry::orderByDesc('id')->first()->action)->toBe('withdrawn');
});

it('accepts a custom version, source, and metadata via options', function (): void {
    $this->artisan('retention:consent:grant', [
        'subject' => 'alice',
        'purpose' => 'newsletter',
        '--consent-version' => '2',
        '--source' => 'web',
        '--metadata' => '{"ip":"203.0.113.5","form":"signup-v3"}',
    ])->assertExitCode(0);

    $entry = ConsentEntry::firstOrFail();

    expect($entry->version)->toBe('2');
    expect($entry->source)->toBe('web');
    expect($entry->metadata)->toBe(['ip' => '203.0.113.5', 'form' => 'signup-v3']);
});

it('rejects invalid JSON in --metadata', function (): void {
    $this->artisan('retention:consent:grant', [
        'subject' => 'alice',
        'purpose' => 'newsletter',
        '--metadata' => '{not-json',
    ])->expectsOutputToContain('Invalid JSON')
        ->assertExitCode(1);

    expect(ConsentEntry::count())->toBe(0);
});

it('rejects --metadata that decodes to a non-object', function (): void {
    $this->artisan('retention:consent:grant', [
        'subject' => 'alice',
        'purpose' => 'newsletter',
        '--metadata' => '"just-a-string"',
    ])->expectsOutputToContain('JSON object')
        ->assertExitCode(1);
});

it('shows the consent status for a subject', function (): void {
    $this->artisan('retention:consent:grant', ['subject' => 'alice', 'purpose' => 'newsletter']);
    $this->artisan('retention:consent:grant', ['subject' => 'alice', 'purpose' => 'analytics']);
    $this->artisan('retention:consent:withdraw', ['subject' => 'alice', 'purpose' => 'newsletter']);

    $this->artisan('retention:consent:status', ['subject' => 'alice'])
        ->expectsOutputToContain('Active consents:')
        ->expectsOutputToContain('- analytics')
        ->expectsOutputToContain('Full history')
        ->expectsOutputToContain('GRANTED')
        ->expectsOutputToContain('WITHDRAWN')
        ->assertExitCode(0);
});

it('reports no consent state for an unknown subject', function (): void {
    $this->artisan('retention:consent:status', ['subject' => 'nobody'])
        ->expectsOutputToContain('(none)')
        ->assertExitCode(0);
});

it('rejects an empty subject identifier on status', function (): void {
    $this->artisan('retention:consent:status', ['subject' => ''])
        ->expectsOutputToContain('must not be empty')
        ->assertExitCode(1);
});
