<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Exporters;

use Ginkelsoft\DataRetention\Contracts\Exporter;
use Ginkelsoft\DataRetention\Support\SubjectExportDataset;

/**
 * Renders a {@see SubjectExportDataset} as pretty-printed JSON.
 *
 * Use this format when handing the export to the subject for further
 * processing or transferring it to another controller — it doubles as
 * the GDPR art. 20 data-portability payload.
 *
 * The top-level shape:
 *
 *   {
 *       "subject_id":    "01HXYZ...",
 *       "collected_at":  "2026-05-27T09:00:00+00:00",
 *       "total_records": 5,
 *       "models": {
 *           "App\\Models\\User": [ { "<label>": "<value>", ... }, ... ],
 *           "App\\Models\\Profile": [ ... ]
 *       }
 *   }
 */
final class JsonExporter implements Exporter
{
    public function format(): string
    {
        return 'json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public function render(SubjectExportDataset $dataset): string
    {
        $payload = [
            'subject_id' => $dataset->subjectId,
            'collected_at' => $dataset->collectedAt->utc()->toIso8601String(),
            'total_records' => $dataset->totalRecords,
            'models' => $dataset->perModel,
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new \RuntimeException('Failed to encode subject access dataset as JSON.');
        }

        return $json;
    }
}
