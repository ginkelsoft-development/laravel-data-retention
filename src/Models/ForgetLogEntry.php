<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Models;

use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class ForgetLogEntry
 *
 * Append-only audit row that records a single "right to be forgotten"
 * action. Each row forms part of a SHA-256 hash chain identical in
 * concept to {@see RetentionLogEntry}, but tracked in a separate
 * `forget_log` table so the two controls have independent, verifiable
 * audit trails.
 *
 * Privacy: the log contains no personal data. The subject is
 * represented only by `subject_hash`, an irreversible SHA-256 of the
 * subject identifier combined with `config('data-retention.log_secret')`.
 *
 * The model deliberately blocks `update()` and `delete()` so the
 * application itself cannot mutate the audit trail through Eloquent.
 * Bypassing the model (e.g. direct DB writes) is still detectable
 * via {@see HashChain::verify()}.
 *
 * @property int $id
 * @property string $subject_hash
 * @property string $model_type
 * @property string $model_id
 * @property string $action
 * @property Carbon|null $requested_at
 * @property Carbon $performed_at
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static static create(array<string, mixed> $attributes = [])
 */
class ForgetLogEntry extends Model
{
    /** @var string */
    protected $table = 'forget_log';

    /** @var list<string> */
    protected $fillable = [
        'subject_hash',
        'model_type',
        'model_id',
        'action',
        'requested_at',
        'performed_at',
        'previous_hash',
        'hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'requested_at' => 'datetime',
        'performed_at' => 'datetime',
    ];

    /**
     * Boot the model and block any mutation after creation.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException(
                'ForgetLogEntry is append-only and cannot be updated. '
                .'The audit trail is tamper-evident; modify the database directly '
                .'only if you understand that doing so invalidates the hash chain.'
            );
        });

        static::deleting(function (): bool {
            throw new \RuntimeException(
                'ForgetLogEntry is append-only and cannot be deleted. '
                .'The audit trail must be preserved for the full statutory retention period.'
            );
        });
    }
}
