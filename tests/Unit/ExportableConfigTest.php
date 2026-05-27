<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Support\ExportableConfig;
use Ginkelsoft\DataRetention\Tests\Models\ExportLogin;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
use Ginkelsoft\DataRetention\Tests\Models\UnpolicyedModel;
use Illuminate\Database\Eloquent\Model;

it('resolves a policy from the attribute + property combination', function (): void {
    $config = ExportableConfig::for(ForgetUser::class);

    expect($config)->not->toBeNull();
    expect($config->column)->toBe('id');
    expect($config->fields)->toHaveKeys(['id', 'email']);
    expect($config->fields['email']['label'])->toBe('E-mailadres');
    expect($config->fields['email']['transform'])->toBeNull();
});

it('resolves a policy from the property alone (column inside the property)', function (): void {
    $config = ExportableConfig::for(ForgetProfile::class);

    expect($config)->not->toBeNull();
    expect($config->column)->toBe('user_id');
    expect(array_keys($config->fields))->toEqual(['first_name', 'last_name', 'email']);
    expect($config->fields['first_name']['label'])->toBe('Voornaam');
});

it('omits fields that are not declared (internal_note is missing)', function (): void {
    $config = ExportableConfig::for(ForgetProfile::class);

    expect($config)->not->toBeNull();
    expect($config->fields)->not->toHaveKey('internal_note');
});

it('normalizes string-form fields to label + null transform', function (): void {
    $config = ExportableConfig::for(ForgetUser::class);

    expect($config)->not->toBeNull();
    expect($config->fields['id'])->toBe(['label' => 'Subject identifier', 'transform' => null]);
});

it('keeps a configured transform as a Closure on the policy', function (): void {
    $config = ExportableConfig::for(ExportLogin::class);

    expect($config)->not->toBeNull();
    expect($config->fields['logged_in_at']['transform'])->toBeInstanceOf(Closure::class);
});

it('returns null when no policy is declared', function (): void {
    expect(ExportableConfig::for(UnpolicyedModel::class))->toBeNull();
});

it('returns null when the class does not exist', function (): void {
    expect(ExportableConfig::for('App\\Models\\DoesNotExist'))->toBeNull();
});

it('rejects an Exportable policy without any fields', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $exportable = ['column' => 'user_id'];
    };

    expect(fn () => ExportableConfig::for($model))
        ->toThrow(InvalidArgumentException::class, 'declares no fields');
});

it('rejects a field whose spec is neither a string nor an array', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $exportable = ['column' => 'user_id', 'fields' => ['email' => 42]];
    };

    expect(fn () => ExportableConfig::for($model))
        ->toThrow(InvalidArgumentException::class, 'label string or');
});

it('rejects a field whose label is not a string', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $exportable = ['column' => 'user_id', 'fields' => ['email' => ['label' => 42]]];
    };

    expect(fn () => ExportableConfig::for($model))
        ->toThrow(InvalidArgumentException::class, 'non-string label');
});

it('rejects a transform that is neither a Closure nor a callable array', function (): void {
    $model = new class extends Model
    {
        protected $table = 'x';

        protected $exportable = ['column' => 'user_id', 'fields' => ['email' => ['label' => 'E', 'transform' => 'not-callable-string']]];
    };

    expect(fn () => ExportableConfig::for($model))
        ->toThrow(InvalidArgumentException::class, 'transform');
});
