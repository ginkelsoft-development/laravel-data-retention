<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Ginkelsoft\DataRetention\Tests\Models\Client;
use Ginkelsoft\DataRetention\Tests\Models\UnpolicyedModel;
use Illuminate\Database\Eloquent\Model;

it('resolves a delete policy from an attribute', function (): void {
    $config = RetentionConfig::for(AuditEntry::class);

    expect($config)->not->toBeNull();
    expect($config->period)->toBe('2 years');
    expect($config->from)->toBe('created_at');
    expect($config->action)->toBe('delete');
    expect($config->anonymize)->toBe([]);
});

it('resolves an anonymize policy from a $retention property', function (): void {
    $config = RetentionConfig::for(Client::class);

    expect($config)->not->toBeNull();
    expect($config->period)->toBe('5 years');
    expect($config->from)->toBe('ended_at');
    expect($config->action)->toBe('anonymize');
    expect($config->anonymize)->toBe([
        'first_name' => 'placeholder',
        'last_name' => 'placeholder',
        'bsn' => 'hash',
        'phone' => 'null',
    ]);
});

it('returns null when no policy is declared', function (): void {
    expect(RetentionConfig::for(UnpolicyedModel::class))->toBeNull();
});

it('returns null when the class does not exist', function (): void {
    expect(RetentionConfig::for('App\\Models\\DoesNotExist'))->toBeNull();
});

it('rejects an unknown action', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $retention = ['period' => '1 year', 'action' => 'burn-it-all'];
    };

    expect(fn () => RetentionConfig::for($model))
        ->toThrow(InvalidArgumentException::class, "must be 'delete' or 'anonymize'");
});

it('rejects anonymize without configured fields', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $retention = ['period' => '1 year', 'action' => 'anonymize'];
    };

    expect(fn () => RetentionConfig::for($model))
        ->toThrow(InvalidArgumentException::class, 'no fields are configured');
});
