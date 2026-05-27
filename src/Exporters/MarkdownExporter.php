<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Exporters;

use Ginkelsoft\DataRetention\Contracts\Exporter;
use Ginkelsoft\DataRetention\Support\SubjectExportDataset;

/**
 * Renders a {@see SubjectExportDataset} as a human-readable Markdown
 * report. Suitable for sharing with the subject (a clear summary they
 * can read) and for archiving alongside the request itself.
 *
 * Structure: a header with the subject identifier and collection
 * instant, a single line listing the total record count, and one
 * section per model with a two-column "Field / Value" table per
 * record.
 */
final class MarkdownExporter implements Exporter
{
    public function format(): string
    {
        return 'markdown';
    }

    public function extension(): string
    {
        return 'md';
    }

    public function render(SubjectExportDataset $dataset): string
    {
        $lines = [];

        $lines[] = '# Subject access export';
        $lines[] = '';
        $lines[] = sprintf('- **Subject identifier**: `%s`', $dataset->subjectId);
        $lines[] = sprintf('- **Collected at**: %s', $dataset->collectedAt->utc()->toIso8601String());
        $lines[] = sprintf('- **Total records**: %d', $dataset->totalRecords);
        $lines[] = '';

        if ($dataset->perModel === []) {
            $lines[] = '_No records found for this subject in any registered model._';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($dataset->perModel as $modelClass => $rows) {
            $lines[] = sprintf('## %s', $modelClass);
            $lines[] = '';
            $lines[] = sprintf('%d record(s)', count($rows));
            $lines[] = '';

            foreach ($rows as $index => $row) {
                $lines[] = sprintf('### Record %d', $index + 1);
                $lines[] = '';
                $lines[] = '| Field | Value |';
                $lines[] = '| --- | --- |';

                foreach ($row as $label => $value) {
                    $lines[] = sprintf(
                        '| %s | %s |',
                        $this->escapeCell((string) $label),
                        $this->escapeCell($this->stringify($value)),
                    );
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Convert any field value into a Markdown-safe single-line string.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '_(null)_';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '_(unrenderable)_' : $encoded;
    }

    /**
     * Escape pipe and newline so a value can never break the table.
     */
    private function escapeCell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return str_replace('|', '\|', $value);
    }
}
