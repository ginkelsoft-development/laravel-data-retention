<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Concerns\HasRetention;
use Ginkelsoft\DataRetention\Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model demonstrating the property form: an anonymize policy
 * with per-field strategies including a callable.
 *
 * @method static ClientFactory factory(...$arguments)
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasRetention;

    /** @var string */
    protected $table = 'clients';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'bsn', 'phone', 'ended_at'];

    /** @var array<string, string> */
    protected $casts = [
        'ended_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $retention = [
        'period' => '5 years',
        'from' => 'ended_at',
        'action' => 'anonymize',
        'anonymize' => [
            'first_name' => 'placeholder',
            'last_name' => 'placeholder',
            'bsn' => 'hash',
            'phone' => 'null',
        ],
    ];

    /**
     * Resolve the package-provided factory for this test model.
     *
     * @return Factory<Client>
     */
    protected static function newFactory(): Factory
    {
        return ClientFactory::new();
    }
}
