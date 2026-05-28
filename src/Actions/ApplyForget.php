<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Actions;

use Ginkelsoft\DataRetention\Models\ForgetLogEntry;
use Ginkelsoft\DataRetention\Strategies\StrategyResolver;
use Ginkelsoft\DataRetention\Support\ForgettableConfig;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a resolved {@see ForgettableConfig} to a single Eloquent
 * model instance on behalf of a specific subject, and appends a
 * tamper-evident row to `forget_log`.
 *
 * Behaviour mirrors {@see ApplyRetention}:
 *  - `delete` calls `forceDelete()` on SoftDeletes models so that the
 *    storage-limitation principle is actually satisfied — the row no
 *    longer holds personal data.
 *  - `anonymize` overwrites every configured field through the same
 *    {@see StrategyResolver} the retention path uses.
 *
 * The audit log contains NO personal data. Only `subject_hash`, the
 * source class, primary key, action, and timestamps.
 */
final class ApplyForget
{
    /**
     * Apply the policy to a single model instance for the given subject.
     *
     * @param  string  $subjectHash  Pre-computed irreversible subject hash.
     * @param  Carbon|null  $requestedAt  When the subject filed the request, if known.
     * @return ForgetLogEntry|null The log entry written, or null in dry-run mode.
     */
    public function apply(
        Model $model,
        ForgettableConfig $policy,
        string $subjectHash,
        ?Carbon $requestedAt = null,
        bool $dryRun = false,
    ): ?ForgetLogEntry {
        if ($dryRun) {
            return null;
        }

        $performedAt = Carbon::now();

        /** @var ForgetLogEntry $entry */
        $entry = DB::transaction(function () use ($model, $policy, $subjectHash, $requestedAt, $performedAt): ForgetLogEntry {
            match ($policy->action) {
                'delete' => $this->executeDelete($model),
                'anonymize' => $this->executeAnonymize($model, $policy),
                default => throw new \LogicException(
                    "Unsupported forget action '{$policy->action}'."
                ),
            };

            return $this->writeLogEntry($model, $policy, $subjectHash, $requestedAt, $performedAt);
        });

        return $entry;
    }

    /**
     * Hard-delete the record. On SoftDeletes models we force-delete so
     * the row no longer holds personal data, which is the whole point.
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
    private function executeAnonymize(Model $model, ForgettableConfig $policy): void
    {
        foreach ($policy->anonymize as $field => $strategySpec) {
            $current = $model->getAttribute($field);
            $replacement = StrategyResolver::resolve($strategySpec)->apply($current, $field, $model);
            $model->setAttribute($field, $replacement);
        }

        $model->save();
    }

    /**
     * Append a row to `forget_log`, chaining its hash to the previous
     * row in the same table.
     */
    private function writeLogEntry(
        Model $model,
        ForgettableConfig $policy,
        string $subjectHash,
        ?Carbon $requestedAt,
        Carbon $performedAt,
    ): ForgetLogEntry {
        $previous = ForgetLogEntry::query()->orderByDesc('id')->lockForUpdate()->first();
        $previousHash = $previous instanceof ForgetLogEntry ? $previous->hash : '';

        $action = $policy->action === 'delete' ? 'forgotten_deleted' : 'forgotten_anonymized';

        $modelKey = $model->getKey();
        $modelId = match (true) {
            $modelKey === null => '',
            is_string($modelKey) => $modelKey,
            is_int($modelKey) => (string) $modelKey,
            $modelKey instanceof \Stringable => (string) $modelKey,
            default => serialize($modelKey),
        };

        $payload = [
            'subject_hash' => $subjectHash,
            'model_type' => $model::class,
            'model_id' => $modelId,
            'action' => $action,
            'requested_at' => $requestedAt?->utc()->format('Y-m-d H:i:s'),
            'performed_at' => $performedAt->utc()->format('Y-m-d H:i:s'),
        ];

        $secret = config('data-retention.log_secret');
        $hash = HashChain::compute(
            $payload,
            $previousHash,
            is_string($secret) ? $secret : '',
        );

        /** @var ForgetLogEntry $entry */
        $entry = ForgetLogEntry::query()->create($payload + [
            'previous_hash' => $previousHash,
            'hash' => $hash,
        ]);

        return $entry;
    }
}
