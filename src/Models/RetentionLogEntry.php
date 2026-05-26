<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Models;

use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class RetentionLogEntry
 *
 * Append-only audit row that records a single retention action.
 *
 * Each entry forms part of a SHA-256 hash chain: the `hash` column
 * is derived from the entry's own payload, the `previous_hash`, and
 * a secret held in `config('data-retention.log_secret')`. As a
 * result, tampering with any entry invalidates every later entry.
 *
 * The model deliberately blocks `update()` and `delete()` so the
 * application itself cannot mutate the audit trail through Eloquent.
 * Bypassing the model (e.g. direct DB writes) is still detectable
 * via {@see HashChain::verify()}.
 *
 * Privacy: the log MUST NOT contain personal data. Only the source
 * record's class and primary key are stored, never its field values.
 *
 * @property int $id
 * @property string $model_type
 * @property string $model_id
 * @property string $action
 * @property string $retention_period
 * @property string $retention_field
 * @property Carbon $expired_at
 * @property Carbon $performed_at
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static static create(array<string, mixed> $attributes = [])
 */
class RetentionLogEntry extends Model
{
    /** @var string */
    protected $table = 'retention_log';

    /** @var list<string> */
    protected $fillable = [
        'model_type',
        'model_id',
        'action',
        'retention_period',
        'retention_field',
        'expired_at',
        'performed_at',
        'previous_hash',
        'hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'expired_at' => 'datetime',
        'performed_at' => 'datetime',
    ];

    /**
     * Boot the model and block any mutation after creation.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException(
                'RetentionLogEntry is append-only and cannot be updated. '
                .'The audit trail is tamper-evident; modify the database directly '
                .'only if you understand that doing so invalidates the hash chain.'
            );
        });

        static::deleting(function (): bool {
            throw new \RuntimeException(
                'RetentionLogEntry is append-only and cannot be deleted. '
                .'The audit trail must be preserved for the full statutory retention period.'
            );
        });
    }
}
