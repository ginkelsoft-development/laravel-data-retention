<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Concerns;

use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait HasRetention
 *
 * Marks an Eloquent model as participating in GDPR storage-limitation
 * enforcement. The trait itself is intentionally lightweight: all the
 * decisioning lives in {@see RetentionConfig} and the action classes.
 *
 * A model that uses this trait must declare its policy via either:
 *  - The `#[Retention]` class attribute, or
 *  - A protected `$retention` array property.
 *
 * Example (attribute):
 *
 *   #[Retention(period: '2 years', from: 'created_at', action: 'delete')]
 *   class AuditEntry extends Model { use HasRetention; }
 *
 * Example (property + anonymize):
 *
 *   class Client extends Model {
 *       use HasRetention;
 *
 *       protected array $retention = [
 *           'period'    => '5 years',
 *           'from'      => 'ended_at',
 *           'action'    => 'anonymize',
 *           'anonymize' => [
 *               'first_name' => 'placeholder',
 *               'last_name'  => 'placeholder',
 *               'bsn'        => 'hash',
 *               'phone'      => 'null',
 *           ],
 *       ];
 *   }
 *
 * @mixin Model
 */
trait HasRetention
{
    /**
     * Resolve and return the retention policy for this model.
     *
     * @return RetentionConfig|null Null when the model has no policy.
     */
    public function retentionPolicy(): ?RetentionConfig
    {
        return RetentionConfig::for(static::class);
    }
}
