<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Immutable resolved "right to be forgotten" policy for one model.
 *
 * Built from a combination of:
 *  1. The `#[Forgettable]` class attribute (lowest priority).
 *  2. The protected `$forgettable` array property on the model
 *     (highest priority — adds support for `anonymize` strategies).
 *
 * Parallel in shape to {@see RetentionConfig} but expresses
 * subject-driven, not time-driven, processing.
 */
final class ForgettableConfig
{
    /**
     * @param  string  $column  Model column that holds the subject identifier.
     * @param  string  $action  Either `delete` or `anonymize`.
     * @param  array<string, string|callable>  $anonymize
     *                                                     Per-field anonymization strategy. Same semantics as in
     *                                                     {@see RetentionConfig::$anonymize}.
     */
    public function __construct(
        public readonly string $column,
        public readonly string $action,
        public readonly array $anonymize = [],
    ) {}

    /**
     * Resolve the forget policy for the given model class.
     *
     * Returns null when the model has no policy declared.
     *
     * @param  class-string<Model>|Model|string  $modelOrClass
     */
    public static function for(string|Model $modelOrClass): ?self
    {
        $class = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;

        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        $hasPolicy = false;
        $column = 'user_id';
        $action = 'delete';
        /** @var array<string, string|callable> $anonymize */
        $anonymize = [];

        // 1. Attribute.
        foreach ($reflection->getAttributes(Forgettable::class) as $attribute) {
            $instance = $attribute->newInstance();
            $column = $instance->column;
            $action = $instance->action;
            $hasPolicy = true;
        }

        // 2. Protected $forgettable property.
        if ($reflection->hasProperty('forgettable')) {
            $defaults = $reflection->getDefaultProperties();
            $value = $defaults['forgettable'] ?? null;

            if (is_array($value)) {
                $hasPolicy = true;
                if (isset($value['column']) && is_string($value['column'])) {
                    $column = $value['column'];
                }
                if (isset($value['action']) && is_string($value['action'])) {
                    $action = $value['action'];
                }
                if (isset($value['anonymize']) && is_array($value['anonymize'])) {
                    /** @var array<string, string|callable> $anonymize */
                    $anonymize = $value['anonymize'];
                }
            }
        }

        if (! $hasPolicy) {
            return null;
        }

        if (! in_array($action, ['delete', 'anonymize'], true)) {
            throw new \InvalidArgumentException(
                "Forget action for {$class} must be 'delete' or 'anonymize'; got '{$action}'."
            );
        }

        if ($action === 'anonymize' && $anonymize === []) {
            throw new \InvalidArgumentException(
                "Forget policy for {$class} is 'anonymize' but no fields are configured. "
                ."Set protected \$forgettable = ['anonymize' => [...]] on the model."
            );
        }

        return new self($column, $action, $anonymize);
    }
}
