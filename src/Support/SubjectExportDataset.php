<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Support;

use Illuminate\Support\Carbon;

/**
 * Immutable result of a subject-access collection run.
 *
 * Carries the labelled, transformed records collected per Exportable
 * model — exactly what the exporters render into JSON, Markdown, or
 * any future format.
 *
 * `perModel` maps the FQCN of each Exportable model to a list of
 * "rows". Each row is itself a map of `label => value`, so the order
 * of fields in the export follows the order of the `fields`
 * declaration on the model.
 */
final class SubjectExportDataset
{
    /**
     * @param  array<class-string, list<array<string, mixed>>>  $perModel
     */
    public function __construct(
        public readonly string $subjectId,
        public readonly Carbon $collectedAt,
        public readonly array $perModel,
        public readonly int $totalRecords,
    ) {}

    /**
     * @return list<class-string>
     */
    public function modelClasses(): array
    {
        return array_keys($this->perModel);
    }

    /**
     * Record count for a specific model. Returns zero for models that
     * were registered but found nothing.
     */
    public function recordCountFor(string $modelClass): int
    {
        return count($this->perModel[$modelClass] ?? []);
    }
}
