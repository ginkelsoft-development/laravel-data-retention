<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract every Eloquent model registered under
 * `data-retention.exportable.models` must implement.
 *
 * The {@see \Ginkelsoft\DataRetention\Concerns\Exportable} trait
 * provides a default implementation that satisfies this contract via
 * `WHERE {policy.column} = :subject`. Models with a more complex
 * subject mapping can override `forSubjectQuery` while still
 * implementing this contract, so the collector can keep its dispatch
 * fully typed.
 *
 * A model that implements both this contract and
 * {@see Forgettable} only needs one implementation of
 * `forSubjectQuery` — the signature is identical.
 */
interface Exportable
{
    /**
     * Build the query that selects every record of this model belonging
     * to the given subject identifier.
     *
     * @return Builder<Model>
     */
    public static function forSubjectQuery(string $subject): Builder;
}
