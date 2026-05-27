<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model representing the subject themselves. When the subject is
 * forgotten, the row is hard-deleted.
 */
#[Forgettable(column: 'id', action: 'delete')]
class ForgetUser extends Model implements ForgettableContract
{
    use ForgettableTrait;

    /** @var string */
    protected $table = 'forget_users';

    /** @var list<string> */
    protected $fillable = ['id', 'email'];

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';
}
