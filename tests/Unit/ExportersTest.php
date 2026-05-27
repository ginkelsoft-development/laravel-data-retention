<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Exporters\JsonExporter;
use Ginkelsoft\DataRetention\Exporters\MarkdownExporter;
use Ginkelsoft\DataRetention\Support\SubjectExportDataset;
use Illuminate\Support\Carbon;

function fixtureDataset(): SubjectExportDataset
{
    return new SubjectExportDataset(
        subjectId: 'subject-A',
        collectedAt: Carbon::parse('2026-05-27 12:00:00', 'UTC'),
        perModel: [
            'App\\Models\\User' => [
                ['Subject identifier' => 'subject-A', 'E-mailadres' => 'a@example.com'],
            ],
            'App\\Models\\Profile' => [
                ['Voornaam' => 'Wietse', 'Achternaam' => 'van Ginkel', 'E-mailadres' => 'a@example.com'],
            ],
        ],
        totalRecords: 2,
    );
}

it('JsonExporter declares the json format and extension', function (): void {
    $exporter = new JsonExporter;

    expect($exporter->format())->toBe('json');
    expect($exporter->extension())->toBe('json');
});

it('JsonExporter renders a well-formed payload', function (): void {
    $rendered = (new JsonExporter)->render(fixtureDataset());

    $decoded = json_decode($rendered, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['subject_id'])->toBe('subject-A');
    expect($decoded['collected_at'])->toBe('2026-05-27T12:00:00+00:00');
    expect($decoded['total_records'])->toBe(2);
    expect(array_keys($decoded['models']))->toEqual(['App\\Models\\User', 'App\\Models\\Profile']);
    expect($decoded['models']['App\\Models\\Profile'][0]['Voornaam'])->toBe('Wietse');
});

it('JsonExporter pretty-prints the output', function (): void {
    $rendered = (new JsonExporter)->render(fixtureDataset());

    // Pretty-printed JSON has newlines and indentation; the compact
    // form would not.
    expect($rendered)->toContain("\n");
    expect($rendered)->toContain('    ');
});

it('MarkdownExporter declares the markdown format and md extension', function (): void {
    $exporter = new MarkdownExporter;

    expect($exporter->format())->toBe('markdown');
    expect($exporter->extension())->toBe('md');
});

it('MarkdownExporter produces a header, a section per model, and a table per record', function (): void {
    $rendered = (new MarkdownExporter)->render(fixtureDataset());

    expect($rendered)->toContain('# Subject access export');
    expect($rendered)->toContain('**Subject identifier**: `subject-A`');
    expect($rendered)->toContain('**Total records**: 2');
    expect($rendered)->toContain('## App\\Models\\User');
    expect($rendered)->toContain('## App\\Models\\Profile');
    expect($rendered)->toContain('### Record 1');
    expect($rendered)->toContain('| Voornaam | Wietse |');
});

it('MarkdownExporter renders a friendly note when the subject has no data', function (): void {
    $empty = new SubjectExportDataset(
        subjectId: 'unknown',
        collectedAt: Carbon::parse('2026-05-27 12:00:00', 'UTC'),
        perModel: [],
        totalRecords: 0,
    );

    $rendered = (new MarkdownExporter)->render($empty);

    expect($rendered)->toContain('No records found');
});

it('MarkdownExporter escapes pipes and newlines so the table can never break', function (): void {
    $dataset = new SubjectExportDataset(
        subjectId: 'subject-A',
        collectedAt: Carbon::parse('2026-05-27 12:00:00', 'UTC'),
        perModel: [
            'App\\Models\\Comment' => [
                ['Body' => "Line one|with a pipe\nand a newline"],
            ],
        ],
        totalRecords: 1,
    );

    $rendered = (new MarkdownExporter)->render($dataset);

    expect($rendered)->toContain('| Body | Line one\|with a pipe and a newline |');
});

it('MarkdownExporter handles null values and DateTimeInterface values gracefully', function (): void {
    $dataset = new SubjectExportDataset(
        subjectId: 'subject-A',
        collectedAt: Carbon::parse('2026-05-27 12:00:00', 'UTC'),
        perModel: [
            'App\\Models\\Event' => [
                [
                    'Phone' => null,
                    'When' => new DateTimeImmutable('2025-01-15 09:30:00'),
                ],
            ],
        ],
        totalRecords: 1,
    );

    $rendered = (new MarkdownExporter)->render($dataset);

    expect($rendered)->toContain('| Phone | _(null)_ |');
    expect($rendered)->toContain('| When | 2025-01-15 09:30:00 |');
});
