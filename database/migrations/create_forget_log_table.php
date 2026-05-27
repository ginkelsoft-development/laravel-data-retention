<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateForgetLogTable
 *
 * Stores a tamper-evident audit trail for every GDPR art. 17
 * ("right to be forgotten") action executed against a specific subject.
 *
 * The table is structurally similar to `retention_log` but lives apart
 * for two reasons:
 *
 *  1. The existing `retention_log` hash chain is computed over a fixed
 *     payload schema. Adding a `subject_hash` column there would change
 *     the payload of every row written from then on, breaking the
 *     verifiability of rows that pre-dated the migration.
 *  2. Conceptually, time-driven retention and subject-driven forgetting
 *     are different controls. Auditing one without the other should not
 *     require filtering a shared table.
 *
 * Both tables share the same {@see HashChain}
 * implementation; only the payload contents differ.
 *
 * The log MUST NOT contain personal data. Only `subject_hash`
 * (irreversible SHA-256 of the subject identifier + secret), the
 * `model_type` / `model_id` pointer, the action, and timestamps.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('forget_log', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // SHA-256 hex of the subject identifier + log_secret.
            // Same subject always hashes to the same value, but the
            // identifier itself cannot be recovered from the hash.
            $table->string('subject_hash', 64);

            // FQCN of the source model (e.g. App\Models\Order).
            $table->string('model_type');

            // Primary key of the source record (string-safe for ULID / UUID / int).
            $table->string('model_id', 64);

            // 'forgotten_deleted' or 'forgotten_anonymized'.
            $table->string('action', 32);

            // When the forget request was filed by the subject, if known.
            $table->timestamp('requested_at')->nullable();

            // When the action was executed and logged.
            $table->timestamp('performed_at');

            // Hash of the previous entry (empty string for the genesis row).
            $table->string('previous_hash', 64);

            // SHA-256 hash of this entry's content + previous_hash + secret.
            $table->string('hash', 64);

            $table->timestamps();

            $table->index(['subject_hash'], 'forget_log_subject_idx');
            $table->index(['model_type', 'model_id'], 'forget_log_source_idx');
            $table->index(['action'], 'forget_log_action_idx');
            $table->index(['performed_at'], 'forget_log_performed_idx');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('forget_log');
    }
};
