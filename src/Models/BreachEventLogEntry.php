<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class BreachEventLogEntry
 *
 * Append-only row in the breach event log. Each row records one state
 * transition for a breach in `breach_register`, identified by its
 * `breach_reference`. Rows participate in a SHA-256 hash chain so
 * tampering with any row invalidates every subsequent row.
 *
 * @property int $id
 * @property string $breach_reference
 * @property string $action
 * @property array<string, mixed>|null $changes
 * @property string|null $actor
 * @property Carbon $occurred_at
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static static create(array<string, mixed> $attributes = [])
 */
class BreachEventLogEntry extends Model
{
    /** @var string */
    protected $table = 'breach_event_log';

    /** @var list<string> */
    protected $fillable = [
        'breach_reference',
        'action',
        'changes',
        'actor',
        'occurred_at',
        'previous_hash',
        'hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'changes' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Block any mutation. The audit trail must remain intact.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException(
                'BreachEventLogEntry is append-only and cannot be updated. '
                .'A correction is a new event, not a mutation.'
            );
        });

        static::deleting(function (): bool {
            throw new \RuntimeException(
                'BreachEventLogEntry is append-only and cannot be deleted. '
                .'The audit trail must be preserved for the full statutory retention period.'
            );
        });
    }
}
