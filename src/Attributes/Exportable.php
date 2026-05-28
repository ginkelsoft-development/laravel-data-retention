<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Attributes;

use Attribute;

/**
 * Declares that an Eloquent model participates in GDPR art. 15
 * "subject access" exports for a given subject.
 *
 * The attribute only carries the subject column. The list of fields
 * that are part of the export — and their human-readable labels and
 * optional transforms — MUST be declared via the protected
 * `$exportable` property on the model, because PHP attributes cannot
 * express closures.
 *
 * Example:
 *
 *   #[Exportable(column: 'user_id')]
 *   class Profile extends Model implements ExportableContract {
 *       use ExportableTrait;
 *
 *       protected array $exportable = [
 *           'fields' => [
 *               'first_name' => 'Voornaam',
 *               'email'      => ['label' => 'E-mailadres'],
 *               'created_at' => ['label' => 'Aangemaakt op',
 *                                'transform' => fn ($v) => $v?->format('Y-m-d H:i:s')],
 *           ],
 *       ];
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Exportable
{
    /**
     * @param  string  $column  Column on this model that points to the
     *                          subject identifier. Defaults to `user_id`.
     */
    public function __construct(
        public string $column = 'user_id',
    ) {}

    /**
     * @return array{column: string}
     */
    public function toArray(): array
    {
        return ['column' => $this->column];
    }
}
