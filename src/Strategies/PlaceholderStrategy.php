<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Strategies;

use Ginkelsoft\DataRetention\Contracts\AnonymizeStrategy;
use Illuminate\Database\Eloquent\Model;

/**
 * Replaces the field's value with a static placeholder string.
 *
 * Use this for required text fields where downstream code may rely
 * on a non-null value. The placeholder is read from
 * `data-retention.placeholders.string` (defaults to `'[REDACTED]'`)
 * unless a custom value is passed to the constructor.
 *
 * For more nuanced cases (e.g. a placeholder that depends on the
 * field name) use a callable strategy instead.
 */
final class PlaceholderStrategy implements AnonymizeStrategy
{
    public function __construct(
        private readonly ?string $value = null,
    ) {}

    public function apply(mixed $value, string $field, Model $model): mixed
    {
        if ($this->value !== null) {
            return $this->value;
        }

        $configured = config('data-retention.placeholders.string', '[REDACTED]');

        return is_string($configured) ? $configured : '[REDACTED]';
    }
}
