<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Database\Seeders\ForgettableDemoSeeder;
use Ginkelsoft\DataRetention\Models\ForgetLogEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Tests\Models\ForgetOrder;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetTicket;
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
});

it('produces realistic PII via the ForgetProfile factory', function (): void {
    $profile = ForgetProfile::factory()->create();

    expect($profile->first_name)->toBeString()->not->toBeEmpty()
        ->and($profile->last_name)->toBeString()->not->toBeEmpty()
        ->and($profile->email)->toBeString()->toMatch('/@/');
});

it('links related factories to a known subject via the helper states', function (): void {
    ForgetUser::factory()->withId('demo-subject')->create();
    ForgetProfile::factory()->forSubject('demo-subject')->create();
    ForgetOrder::factory()->count(2)->forSubject('demo-subject')->create();
    ForgetTicket::factory()->reportedBy('demo-subject')->create();
    ForgetTicket::factory()->assignedTo('demo-subject')->create();

    expect(ForgetUser::find('demo-subject'))->not->toBeNull();
    expect(ForgetProfile::where('user_id', 'demo-subject')->count())->toBe(1);
    expect(ForgetOrder::where('user_id', 'demo-subject')->count())->toBe(2);
    expect(ForgetTicket::where('reporter_id', 'demo-subject')->count())->toBe(1);
    expect(ForgetTicket::where('assignee_id', 'demo-subject')->count())->toBe(1);
});

it('runs the demo seeder and forgets one subject without over-reaching', function (): void {
    config()->set('data-retention.forgettable.models', [
        ForgetUser::class,
        ForgetProfile::class,
        ForgetOrder::class,
        ForgetTicket::class,
    ]);

    (new ForgettableDemoSeeder)->run();

    // Initial state: alice, bob, carol — see the seeder's docblock for
    // the exact shape.
    expect(ForgetUser::count())->toBe(3);
    expect(ForgetProfile::count())->toBe(2);     // alice + bob, not carol
    expect(ForgetOrder::count())->toBe(7);       // 2 + 2 + 3
    expect(ForgetTicket::count())->toBe(4);      // alice (2) + bob (2)

    $this->artisan('retention:forget', ['subject' => 'alice-01'])->assertExitCode(0);

    // Alice's user row gone, her orders gone, her tickets gone, her
    // profile anonymized in place.
    expect(ForgetUser::find('alice-01'))->toBeNull();
    expect(ForgetOrder::where('user_id', 'alice-01')->count())->toBe(0);
    expect(ForgetTicket::where('reporter_id', 'alice-01')->count())->toBe(0);
    expect(ForgetTicket::where('assignee_id', 'alice-01')->count())->toBe(0);

    $aliceProfile = ForgetProfile::where('user_id', 'alice-01')->first();
    expect($aliceProfile)->not->toBeNull();
    expect($aliceProfile->first_name)->toBe('[REDACTED]');
    expect($aliceProfile->email)->toMatch('/^[a-f0-9]{64}$/');

    // Bob and Carol untouched.
    expect(ForgetUser::find('bob-02'))->not->toBeNull();
    expect(ForgetUser::find('carol-03'))->not->toBeNull();
    expect(ForgetOrder::where('user_id', 'bob-02')->count())->toBe(2);
    expect(ForgetOrder::where('user_id', 'carol-03')->count())->toBe(3);
    expect(ForgetProfile::where('user_id', 'bob-02')->first()->first_name)
        ->not->toBe('[REDACTED]');

    // Log: alice's user (1) + profile (1) + orders (2) + tickets (2) = 6 rows.
    expect(ForgetLogEntry::count())->toBe(6);

    $entries = DB::table('forget_log')->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();
    expect(HashChain::verify($entries, 'test-log-secret'))->toBeTrue();
});

it('handles a partial subject (orders only, no profile or ticket)', function (): void {
    config()->set('data-retention.forgettable.models', [
        ForgetUser::class,
        ForgetProfile::class,
        ForgetOrder::class,
        ForgetTicket::class,
    ]);

    (new ForgettableDemoSeeder)->run();

    $this->artisan('retention:forget', ['subject' => 'carol-03'])->assertExitCode(0);

    // Carol's user gone, three orders gone, no profile/ticket existed.
    expect(ForgetUser::find('carol-03'))->toBeNull();
    expect(ForgetOrder::where('user_id', 'carol-03')->count())->toBe(0);
    expect(ForgetLogEntry::count())->toBe(4); // 1 user + 3 orders
});
