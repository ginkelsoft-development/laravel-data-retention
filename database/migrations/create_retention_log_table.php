<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateRetentionLogTable
 *
 * Stores a tamper-evident audit trail for every retention action.
 *
 * Each row represents a single record that was deleted or anonymized
 * because its retention period had expired. Rows are append-only and
 * are linked together by a SHA-256 hash chain (`hash` includes
 * `previous_hash` and a secret), making any tampering detectable.
 *
 * The log MUST NOT contain personal data. We only record:
 * - model_type / model_id (a pointer, not content)
 * - action  (`deleted` or `anonymized`)
 * - the policy that was applied
 * - timestamps and chain hashes
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('retention_log', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // FQCN of the source model (e.g. App\Models\Client).
            $table->string('model_type');

            // Primary key of the source record (string-safe for ULID / UUID / int).
            $table->string('model_id', 64);

            // 'deleted' or 'anonymized'.
            $table->string('action', 16);

            // The retention period that triggered the action (e.g. "2 years").
            $table->string('retention_period', 64);

            // The field on the source model from which the period was measured.
            $table->string('retention_field', 64);

            // The instant on which the source record became eligible for retention.
            $table->timestamp('expired_at');

            // The instant on which the action was executed and logged.
            $table->timestamp('performed_at');

            // Hash of the previous entry (empty string for the genesis row).
            $table->string('previous_hash', 64);

            // SHA-256 hash of this entry's content + previous_hash + secret.
            $table->string('hash', 64);

            $table->timestamps();

            $table->index(['model_type', 'model_id'], 'retention_log_source_idx');
            $table->index(['action'], 'retention_log_action_idx');
            $table->index(['performed_at'], 'retention_log_performed_idx');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('retention_log');
    }
};
