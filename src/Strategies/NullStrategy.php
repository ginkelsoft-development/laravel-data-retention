<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Strategies;

use Ginkelsoft\DataRetention\Contracts\AnonymizeStrategy;
use Illuminate\Database\Eloquent\Model;

/**
 * Replaces the field's value with NULL.
 *
 * Use this for fields that are nullable in the schema and have no
 * meaningful placeholder (free-text remarks, optional second names).
 */
final class NullStrategy implements AnonymizeStrategy
{
    public function apply(mixed $value, string $field, Model $model): mixed
    {
        return null;
    }
}
