<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\CollectSubjectData;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Support\SubjectHash;
use Ginkelsoft\DataRetention\Tests\Models\ExportLogin;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('forget_users', function ($table): void {
        $table->string('id', 64)->primary();
        $table->string('email')->nullable();
        $table->timestamps();
    });
    Schema::create('forget_profiles', function ($table): void {
        $table->id();
        $table->string('user_id', 64)->index();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email', 128)->nullable();
        $table->string('internal_note')->nullable();
        $table->timestamps();
    });
    Schema::create('export_logins', function ($table): void {
        $table->id();
        $table->string('user_id', 64)->index();
        $table->string('ip_address');
        $table->timestamp('logged_in_at')->nullable();
        $table->timestamps();
    });

    config()->set('data-retention.exportable.models', [
        ForgetUser::class,
        ForgetProfile::class,
        ExportLogin::class,
    ]);
});

it('collects every declared field across every registered model', function (): void {
    ForgetUser::create(['id' => 'alice', 'email' => 'alice@example.com']);
    ForgetProfile::create([
        'user_id' => 'alice',
        'first_name' => 'Alice',
        'last_name' => 'Anderson',
        'email' => 'alice@example.com',
        'internal_note' => 'do-not-export',
    ]);
    ExportLogin::create([
        'user_id' => 'alice',
        'ip_address' => '203.0.113.5',
        'logged_in_at' => '2026-04-01 10:00:00',
    ]);

    $dataset = (new CollectSubjectData)->collect('alice');

    expect($dataset->totalRecords)->toBe(3);
    expect($dataset->modelClasses())->toEqualCanonicalizing([
        ForgetUser::class, ForgetProfile::class, ExportLogin::class,
    ]);

    expect($dataset->perModel[ForgetUser::class][0])->toBe([
        'Subject identifier' => 'alice',
        'E-mailadres' => 'alice@example.com',
    ]);

    expect($dataset->perModel[ForgetProfile::class][0])->toBe([
        'Voornaam' => 'Alice',
        'Achternaam' => 'Anderson',
        'E-mailadres' => 'alice@example.com',
    ]);
});

it('omits fields that are not declared as exportable', function (): void {
    ForgetProfile::create([
        'user_id' => 'alice',
        'first_name' => 'Alice',
        'last_name' => 'Anderson',
        'email' => 'alice@example.com',
        'internal_note' => 'do-not-export',
    ]);

    $dataset = (new CollectSubjectData)->collect('alice');

    $row = $dataset->perModel[ForgetProfile::class][0];

    expect($row)->not->toHaveKey('internal_note');
    foreach ($row as $value) {
        if (is_string($value)) {
            expect($value)->not->toBe('do-not-export');
        }
    }
});

it('applies the configured field transform', function (): void {
    ExportLogin::create([
        'user_id' => 'alice',
        'ip_address' => '203.0.113.5',
        'logged_in_at' => '2026-04-01 10:00:00',
    ]);

    $dataset = (new CollectSubjectData)->collect('alice');

    expect($dataset->perModel[ExportLogin::class][0]['Aangemeld op'])
        ->toBe('2026-04-01 10:00:00');
});

it('does not reach across subjects', function (): void {
    ForgetUser::create(['id' => 'alice', 'email' => 'alice@example.com']);
    ForgetUser::create(['id' => 'bob', 'email' => 'bob@example.com']);
    ForgetProfile::create(['user_id' => 'bob', 'first_name' => 'Bob', 'last_name' => 'B', 'email' => 'bob@example.com']);

    $dataset = (new CollectSubjectData)->collect('alice');

    expect($dataset->totalRecords)->toBe(1);
    expect($dataset->perModel)->toHaveKey(ForgetUser::class);
    expect($dataset->perModel)->not->toHaveKey(ForgetProfile::class);
});

it('does not mutate the records that were exported (read-only)', function (): void {
    ForgetProfile::create([
        'user_id' => 'alice',
        'first_name' => 'Alice',
        'last_name' => 'Anderson',
        'email' => 'alice@example.com',
        'internal_note' => 'kept',
    ]);

    (new CollectSubjectData)->collect('alice');

    $profile = ForgetProfile::firstOrFail();

    expect($profile->first_name)->toBe('Alice');
    expect($profile->internal_note)->toBe('kept');
});

it('writes one retention_log row per matched model, tagged subject_access', function (): void {
    ForgetUser::create(['id' => 'alice', 'email' => 'alice@example.com']);
    ForgetProfile::create(['user_id' => 'alice', 'first_name' => 'Alice', 'last_name' => 'A', 'email' => 'alice@example.com']);

    (new CollectSubjectData)->collect('alice');

    $rows = RetentionLogEntry::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2);

    $expectedSubjectHash = SubjectHash::compute('alice', 'test-log-secret');

    foreach ($rows as $row) {
        expect($row->action)->toBe('subject_access_exported');
        expect($row->retention_field)->toBe('subject_access');
        expect($row->model_id)->toBe($expectedSubjectHash);
        expect($row->retention_period)->toBe('1 records');
    }
});

it('keeps the retention_log hash chain verifiable after a subject access', function (): void {
    ForgetUser::create(['id' => 'alice', 'email' => 'alice@example.com']);
    ForgetProfile::create(['user_id' => 'alice', 'first_name' => 'Alice', 'last_name' => 'A', 'email' => 'alice@example.com']);

    (new CollectSubjectData)->collect('alice');

    $entries = DB::table('retention_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('does not write any retention_log row when nothing is found', function (): void {
    $dataset = (new CollectSubjectData)->collect('nobody');

    expect($dataset->totalRecords)->toBe(0);
    expect(RetentionLogEntry::count())->toBe(0);
});

it('does not leak the subject identifier or field values into the log', function (): void {
    ForgetProfile::create([
        'user_id' => 'subject-XYZ',
        'first_name' => 'UniqueFirstName1234',
        'last_name' => 'UniqueLastName5678',
        'email' => 'unique-email-9876@example.com',
        'internal_note' => 'should-not-appear',
    ]);

    (new CollectSubjectData)->collect('subject-XYZ');

    foreach (DB::table('retention_log')->get() as $row) {
        foreach ((array) $row as $value) {
            if (is_string($value)) {
                expect($value)->not->toContain('subject-XYZ')
                    ->and($value)->not->toContain('UniqueFirstName1234')
                    ->and($value)->not->toContain('UniqueLastName5678')
                    ->and($value)->not->toContain('unique-email-9876')
                    ->and($value)->not->toContain('should-not-appear');
            }
        }
    }
});

it('can skip access logging when explicitly told to', function (): void {
    ForgetUser::create(['id' => 'alice', 'email' => 'alice@example.com']);

    (new CollectSubjectData)->collect('alice', logAccess: false);

    expect(RetentionLogEntry::count())->toBe(0);
});

it('rejects an empty subject identifier', function (): void {
    expect(fn () => (new CollectSubjectData)->collect(''))
        ->toThrow(InvalidArgumentException::class, 'must not be empty');
});
