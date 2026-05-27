<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Exportable;
use Ginkelsoft\DataRetention\Concerns\Exportable as ExportableTrait;
use Ginkelsoft\DataRetention\Contracts\Exportable as ExportableContract;
use Ginkelsoft\DataRetention\Database\Factories\ExportLoginFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model that exercises the `transform` callable on an Exportable
 * field — `logged_in_at` is a Carbon column rendered as a
 * deterministic UTC string.
 *
 * Exists only for the subject-access tests; it deliberately carries
 * no Forgettable policy so the test asserts the two controls are
 * decoupled.
 *
 * @method static ExportLoginFactory factory(...$arguments)
 */
#[Exportable(column: 'user_id')]
class ExportLogin extends Model implements ExportableContract
{
    use ExportableTrait;

    /** @use HasFactory<ExportLoginFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'export_logins';

    /** @var list<string> */
    protected $fillable = ['user_id', 'ip_address', 'logged_in_at'];

    /** @var array<string, string> */
    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $exportable = [
        'fields' => [
            'ip_address' => 'IP-adres',
            'logged_in_at' => [
                'label' => 'Aangemeld op',
                'transform' => [self::class, 'formatLoggedInAt'],
            ],
        ],
    ];

    /**
     * Transform a Carbon timestamp into a deterministic UTC string.
     * Declared as a static method so it survives serialization in
     * config dumps; closures would not.
     */
    public static function formatLoggedInAt(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '—';
    }

    /**
     * @return Factory<ExportLogin>
     */
    protected static function newFactory(): Factory
    {
        return ExportLoginFactory::new();
    }
}
