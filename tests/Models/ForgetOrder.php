<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Ginkelsoft\DataRetention\Database\Factories\ForgetOrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model with the attribute-form policy: delete records linked
 * to the user_id when the subject is forgotten.
 *
 * @method static ForgetOrderFactory factory(...$arguments)
 */
#[Forgettable(column: 'user_id', action: 'delete')]
class ForgetOrder extends Model implements ForgettableContract
{
    use ForgettableTrait;

    /** @use HasFactory<ForgetOrderFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'forget_orders';

    /** @var list<string> */
    protected $fillable = ['user_id', 'reference', 'amount_cents'];

    /**
     * @return Factory<ForgetOrder>
     */
    protected static function newFactory(): Factory
    {
        return ForgetOrderFactory::new();
    }
}
