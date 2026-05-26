<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Carbon\CarbonImmutable;
use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resolves the Eloquent query that selects all records of a model
 * whose retention period has expired.
 *
 * Cut-off rule: `now() - policy.period > record[policy.from]`.
 *
 * Records with a NULL value in the `from` column are NOT included.
 * The package treats NULL as "the clock has not started yet" — for
 * example, a `Client` with `ended_at = null` is an active client and
 * may not be retired by retention.
 *
 * Soft-deleted records are included by default (see
 * `data-retention.include_soft_deleted`); the rationale is that
 * soft-deleted rows still contain personal data and the storage-
 * limitation principle continues to apply to them.
 */
final class ResolveExpiredRecords
{
    /**
     * Build a query of expired records for the given model class.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return Builder<TModel>
     */
    public function query(string $modelClass, ?\DateTimeInterface $asOf = null): Builder
    {
        $policy = RetentionConfig::for($modelClass);

        if ($policy === null) {
            throw new \InvalidArgumentException(
                "Model {$modelClass} has no retention policy declared. "
                .'Add the HasRetention trait and either the #[Retention] attribute '
                .'or a $retention array.'
            );
        }

        $cutoff = $this->cutoff($policy->period, $asOf);

        /** @var Builder<TModel> $query */
        $query = $modelClass::query();

        if ($this->shouldIncludeSoftDeleted($modelClass)) {
            /** @var Builder<TModel> $query */
            $query = $query->withTrashed(); // @phpstan-ignore-line method.notFound
        }

        $query = $query->whereNotNull($policy->from);
        $query = $query->where($policy->from, '<=', $cutoff);

        /** @var Builder<TModel> $query */
        return $query;
    }

    /**
     * Calculate the cut-off instant for a Carbon-style period.
     */
    public function cutoff(string $period, ?\DateTimeInterface $asOf = null): CarbonImmutable
    {
        $now = $asOf !== null
            ? CarbonImmutable::instance(\DateTime::createFromInterface($asOf))
            : CarbonImmutable::now();

        return $now->sub($period);
    }

    /**
     * Decide whether soft-deleted rows should be considered.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function shouldIncludeSoftDeleted(string $modelClass): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            return false;
        }

        return (bool) config('data-retention.include_soft_deleted', true);
    }
}
