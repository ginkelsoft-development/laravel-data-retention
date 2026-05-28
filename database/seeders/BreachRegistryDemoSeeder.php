<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Seeders;

use Ginkelsoft\DataRetention\Actions\BreachRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds five breaches across the lifecycle states a registry will see
 * in practice, so a developer can run `retention:breach:list` and
 * `retention:breach:deadlines` immediately and observe useful output.
 *
 *  - BREACH-DEMO-001: fresh, just discovered, still open.
 *                     Authority deadline lies in the future.
 *  - BREACH-DEMO-002: discovered ~60 hours ago, NOT yet reported.
 *                     Surfaces in `retention:breach:deadlines` as
 *                     "approaching".
 *  - BREACH-DEMO-003: discovered ~96 hours ago, NOT yet reported.
 *                     Surfaces as OVERDUE — exit code 1.
 *  - BREACH-DEMO-004: properly handled — reported to authority and
 *                     to subjects, contained, then resolved. The
 *                     event log shows the full chain.
 *  - BREACH-DEMO-005: low severity, contained quickly, never
 *                     escalated to authority notification (a
 *                     judgement call documented in the cause).
 *
 * Run:
 *
 *   php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\BreachRegistryDemoSeeder"
 *   php artisan retention:breach:list
 *   php artisan retention:breach:deadlines
 *   php artisan retention:breach:show BREACH-DEMO-004
 *
 * Like the other demo seeders, this one operates through the
 * {@see BreachRegistry} actions so register rows and event log rows
 * stay in lockstep, with a verifiable hash chain.
 */
class BreachRegistryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $registry = new BreachRegistry;

        $registry->register(
            reference: 'BREACH-DEMO-001',
            discoveredAt: Carbon::now()->subMinutes(45),
            description: 'Operator inadvertently emailed an export of 12 client records to the wrong recipient.',
            severity: 'medium',
            occurredAt: Carbon::now()->subHours(1),
            dataCategories: ['name', 'email', 'order_history'],
            subjectsAffected: 12,
            cause: 'Operator selected the wrong recipient group in the CRM export tool.',
            actor: 'ops@example.com',
        );

        $registry->register(
            reference: 'BREACH-DEMO-002',
            discoveredAt: Carbon::now()->subHours(60),
            description: 'A backup of staging data containing 8500 customer emails was exposed via a misconfigured object-storage bucket.',
            severity: 'high',
            occurredAt: Carbon::now()->subHours(72),
            dataCategories: ['email', 'name'],
            subjectsAffected: 8500,
            cause: 'Bucket policy edited without review during emergency rollback.',
            actor: 'sre@example.com',
        );

        $registry->register(
            reference: 'BREACH-DEMO-003',
            discoveredAt: Carbon::now()->subHours(96),
            description: 'Internal report listing patient initials was forwarded to an external auditor.',
            severity: 'high',
            occurredAt: Carbon::now()->subHours(100),
            dataCategories: ['name', 'health'],
            subjectsAffected: 47,
            cause: 'Email reply auto-completed an external address that resembled an internal one.',
            actor: 'legal@example.com',
        );

        $registry->register(
            reference: 'BREACH-DEMO-004',
            discoveredAt: Carbon::now()->subDays(7),
            description: 'A laptop containing customer phone numbers was stolen from a parked car.',
            severity: 'critical',
            occurredAt: Carbon::now()->subDays(7)->subHours(2),
            dataCategories: ['name', 'phone'],
            subjectsAffected: 2300,
            cause: 'Hardware theft; disk was full-disk-encrypted but the recovery key was on a sticky note inside the laptop bag.',
            actor: 'sre@example.com',
        );
        $registry->update('BREACH-DEMO-004', [
            'mitigation' => 'Remote-wipe issued, recovery key revoked, hardware encryption policy updated.',
        ], actor: 'sre@example.com');
        $registry->reportToAuthority('BREACH-DEMO-004',
            reportedAt: Carbon::now()->subDays(6),
            notificationReference: 'AP-2026-DEMO-001',
            actor: 'dpo@example.com',
        );
        $registry->reportToSubjects('BREACH-DEMO-004',
            reportedAt: Carbon::now()->subDays(5),
            channel: 'email',
            actor: 'dpo@example.com',
        );
        $registry->contain('BREACH-DEMO-004', actor: 'sre@example.com');
        $registry->resolve('BREACH-DEMO-004', actor: 'dpo@example.com');

        $registry->register(
            reference: 'BREACH-DEMO-005',
            discoveredAt: Carbon::now()->subHours(20),
            description: 'Marketing newsletter mistakenly used a To: instead of Bcc:, exposing recipient addresses to each other.',
            severity: 'low',
            occurredAt: Carbon::now()->subHours(20),
            dataCategories: ['email'],
            subjectsAffected: 320,
            cause: 'Template bug in the newsletter renderer; only a single send affected.',
            actor: 'marketing@example.com',
        );
        $registry->contain('BREACH-DEMO-005', actor: 'marketing@example.com');
    }
}
