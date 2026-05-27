<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Closure;
use Ginkelsoft\DataRetention\Attributes\Exportable;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Immutable resolved "subject access" policy for one Eloquent model.
 *
 * Built from:
 *  1. The `#[Exportable]` class attribute (declares the subject column).
 *  2. The protected `$exportable` array property on the model
 *     (declares the export fields and optional transforms).
 *
 * The fields map is normalized at resolve-time to a uniform shape:
 *
 *   [
 *       'first_name' => ['label' => 'Voornaam', 'transform' => null],
 *       'email'      => ['label' => 'E-mailadres', 'transform' => null],
 *       'created_at' => ['label' => 'Aangemaakt op', 'transform' => Closure(...)],
 *   ]
 *
 * Field values may be declared either as a plain label string, or as
 * an array with an explicit `label` and optional `transform` callable.
 */
final class ExportableConfig
{
    /**
     * @param  array<string, array{label: string, transform: ?Closure}>  $fields
     */
    public function __construct(
        public readonly string $column,
        public readonly array $fields,
    ) {}

    /**
     * Resolve the export policy for the given model class.
     *
     * Returns null when no policy is declared.
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
        /** @var array<string, string|array{label?: string, transform?: callable}> $rawFields */
        $rawFields = [];

        // 1. Attribute.
        foreach ($reflection->getAttributes(Exportable::class) as $attribute) {
            $instance = $attribute->newInstance();
            $column = $instance->column;
            $hasPolicy = true;
        }

        // 2. Protected $exportable property.
        if ($reflection->hasProperty('exportable')) {
            $defaults = $reflection->getDefaultProperties();
            $value = $defaults['exportable'] ?? null;

            if (is_array($value)) {
                $hasPolicy = true;
                if (isset($value['column']) && is_string($value['column'])) {
                    $column = $value['column'];
                }
                if (isset($value['fields']) && is_array($value['fields'])) {
                    /** @var array<string, string|array{label?: string, transform?: callable}> $rawFields */
                    $rawFields = $value['fields'];
                }
            }
        }

        if (! $hasPolicy) {
            return null;
        }

        $fields = self::normalizeFields($class, $rawFields);

        if ($fields === []) {
            throw new \InvalidArgumentException(
                "Exportable policy for {$class} declares no fields. "
                ."Set protected \$exportable = ['fields' => [...]] on the model — the "
                .'export must be an explicit opt-in per field; auto-including all columns '
                .'is unsafe because some columns may be internal.'
            );
        }

        return new self($column, $fields);
    }

    /**
     * Normalize the field declarations to a single uniform shape.
     *
     * @param  array<string, string|array{label?: string, transform?: callable}>  $raw
     * @return array<string, array{label: string, transform: ?Closure}>
     */
    private static function normalizeFields(string $class, array $raw): array
    {
        $out = [];

        foreach ($raw as $field => $spec) {
            if (is_string($spec)) {
                $out[$field] = ['label' => $spec, 'transform' => null];

                continue;
            }

            if (! is_array($spec)) {
                throw new \InvalidArgumentException(
                    "Exportable field '{$field}' on {$class} must be a label string or "
                    ."an array of the form ['label' => string, 'transform' => callable]."
                );
            }

            $label = $spec['label'] ?? $field;
            if (! is_string($label)) {
                throw new \InvalidArgumentException(
                    "Exportable field '{$field}' on {$class} has a non-string label."
                );
            }

            $transformRaw = $spec['transform'] ?? null;
            $transform = null;

            if ($transformRaw !== null) {
                if ($transformRaw instanceof Closure) {
                    $transform = $transformRaw;
                } elseif (is_array($transformRaw) && is_callable($transformRaw)) {
                    $transform = Closure::fromCallable($transformRaw);
                } else {
                    throw new \InvalidArgumentException(
                        "Exportable field '{$field}' on {$class} has a transform that is "
                        .'neither a Closure nor a [class, method] callable array.'
                    );
                }
            }

            $out[$field] = ['label' => $label, 'transform' => $transform];
        }

        return $out;
    }
}
