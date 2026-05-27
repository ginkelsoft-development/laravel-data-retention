<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model with the attribute-form policy: delete records linked
 * to the user_id when the subject is forgotten.
 */
#[Forgettable(column: 'user_id', action: 'delete')]
class ForgetOrder extends Model implements ForgettableContract
{
    use ForgettableTrait;

    /** @var string */
    protected $table = 'forget_orders';

    /** @var list<string> */
    protected $fillable = ['user_id', 'reference', 'amount_cents'];
}
