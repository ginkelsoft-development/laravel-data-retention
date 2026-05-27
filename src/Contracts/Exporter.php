<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Contracts;

use Ginkelsoft\DataRetention\Support\SubjectExportDataset;

/**
 * Renders a {@see SubjectExportDataset} into a single textual payload
 * (JSON, Markdown, HTML, future formats).
 *
 * Implementations should never themselves mutate the dataset and
 * should never read from the database — the dataset they receive is
 * authoritative and immutable.
 */
interface Exporter
{
    /**
     * The format identifier matched against the `--format=` option of
     * the `retention:export` command (lowercase, single word).
     */
    public function format(): string;

    /**
     * The conventional file extension for files of this format
     * (without leading dot). Used by the command when writing to disk
     * without an explicit extension.
     */
    public function extension(): string;

    /**
     * Render the dataset.
     */
    public function render(SubjectExportDataset $dataset): string;
}
