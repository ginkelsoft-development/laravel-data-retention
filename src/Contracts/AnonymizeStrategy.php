<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Replacement value provider used by the anonymize retention action.
 *
 * Implementations MUST be deterministic enough to be reproducible
 * during dry-runs and MUST NOT keep any reference to the original
 * value. A strategy receives the raw field value (post-cast) so it
 * can decide based on the input, but it should never persist or
 * return that value unchanged.
 */
interface AnonymizeStrategy
{
    /**
     * Return the replacement value for a single field.
     *
     * @param  mixed  $value  The current value of the field (already cast by Eloquent).
     * @param  string  $field  The attribute name being anonymized.
     * @param  Model  $model  The model instance being anonymized.
     * @return mixed The replacement value to be written back.
     */
    public function apply(mixed $value, string $field, Model $model): mixed;
}
