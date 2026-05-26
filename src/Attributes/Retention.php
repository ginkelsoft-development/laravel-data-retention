<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Attributes;

use Attribute;

/**
 * Declares the GDPR storage-limitation policy for an Eloquent model.
 *
 * Place the attribute on the class itself. Per-field anonymization
 * strategies are configured separately via the `$retention` array
 * on the model (under the `anonymize` key) — attributes don't support
 * closures, so the property form is preferred when you need them.
 *
 * Example:
 *
 *   #[Retention(period: '2 years', from: 'created_at', action: 'delete')]
 *   class AuditEntry extends Model { use HasRetention; }
 *
 *   #[Retention(period: '5 years', from: 'ended_at', action: 'anonymize')]
 *   class Client extends Model {
 *       use HasRetention;
 *
 *       protected array $retention = [
 *           'anonymize' => [
 *               'first_name' => 'placeholder',
 *               'last_name'  => 'placeholder',
 *               'bsn'        => 'hash',
 *               'phone'      => 'null',
 *           ],
 *       ];
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Retention
{
    /**
     * @param  string  $period
     *                          Carbon-compatible relative period string (e.g. "2 years",
     *                          "90 days", "6 months"). The period is subtracted from
     *                          "now" to determine the cut-off instant.
     * @param  string  $from
     *                        Name of the timestamp column the period is measured from.
     *                        Defaults to "created_at".
     * @param  string  $action
     *                          Either "delete" or "anonymize".
     */
    public function __construct(
        public string $period,
        public string $from = 'created_at',
        public string $action = 'delete',
    ) {}

    /**
     * @return array{period: string, from: string, action: string}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from,
            'action' => $this->action,
        ];
    }
}
