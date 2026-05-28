<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Models;

use Ginkelsoft\DataRetention\Actions\CloseBreach;
use Ginkelsoft\DataRetention\Actions\RegisterBreach;
use Ginkelsoft\DataRetention\Actions\ReportBreachToAuthority;
use Ginkelsoft\DataRetention\Actions\ReportBreachToSubjects;
use Ginkelsoft\DataRetention\Actions\UpdateBreach;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class BreachRegisterEntry
 *
 * The canonical, mutable record of one personal-data breach. Use
 * {@see RegisterBreach},
 * {@see UpdateBreach},
 * {@see ReportBreachToAuthority},
 * {@see ReportBreachToSubjects},
 * and {@see CloseBreach} to manipulate
 * rows — direct Eloquent updates work but skip the audit trail in
 * `breach_event_log`, which defeats the point of having a register.
 *
 * @property int $id
 * @property string $reference
 * @property Carbon $discovered_at
 * @property Carbon|null $occurred_at
 * @property string $description
 * @property string $severity
 * @property list<string>|null $data_categories
 * @property int $subjects_affected
 * @property string|null $cause
 * @property string|null $mitigation
 * @property Carbon|null $reported_to_authority_at
 * @property Carbon|null $reported_to_subjects_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static static create(array<string, mixed> $attributes = [])
 */
class BreachRegisterEntry extends Model
{
    /** @var string */
    protected $table = 'breach_register';

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'discovered_at',
        'occurred_at',
        'description',
        'severity',
        'data_categories',
        'subjects_affected',
        'cause',
        'mitigation',
        'reported_to_authority_at',
        'reported_to_subjects_at',
        'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'discovered_at' => 'datetime',
        'occurred_at' => 'datetime',
        'data_categories' => 'array',
        'subjects_affected' => 'integer',
        'reported_to_authority_at' => 'datetime',
        'reported_to_subjects_at' => 'datetime',
    ];

    /**
     * The instant by which the supervisory authority must have been
     * notified (art. 33(1) AVG: 72 hours from discovery).
     */
    public function authorityNotificationDeadline(): Carbon
    {
        return $this->discovered_at->copy()->addHours(72);
    }

    /**
     * Has the supervisory authority been notified yet?
     */
    public function isReportedToAuthority(): bool
    {
        return $this->reported_to_authority_at !== null;
    }

    /**
     * Is the 72-hour deadline already passed and notification still missing?
     */
    public function isAuthorityNotificationOverdue(?Carbon $asOf = null): bool
    {
        if ($this->isReportedToAuthority()) {
            return false;
        }

        $instant = $asOf ?? Carbon::now();

        return $instant->greaterThan($this->authorityNotificationDeadline());
    }
}
