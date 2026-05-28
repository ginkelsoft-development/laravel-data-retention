<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Support\ForgettableConfig;
use Ginkelsoft\DataRetention\Tests\Models\ForgetOrder;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
use Ginkelsoft\DataRetention\Tests\Models\UnpolicyedModel;
use Illuminate\Database\Eloquent\Model;

it('resolves a delete policy from the attribute', function (): void {
    $config = ForgettableConfig::for(ForgetUser::class);

    expect($config)->not->toBeNull();
    expect($config->column)->toBe('id');
    expect($config->action)->toBe('delete');
    expect($config->anonymize)->toBe([]);
});

it('resolves a delete policy with custom column from the attribute', function (): void {
    $config = ForgettableConfig::for(ForgetOrder::class);

    expect($config)->not->toBeNull();
    expect($config->column)->toBe('user_id');
    expect($config->action)->toBe('delete');
});

it('resolves an anonymize policy from the property', function (): void {
    $config = ForgettableConfig::for(ForgetProfile::class);

    expect($config)->not->toBeNull();
    expect($config->column)->toBe('user_id');
    expect($config->action)->toBe('anonymize');
    expect($config->anonymize)->toBe([
        'first_name' => 'placeholder',
        'last_name' => 'placeholder',
        'email' => 'hash',
    ]);
});

it('returns null when no policy is declared', function (): void {
    expect(ForgettableConfig::for(UnpolicyedModel::class))->toBeNull();
});

it('returns null when the class does not exist', function (): void {
    expect(ForgettableConfig::for('App\\Models\\DoesNotExist'))->toBeNull();
});

it('rejects an unknown action', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $forgettable = ['column' => 'user_id', 'action' => 'shred'];
    };

    expect(fn () => ForgettableConfig::for($model))
        ->toThrow(InvalidArgumentException::class, "must be 'delete' or 'anonymize'");
});

it('rejects anonymize without configured fields', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $forgettable = ['column' => 'user_id', 'action' => 'anonymize'];
    };

    expect(fn () => ForgettableConfig::for($model))
        ->toThrow(InvalidArgumentException::class, 'no fields are configured');
});
