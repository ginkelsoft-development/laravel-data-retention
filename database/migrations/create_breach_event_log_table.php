<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateBreachEventLogTable
 *
 * Append-only audit trail for every state transition of a breach
 * register row. Each event participates in a SHA-256 hash chain in the
 * same way as the other audit logs that ship with this package, so
 * tampering with a previous event invalidates every subsequent one.
 *
 * Event types:
 *  - `registered`           — the breach was first recorded
 *  - `updated`              — fields on the register row changed
 *  - `reported_authority`   — notified the supervisory authority
 *  - `reported_subjects`    — notified the affected subjects
 *  - `contained`            — the breach is no longer ongoing
 *  - `resolved`             — closed out for good
 *
 * The `changes` JSON column stores the field-level diff for `updated`
 * events (or the notification reference for the report events). It
 * NEVER contains personal data: that lives in the source systems the
 * breach concerns, not in the register itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_event_log', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Matches breach_register.reference. Not a hard FK because
            // we want events to survive even if a register row is moved
            // or archived; the reference is the durable identifier.
            $table->string('breach_reference', 64);

            // Event type. Use one of the values listed in the class
            // docblock above.
            $table->string('action', 32);

            // JSON-encoded payload describing the event. For 'updated'
            // events this is the field diff. For report events the
            // notification reference. Never personal data.
            $table->json('changes')->nullable();

            // Who performed the action (optional). A username, a system
            // identifier, or null when unknown.
            $table->string('actor', 64)->nullable();

            // When the event happened.
            $table->timestamp('occurred_at');

            // Hash chain pointing to the previous event.
            $table->string('previous_hash', 64);

            // SHA-256 of this row's payload + previous_hash + secret.
            $table->string('hash', 64);

            $table->timestamps();

            $table->index(['breach_reference'], 'breach_event_log_breach_idx');
            $table->index(['action'], 'breach_event_log_action_idx');
            $table->index(['occurred_at'], 'breach_event_log_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breach_event_log');
    }
};
