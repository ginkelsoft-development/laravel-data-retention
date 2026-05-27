<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Models\ForgetLogEntry;
use Ginkelsoft\DataRetention\Tests\Models\ForgetOrder;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
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

    config()->set('data-retention.forgettable.models', [
        ForgetUser::class,
        ForgetProfile::class,
        ForgetOrder::class,
    ]);

    ForgetUser::create(['id' => 'subject-1', 'email' => 'a@example.com']);
    ForgetProfile::create(['user_id' => 'subject-1', 'first_name' => 'A', 'last_name' => 'A', 'email' => 'a@example.com']);
    ForgetOrder::create(['user_id' => 'subject-1', 'reference' => 'O1', 'amount_cents' => 100]);
});

it('forgets a subject end-to-end through the artisan command', function (): void {
    $this->artisan('retention:forget', ['subject' => 'subject-1'])
        ->assertExitCode(0);

    expect(ForgetUser::find('subject-1'))->toBeNull();
    expect(ForgetOrder::where('user_id', 'subject-1')->count())->toBe(0);
    expect(ForgetProfile::where('user_id', 'subject-1')->first()->first_name)->toBe('[REDACTED]');
    expect(ForgetLogEntry::count())->toBe(3);
});

it('changes nothing in --dry-run mode', function (): void {
    $this->artisan('retention:forget', ['subject' => 'subject-1', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertExitCode(0);

    expect(ForgetUser::find('subject-1'))->not->toBeNull();
    expect(ForgetLogEntry::count())->toBe(0);
});

it('reports cleanly when the subject has no records anywhere', function (): void {
    $this->artisan('retention:forget', ['subject' => 'unknown-subject'])
        ->expectsOutputToContain('No records found')
        ->assertExitCode(0);
});

it('the second invocation is idempotent', function (): void {
    $this->artisan('retention:forget', ['subject' => 'subject-1'])->assertExitCode(0);
    $logCount = ForgetLogEntry::count();

    $this->artisan('retention:forget', ['subject' => 'subject-1'])->assertExitCode(0);

    expect(ForgetLogEntry::count())->toBe($logCount);
});
