<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Concerns\Forgettable;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model with the property-form policy: anonymize three fields
 * when the linked user is forgotten.
 */
class ForgetProfile extends Model implements ForgettableContract
{
    use Forgettable;

    /** @var string */
    protected $table = 'forget_profiles';

    /** @var list<string> */
    protected $fillable = ['user_id', 'first_name', 'last_name', 'email'];

    /** @var array<string, mixed> */
    protected $forgettable = [
        'column' => 'user_id',
        'action' => 'anonymize',
        'anonymize' => [
            'first_name' => 'placeholder',
            'last_name' => 'placeholder',
            'email' => 'hash',
        ],
    ];
}
