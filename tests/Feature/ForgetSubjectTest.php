<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Actions\ForgetSubject;
use Ginkelsoft\DataRetention\Models\ForgetLogEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Support\SubjectHash;
use Ginkelsoft\DataRetention\Tests\Models\ForgetOrder;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetTicket;
use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
use Ginkelsoft\DataRetention\Tests\Models\UnpolicyedModel;
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
        $table->timestamps();
    });

    Schema::create('forget_orders', function ($table): void {
        $table->id();
        $table->string('user_id', 64)->index();
        $table->string('reference');
        $table->integer('amount_cents');
        $table->timestamps();
    });

    Schema::create('forget_tickets', function ($table): void {
        $table->id();
        $table->string('reporter_id', 64)->index();
        $table->string('assignee_id', 64)->nullable()->index();
        $table->string('subject');
        $table->timestamps();
    });

    config()->set('data-retention.forgettable.models', [
        ForgetUser::class,
        ForgetProfile::class,
        ForgetOrder::class,
        ForgetTicket::class,
    ]);
});

function seedSubject(string $id): void
{
    ForgetUser::create(['id' => $id, 'email' => $id.'@example.com']);

    ForgetProfile::create([
        'user_id' => $id,
        'first_name' => 'Wietse',
        'last_name' => 'van Ginkel',
        'email' => $id.'@example.com',
    ]);

    ForgetOrder::create(['user_id' => $id, 'reference' => 'ORD-1', 'amount_cents' => 4250]);
    ForgetOrder::create(['user_id' => $id, 'reference' => 'ORD-2', 'amount_cents' => 1199]);

    ForgetTicket::create(['reporter_id' => $id, 'subject' => 'Reported']);
    ForgetTicket::create(['reporter_id' => 'someone-else', 'assignee_id' => $id, 'subject' => 'Assigned']);
}

it('forgets a subject across every registered model', function (): void {
    seedSubject('01HXYZ-A');

    // Untouched subject to prove we do not over-reach.
    ForgetUser::create(['id' => '01HXYZ-B', 'email' => 'B@example.com']);
    ForgetProfile::create([
        'user_id' => '01HXYZ-B',
        'first_name' => 'Other',
        'last_name' => 'Person',
        'email' => 'B@example.com',
    ]);

    $affected = (new ForgetSubject)->forget('01HXYZ-A');

    expect(ForgetUser::find('01HXYZ-A'))->toBeNull();
    expect(ForgetUser::find('01HXYZ-B'))->not->toBeNull();

    expect(ForgetOrder::where('user_id', '01HXYZ-A')->count())->toBe(0);

    $profile = ForgetProfile::where('user_id', '01HXYZ-A')->first();
    expect($profile)->not->toBeNull();
    expect($profile->first_name)->toBe('[REDACTED]');
    expect($profile->email)->toMatch('/^[a-f0-9]{64}$/');

    // Untouched profile of subject B intact.
    expect(ForgetProfile::where('user_id', '01HXYZ-B')->first()->first_name)->toBe('Other');

    expect(array_keys($affected))->toEqualCanonicalizing([
        ForgetUser::class,
        ForgetProfile::class,
        ForgetOrder::class,
        ForgetTicket::class,
    ]);
    expect($affected[ForgetOrder::class])->toHaveCount(2);
    expect($affected[ForgetTicket::class])->toHaveCount(2); // both reporter and assignee
});

it('writes one forget_log row per affected record, with the subject hash', function (): void {
    seedSubject('01HXYZ-A');

    (new ForgetSubject)->forget('01HXYZ-A');

    $expectedHash = SubjectHash::compute('01HXYZ-A', 'test-log-secret');

    expect(ForgetLogEntry::count())->toBe(6); // 1 user + 1 profile + 2 orders + 2 tickets
    expect(ForgetLogEntry::distinct('subject_hash')->pluck('subject_hash')->all())->toEqual([$expectedHash]);
});

it('produces a verifiable forget_log hash chain', function (): void {
    seedSubject('01HXYZ-A');

    (new ForgetSubject)->forget('01HXYZ-A');

    $entries = DB::table('forget_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('detects tampering with a forget_log row', function (): void {
    seedSubject('01HXYZ-A');

    (new ForgetSubject)->forget('01HXYZ-A');

    DB::table('forget_log')->where('id', 1)->update(['action' => 'forgotten_anonymized']);

    $entries = DB::table('forget_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();

    expect(HashChain::verify($entries, 'test-log-secret'))->toBeFalse();
});

it('changes nothing in dry-run mode', function (): void {
    seedSubject('01HXYZ-A');

    $affected = (new ForgetSubject)->forget('01HXYZ-A', dryRun: true);

    expect(ForgetUser::find('01HXYZ-A'))->not->toBeNull();
    expect(ForgetOrder::where('user_id', '01HXYZ-A')->count())->toBe(2);
    expect(ForgetLogEntry::count())->toBe(0);

    // But the report still lists which records would have been touched.
    expect($affected[ForgetOrder::class])->toHaveCount(2);
});

it('is idempotent: a second forget for the same subject writes no extra log', function (): void {
    seedSubject('01HXYZ-A');

    $forget = new ForgetSubject;
    $forget->forget('01HXYZ-A');
    $logsAfterFirst = ForgetLogEntry::count();

    $forget->forget('01HXYZ-A');
    expect(ForgetLogEntry::count())->toBe($logsAfterFirst);
});

it('does not leak personal data into the forget_log', function (): void {
    ForgetProfile::create([
        'user_id' => 'subject-X',
        'first_name' => 'UniqueFirstName1234',
        'last_name' => 'UniqueLastName5678',
        'email' => 'unique-email-9876@example.com',
    ]);

    config()->set('data-retention.forgettable.models', [ForgetProfile::class]);

    (new ForgetSubject)->forget('subject-X');

    foreach (DB::table('forget_log')->get() as $row) {
        foreach ((array) $row as $value) {
            if (is_string($value)) {
                expect($value)->not->toContain('UniqueFirstName1234')
                    ->and($value)->not->toContain('UniqueLastName5678')
                    ->and($value)->not->toContain('unique-email-9876')
                    ->and($value)->not->toContain('subject-X');
            }
        }
    }
});

it('honours a custom scopeForSubject override (subject as reporter OR assignee)', function (): void {
    ForgetTicket::create(['reporter_id' => 'subject', 'subject' => 'reported']);
    ForgetTicket::create(['reporter_id' => 'other', 'assignee_id' => 'subject', 'subject' => 'assigned']);
    ForgetTicket::create(['reporter_id' => 'other', 'assignee_id' => 'other', 'subject' => 'irrelevant']);

    config()->set('data-retention.forgettable.models', [ForgetTicket::class]);

    $affected = (new ForgetSubject)->forget('subject');

    expect($affected[ForgetTicket::class])->toHaveCount(2);
    expect(ForgetTicket::count())->toBe(1);
    expect(ForgetTicket::first()->subject)->toBe('irrelevant');
});

it('skips models in the registry that have no forgettable policy', function (): void {
    config()->set('data-retention.forgettable.models', [
        UnpolicyedModel::class,
    ]);

    $affected = (new ForgetSubject)->forget('whoever');

    expect($affected)->toBe([]);
    expect(ForgetLogEntry::count())->toBe(0);
});

it('rejects an empty subject identifier', function (): void {
    expect(fn () => (new ForgetSubject)->forget(''))
        ->toThrow(InvalidArgumentException::class, 'must not be empty');
});
