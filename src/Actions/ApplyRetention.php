<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Closure;
use Ginkelsoft\DataRetention\Contracts\AnonymizeStrategy;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataRetention\Strategies\HashStrategy;
use Ginkelsoft\DataRetention\Strategies\NullStrategy;
use Ginkelsoft\DataRetention\Strategies\PlaceholderStrategy;
use Ginkelsoft\DataRetention\Support\HashChain;
use Ginkelsoft\DataRetention\Support\RetentionConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a resolved retention policy to a single Eloquent model
 * instance and appends a tamper-evident log entry.
 *
 * The action is split into two paths:
 *  - `delete`     — issues a hard delete on the model.
 *  - `anonymize`  — overwrites every configured field with the
 *                   result of its strategy and saves the model.
 *
 * Both paths are idempotent: a record that has already been deleted
 * cannot be acted on again, and an anonymize run that finds nothing
 * to change still writes only one log entry per record per policy
 * application (callers select the records — this class trusts the
 * input).
 *
 * The audit log contains NO personal data. Only the source class,
 * primary key, action, and policy metadata are recorded.
 */
final class ApplyRetention
{
    /**
     * Apply the policy to a single model instance.
     *
     * @return RetentionLogEntry|null
     *                                The log entry that was written, or null in dry-run mode.
     */
    public function apply(Model $model, RetentionConfig $policy, bool $dryRun = false): ?RetentionLogEntry
    {
        $expiredAt = $this->expiredAt($model, $policy);
        $performedAt = Carbon::now();

        if ($dryRun) {
            return null;
        }

        /** @var RetentionLogEntry $entry */
        $entry = DB::transaction(function () use ($model, $policy, $expiredAt, $performedAt): RetentionLogEntry {
            match ($policy->action) {
                'delete' => $this->executeDelete($model),
                'anonymize' => $this->executeAnonymize($model, $policy),
                default => throw new \LogicException(
                    "Unsupported retention action '{$policy->action}'."
                ),
            };

            return $this->writeLogEntry($model, $policy, $expiredAt, $performedAt);
        });

        return $entry;
    }

    /**
     * Hard-delete the record. If the model uses SoftDeletes, we
     * intentionally call `forceDelete()` so the storage-limitation
     * principle is actually satisfied — a soft-deleted row still
     * contains personal data.
     */
    private function executeDelete(Model $model): void
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            /** @var Model&object{forceDelete: callable(): bool} $model */
            $model->forceDelete();

            return;
        }

        $model->delete();
    }

    /**
     * Overwrite every configured field with its strategy's output.
     */
    private function executeAnonymize(Model $model, RetentionConfig $policy): void
    {
        foreach ($policy->anonymize as $field => $strategySpec) {
            $current = $model->getAttribute($field);
            $replacement = $this->resolveStrategy($strategySpec)->apply($current, $field, $model);
            $model->setAttribute($field, $replacement);
        }

        $model->save();
    }

    /**
     * Resolve a strategy id or callable to an {@see AnonymizeStrategy}.
     *
     * Strings are always treated as strategy ids — never as callable
     * function names — so config entries like `'hash'` cannot
     * accidentally invoke PHP's built-in `hash()` function.
     */
    private function resolveStrategy(string|callable $spec): AnonymizeStrategy
    {
        if (is_string($spec)) {
            return match ($spec) {
                'null' => new NullStrategy,
                'hash' => new HashStrategy,
                'placeholder' => new PlaceholderStrategy,
                default => throw new \InvalidArgumentException(
                    "Unknown anonymize strategy '{$spec}'. Allowed: 'null', 'hash', 'placeholder', or a callable."
                ),
            };
        }

        $callable = $spec instanceof Closure ? $spec : Closure::fromCallable($spec);

        return new class($callable) implements AnonymizeStrategy
        {
            public function __construct(private readonly Closure $callable) {}

            public function apply(mixed $value, string $field, Model $model): mixed
            {
                return ($this->callable)($value, $field, $model);
            }
        };
    }

    /**
     * Append the audit row, wiring the hash chain to the previous entry.
     *
     * Timestamps are canonicalized to a 'Y-m-d H:i:s' UTC string BEFORE
     * hashing and persistence. That guarantees the value the hash sees
     * is identical to the value the database stores, so verification
     * still works after the row is read back via raw queries.
     */
    private function writeLogEntry(
        Model $model,
        RetentionConfig $policy,
        Carbon $expiredAt,
        Carbon $performedAt,
    ): RetentionLogEntry {
        $previous = RetentionLogEntry::query()->orderByDesc('id')->lockForUpdate()->first();
        $previousHash = $previous instanceof RetentionLogEntry ? $previous->hash : '';

        $action = $policy->action === 'delete' ? 'deleted' : 'anonymized';

        $modelKey = $model->getKey();
        $modelId = match (true) {
            $modelKey === null => '',
            is_string($modelKey) => $modelKey,
            is_int($modelKey) => (string) $modelKey,
            $modelKey instanceof \Stringable => (string) $modelKey,
            default => serialize($modelKey),
        };

        $payload = [
            'model_type' => $model::class,
            'model_id' => $modelId,
            'action' => $action,
            'retention_period' => $policy->period,
            'retention_field' => $policy->from,
            'expired_at' => $expiredAt->utc()->format('Y-m-d H:i:s'),
            'performed_at' => $performedAt->utc()->format('Y-m-d H:i:s'),
        ];

        $secret = config('data-retention.log_secret');
        $hash = HashChain::compute(
            $payload,
            $previousHash,
            is_string($secret) ? $secret : '',
        );

        /** @var RetentionLogEntry $entry */
        $entry = RetentionLogEntry::query()->create($payload + [
            'previous_hash' => $previousHash,
            'hash' => $hash,
        ]);

        return $entry;
    }

    /**
     * Read the `from` field from the model to determine when the
     * record actually became eligible for retention. Falls back to
     * `now()` for the (impossible-by-construction) NULL case so the
     * audit row is always populated.
     */
    private function expiredAt(Model $model, RetentionConfig $policy): Carbon
    {
        $raw = $model->getAttribute($policy->from);

        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($raw));
        }

        if (is_string($raw) && $raw !== '') {
            return Carbon::parse($raw);
        }

        return Carbon::now();
    }
}
