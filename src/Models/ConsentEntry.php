<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class ConsentEntry
 *
 * Append-only row recording a single consent event (`granted` or
 * `withdrawn`) for a specific (subject, purpose, version) combination.
 * Each row participates in a SHA-256 hash chain identical in concept
 * to the retention / forget logs.
 *
 * Privacy: unlike `RetentionLogEntry` and `ForgetLogEntry`, this model
 * stores the subject identifier directly. Consent inherently requires
 * identification — without a real subject identifier you cannot
 * demonstrate "this person consented" under GDPR art. 7(1). The
 * trade-off is documented in the README; record this table in your
 * DPIA and apply your own retention policy to it (typically: keep for
 * the duration of the processing plus the statutory defense period).
 *
 * @property int $id
 * @property string $subject_id
 * @property string $purpose
 * @property string $version
 * @property string $action
 * @property string|null $source
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static static create(array<string, mixed> $attributes = [])
 */
class ConsentEntry extends Model
{
    /** @var string */
    protected $table = 'consent_log';

    /** @var list<string> */
    protected $fillable = [
        'subject_id',
        'purpose',
        'version',
        'action',
        'source',
        'metadata',
        'occurred_at',
        'previous_hash',
        'hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Boot the model and block any mutation after creation.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException(
                'ConsentEntry is append-only and cannot be updated. '
                .'A withdrawal of consent is itself a new event — call '
                .'RecordConsent::withdraw() instead of mutating an existing row.'
            );
        });

        static::deleting(function (): bool {
            throw new \RuntimeException(
                'ConsentEntry is append-only and cannot be deleted. '
                .'The proof of consent (and its withdrawal) is exactly '
                .'what GDPR art. 7(1) accountability requires you to keep.'
            );
        });
    }
}
