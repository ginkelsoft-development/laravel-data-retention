<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateConsentLogTable
 *
 * Records every consent event under GDPR art. 6(1)(a) and art. 7. The
 * table is append-only and hash-chained: each row is either a `granted`
 * or `withdrawn` event for a specific (subject, purpose, version)
 * combination.
 *
 * Active consent for a (subject, purpose, version) is derived from the
 * latest event: if the latest event is `granted`, consent is active.
 * If the latest event is `withdrawn` (or no event exists), consent is
 * not active. The history of grants and withdrawals stays visible in
 * the log forever — that is what art. 7(1) accountability requires.
 *
 * Privacy note: this table DOES store the subject identifier directly,
 * because consent inherently requires identification (you cannot prove
 * "this person consented" without knowing who the person is). The other
 * audit logs in this package (retention_log, forget_log) use an
 * irreversible SubjectHash precisely because they do not need
 * identification. Consent does. Document this in your DPIA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_log', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The subject who is giving or withdrawing consent.
            // String-safe for ULID / UUID / integer / email identifiers.
            $table->string('subject_id', 128);

            // What the subject consented to (purpose of processing).
            // E.g. 'newsletter', 'marketing', 'analytics', 'profile_sharing'.
            $table->string('purpose', 128);

            // Version of the consent text or processing context.
            // When the terms change, prior consent does not automatically
            // cover the new version — record a new grant against the new
            // version string.
            $table->string('version', 32)->default('1');

            // 'granted' or 'withdrawn'.
            $table->string('action', 16);

            // Where the event came from: 'web', 'api', 'paper',
            // 'phone', etc. Free-form, max 64 chars.
            $table->string('source', 64)->nullable();

            // Arbitrary additional context the controller wants to
            // record (IP address, form ID, user agent, etc). Keep this
            // proportional — do not stuff PII in here unless necessary.
            $table->json('metadata')->nullable();

            // When the event occurred. Distinct from `created_at`, which
            // records when the row was inserted; `occurred_at` is when
            // the subject actually acted, which may differ for backfills.
            $table->timestamp('occurred_at');

            // Hash of the previous row (empty for the genesis row).
            $table->string('previous_hash', 64);

            // SHA-256 of this row's payload + previous_hash + secret.
            $table->string('hash', 64);

            $table->timestamps();

            $table->index(['subject_id', 'purpose', 'version'], 'consent_lookup_idx');
            $table->index(['subject_id'], 'consent_subject_idx');
            $table->index(['action'], 'consent_action_idx');
            $table->index(['occurred_at'], 'consent_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_log');
    }
};
