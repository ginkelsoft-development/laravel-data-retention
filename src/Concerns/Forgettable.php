<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Concerns;

use Ginkelsoft\DataRetention\Support\ForgettableConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait Forgettable
 *
 * Marks an Eloquent model as participating in GDPR art. 17 ("right to
 * be forgotten") sweeps. The trait itself is intentionally lightweight:
 * all decisioning lives in {@see ForgettableConfig} and the action
 * classes.
 *
 * Models declare their policy via either the `#[Forgettable]` class
 * attribute or a protected `$forgettable` array property (the property
 * form supports per-field anonymize strategies).
 *
 * `forSubjectQuery` returns the query that selects every row belonging
 * to the given subject. The default implementation uses
 * `WHERE column = subject`; models with a more complex mapping can
 * override the method on the model itself (see ForgetTicket in the
 * test suite for an OR-across-two-columns example).
 *
 * @mixin Model
 */
trait Forgettable
{
    /**
     * Resolve the forget policy for this model.
     *
     * @return ForgettableConfig|null Null when no policy is declared.
     */
    public function forgettablePolicy(): ?ForgettableConfig
    {
        return ForgettableConfig::for(static::class);
    }

    /**
     * Build the query that selects every record of this model belonging
     * to the given subject.
     *
     * Override on the model when the link is more complex than
     * `column = subject` (multi-column, polymorphic, joined, etc).
     *
     * @return Builder<static>
     */
    public static function forSubjectQuery(string $subject): Builder
    {
        /** @var Builder<static> $query */
        $query = static::query();

        $policy = ForgettableConfig::for(static::class);

        if ($policy === null) {
            $query = $query->whereRaw('1=0');
        } else {
            $query = $query->where($policy->column, '=', $subject);
        }

        /** @var Builder<static> $query */
        return $query;
    }
}
