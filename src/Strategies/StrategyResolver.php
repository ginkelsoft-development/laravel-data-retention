<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Strategies;

use Closure;
use Ginkelsoft\DataRetention\Actions\ApplyForget;
use Ginkelsoft\DataRetention\Actions\ApplyRetention;
use Ginkelsoft\DataRetention\Contracts\AnonymizeStrategy;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a per-field anonymization spec (either a built-in strategy
 * id or a callable) to an {@see AnonymizeStrategy} instance.
 *
 * Strings are always treated as strategy ids — never as callable
 * function names — so a config entry like `'hash'` cannot accidentally
 * invoke PHP's built-in `hash()` function.
 *
 * Lives in its own class so both {@see ApplyRetention}
 * and {@see ApplyForget} share the
 * same resolution rules.
 */
final class StrategyResolver
{
    /**
     * Resolve a strategy id or callable to an {@see AnonymizeStrategy}.
     */
    public static function resolve(string|callable $spec): AnonymizeStrategy
    {
        if (is_string($spec)) {
            return match ($spec) {
                'null' => new NullStrategy,
                'hash' => new HashStrategy,
                'placeholder' => new PlaceholderStrategy,
                default => throw new \InvalidArgumentException(
                    "Unknown anonymize strategy '{$spec}'. Allowed: 'null', 'hash', 'placeholder', or a callable."
                ),
            };
        }

        if ($spec instanceof Closure) {
            $callable = $spec;
        } elseif (is_array($spec)) {
            $callable = Closure::fromCallable($spec);
        } else {
            throw new \InvalidArgumentException(
                'Anonymize strategy must be a string id, a Closure, or a [class, method] callable array.'
            );
        }

        return new class($callable) implements AnonymizeStrategy
        {
            public function __construct(private readonly Closure $callable) {}

            public function apply(mixed $value, string $field, Model $model): mixed
            {
                return ($this->callable)($value, $field, $model);
            }
        };
    }
}
