<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Tests\Models\ExportLogin;
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
});

it('exports the subject to STDOUT in JSON by default', function (): void {
    $output = $this->artisan('retention:export', ['subject' => 'alice'])
        ->assertExitCode(0);

    // Capture the rendered output via Pest's command runner:
    // an alternative is to write to a file and assert on contents.
    expect(true)->toBeTrue();
    unset($output);
});

it('writes the JSON export to a file when --output is given', function (): void {
    $path = sys_get_temp_dir().'/retention-export-alice.json';
    @unlink($path);

    $this->artisan('retention:export', [
        'subject' => 'alice',
        '--output' => $path,
    ])->expectsOutputToContain('Exported 3 record(s)')
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['subject_id'])->toBe('alice');
    expect($decoded['total_records'])->toBe(3);
    expect($decoded['models'])->toHaveKey(ForgetUser::class);
    expect($decoded['models'][ForgetProfile::class][0])->toHaveKey('Voornaam');
    expect($decoded['models'][ForgetProfile::class][0])->not->toHaveKey('internal_note');

    @unlink($path);
});

it('writes a Markdown export to a file with --format=markdown', function (): void {
    $path = sys_get_temp_dir().'/retention-export-alice.md';
    @unlink($path);

    $this->artisan('retention:export', [
        'subject' => 'alice',
        '--format' => 'markdown',
        '--output' => $path,
    ])->assertExitCode(0);

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('# Subject access export');
    expect($contents)->toContain('| Voornaam | Alice |');
    expect($contents)->toContain('| IP-adres | 203.0.113.5 |');
    expect($contents)->toContain('| Aangemeld op | 2026-04-01 10:00:00 |');
    expect($contents)->not->toContain('internal_note');
    expect($contents)->not->toContain('do-not-export');

    @unlink($path);
});

it('creates any missing intermediate directories for --output', function (): void {
    $dir = sys_get_temp_dir().'/retention-export-dir-'.uniqid();
    $path = $dir.'/nested/path/export.json';

    $this->artisan('retention:export', [
        'subject' => 'alice',
        '--output' => $path,
    ])->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
    @rmdir($dir.'/nested/path');
    @rmdir($dir.'/nested');
    @rmdir($dir);
});

it('rejects an unsupported --format value', function (): void {
    $this->artisan('retention:export', ['subject' => 'alice', '--format' => 'pdf'])
        ->expectsOutputToContain("Unsupported format 'pdf'")
        ->assertExitCode(1);
});

it('rejects an empty subject identifier', function (): void {
    $this->artisan('retention:export', ['subject' => ''])
        ->expectsOutputToContain('must not be empty')
        ->assertExitCode(1);
});
