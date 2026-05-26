<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Concerns\HasRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Variant of Client with SoftDeletes to exercise the soft-delete
 * inclusion behavior of the retention resolver.
 */
class SoftDeletedClient extends Model
{
    use HasRetention;
    use SoftDeletes;

    /** @var string */
    protected $table = 'soft_deleted_clients';

    /** @var list<string> */
    protected $fillable = ['name', 'ended_at'];

    /** @var array<string, string> */
    protected $casts = [
        'ended_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $retention = [
        'period' => '1 year',
        'from' => 'ended_at',
        'action' => 'anonymize',
        'anonymize' => [
            'name' => 'placeholder',
        ],
    ];
}
