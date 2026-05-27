<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Exportable;
use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Exportable as ExportableTrait;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Exportable as ExportableContract;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Ginkelsoft\DataRetention\Database\Factories\ForgetUserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model representing the subject themselves. Demonstrates that
 * one model can carry multiple AVG policies: it is both Forgettable
 * (deleted on a forget request) and Exportable (included in a subject
 * access export, with an explicit field list and labels).
 *
 * Because both traits define a `forSubjectQuery`, PHP requires
 * explicit conflict resolution. The two implementations are
 * functionally equivalent when both policies use the same subject
 * column (the common case); here we pick Forgettable's version. If a
 * model carries both policies with DIFFERENT columns, override
 * `forSubjectQuery` on the model itself.
 *
 * @method static ForgetUserFactory factory(...$arguments)
 */
#[Exportable(column: 'id')]
#[Forgettable(column: 'id', action: 'delete')]
class ForgetUser extends Model implements ExportableContract, ForgettableContract
{
    use ExportableTrait, ForgettableTrait {
        ForgettableTrait::forSubjectQuery insteadof ExportableTrait;
    }

    /** @use HasFactory<ForgetUserFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'forget_users';

    /** @var list<string> */
    protected $fillable = ['id', 'email'];

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var array<string, mixed> */
    protected $exportable = [
        'fields' => [
            'id' => 'Subject identifier',
            'email' => 'E-mailadres',
        ],
    ];

    /**
     * @return Factory<ForgetUser>
     */
    protected static function newFactory(): Factory
    {
        return ForgetUserFactory::new();
    }
}
