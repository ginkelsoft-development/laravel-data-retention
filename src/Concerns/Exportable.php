<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Concerns;

use Ginkelsoft\DataRetention\Actions\CollectSubjectData;
use Ginkelsoft\DataRetention\Support\ExportableConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait Exportable
 *
 * Marks an Eloquent model as participating in GDPR art. 15 ("subject
 * access") exports. The trait is intentionally lightweight: the
 * decisioning lives in {@see ExportableConfig} and
 * {@see CollectSubjectData}.
 *
 * Models declare their policy via the `#[Exportable]` class attribute
 * (subject column) and a protected `$exportable` array property
 * (fields list, labels, optional transforms).
 *
 * Models that also use {@see Forgettable} only need one
 * implementation of `forSubjectQuery` — the signature matches.
 *
 * @mixin Model
 */
trait Exportable
{
    /**
     * Resolve the export policy for this model.
     *
     * @return ExportableConfig|null Null when no policy is declared.
     */
    public function exportablePolicy(): ?ExportableConfig
    {
        return ExportableConfig::for(static::class);
    }

    /**
     * Build the query that selects every record of this model belonging
     * to the given subject.
     *
     * Override on the model when the link is more complex than
     * `column = subject` (multi-column, polymorphic, joined, etc).
     *
     * @return Builder<static>
     */
    public static function forSubjectQuery(string $subject): Builder
    {
        /** @var Builder<static> $query */
        $query = static::query();

        $policy = ExportableConfig::for(static::class);

        if ($policy === null) {
            $query = $query->whereRaw('1=0');
        } else {
            $query = $query->where($policy->column, '=', $subject);
        }

        /** @var Builder<static> $query */
        return $query;
    }
}
