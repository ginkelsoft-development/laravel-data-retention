<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Ginkelsoft\DataRetention\Attributes\Retention;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Immutable resolved retention policy for a single Eloquent model.
 *
 * Built from a combination of:
 *  1. The `#[Retention]` class attribute (lowest priority).
 *  2. The protected `$retention` array on the model (highest priority).
 *
 * The `$retention` array may override individual keys from the
 * attribute, so a developer can declare the attribute once and
 * tweak (for example) the anonymize strategies on a subclass.
 */
final class RetentionConfig
{
    /**
     * @param  array<string, string|callable>  $anonymize
     *                                                     Per-field anonymization strategy. Keys are field names,
     *                                                     values are either:
     *                                                     - A strategy id ('null', 'hash', 'placeholder').
     *                                                     - A callable `function(mixed $value, string $field, Model $model): mixed`.
     *                                                     Empty when `$action` is `'delete'`.
     */
    public function __construct(
        public readonly string $period,
        public readonly string $from,
        public readonly string $action,
        public readonly array $anonymize = [],
    ) {}

    /**
     * Resolve the retention policy for a given model class.
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

        $period = null;
        $from = 'created_at';
        $action = 'delete';
        /** @var array<string, string|callable> $anonymize */
        $anonymize = [];

        // 1. Attribute on the class.
        foreach ($reflection->getAttributes(Retention::class) as $attribute) {
            $instance = $attribute->newInstance();
            $period = $instance->period;
            $from = $instance->from;
            $action = $instance->action;
        }

        // 2. Protected $retention property (default value).
        if ($reflection->hasProperty('retention')) {
            $defaults = $reflection->getDefaultProperties();
            $value = $defaults['retention'] ?? null;

            if (is_array($value)) {
                if (isset($value['period']) && is_string($value['period'])) {
                    $period = $value['period'];
                }
                if (isset($value['from']) && is_string($value['from'])) {
                    $from = $value['from'];
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

        if ($period === null) {
            return null;
        }

        if (! in_array($action, ['delete', 'anonymize'], true)) {
            throw new \InvalidArgumentException(
                "Retention action for {$class} must be 'delete' or 'anonymize'; got '{$action}'."
            );
        }

        if ($action === 'anonymize' && $anonymize === []) {
            throw new \InvalidArgumentException(
                "Retention policy for {$class} is 'anonymize' but no fields are configured. "
                ."Set protected \$retention = ['anonymize' => [...]] on the model."
            );
        }

        return new self($period, $from, $action, $anonymize);
    }
}
