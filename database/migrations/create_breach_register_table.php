<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateBreachRegisterTable
 *
 * The breach register (Dutch: datalek-register, art. 33(5) AVG) is the
 * mutable, canonical record of every personal-data breach the
 * organisation has discovered. Each row represents one breach in its
 * current state — open, contained, resolved, reported to the
 * supervisory authority (AP in NL), reported to affected subjects.
 *
 * This is one of the two tables that ship with the breach module. The
 * other, `breach_event_log`, is an append-only audit trail of every
 * change to a register row (created in a separate migration). Together
 * they give you the legally required "current state" + "untampered
 * history" pair that an audit will ask for.
 *
 * Updates to the register row mirror business reality: a breach is a
 * single incident that evolves over time. The audit chain is in the
 * event log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_register', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Human-readable reference: "BREACH-2026-001" or similar.
            // Caller-assigned so it can match an internal numbering scheme.
            $table->string('reference', 64)->unique();

            // When the controller became aware of the breach. The 72-hour
            // notification clock (art. 33(1)) starts here.
            $table->timestamp('discovered_at');

            // When the breach actually happened (if known and different
            // from discovery). May be null when the timeline is unclear.
            $table->timestamp('occurred_at')->nullable();

            // Free-form description of what happened. Keep it factual.
            $table->text('description');

            // 'low' | 'medium' | 'high' | 'critical'. Drives whether
            // notification to subjects (art. 34) is likely required.
            $table->string('severity', 16);

            // Categories of personal data involved, as a JSON array
            // (e.g. ["name", "email", "health"]). Useful for the
            // AP notification form.
            $table->json('data_categories')->nullable();

            // Rough estimate of how many subjects are affected.
            $table->unsignedInteger('subjects_affected')->default(0);

            // Root-cause analysis (filled in as soon as known).
            $table->text('cause')->nullable();

            // Measures taken to contain and mitigate.
            $table->text('mitigation')->nullable();

            // When the supervisory authority (AP in NL) was notified.
            // Null means: not yet. Compare against
            // discovered_at + 72h to flag overdue breaches.
            $table->timestamp('reported_to_authority_at')->nullable();

            // When the affected subjects were notified (art. 34).
            // Null means: not yet, or not required.
            $table->timestamp('reported_to_subjects_at')->nullable();

            // Current status: 'open' | 'contained' | 'resolved'.
            $table->string('status', 16)->default('open');

            $table->timestamps();

            $table->index(['status'], 'breach_register_status_idx');
            $table->index(['discovered_at'], 'breach_register_discovered_idx');
            $table->index(['reported_to_authority_at'], 'breach_register_reported_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breach_register');
    }
};
