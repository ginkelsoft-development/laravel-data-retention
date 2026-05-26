<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Retention;
use Ginkelsoft\DataRetention\Concerns\HasRetention;
use Ginkelsoft\DataRetention\Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model demonstrating the attribute form: a delete policy with
 * the default `from` field.
 *
 * @method static AuditEntryFactory factory(...$arguments)
 */
#[Retention(period: '2 years', from: 'created_at', action: 'delete')]
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    use HasRetention;

    /** @var string */
    protected $table = 'audit_entries';

    /** @var list<string> */
    protected $fillable = ['action', 'created_at'];

    /** @var bool */
    public $timestamps = true;

    /**
     * Resolve the package-provided factory for this test model.
     *
     * @return Factory<AuditEntry>
     */
    protected static function newFactory(): Factory
    {
        return AuditEntryFactory::new();
    }
}
