<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Seeders;

use Ginkelsoft\DataRetention\Actions\RecordConsent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a realistic mix of consent events so a developer trying the
 * package locally can immediately see `retention:consent:status` do
 * something meaningful.
 *
 * The seeder produces five subjects, each with a different shape of
 * consent history:
 *
 *  - alice-01: opted in to newsletter only.
 *  - bob-02:   opted in to newsletter and analytics.
 *  - carol-03: opted in to newsletter, then withdrew six months later.
 *  - dan-04:   opted in to a v1 consent text, then opted in again to
 *              the v2 text after the terms changed.
 *  - eve-05:   opted in to marketing, withdrew, opted in again.
 *
 * Run:
 *
 *   php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\ConsentDemoSeeder"
 *   php artisan retention:consent:status dan-04
 *
 * The seeder writes through {@see RecordConsent} (rather than directly
 * via the factory) so each event is real, hash-chained, and indexed —
 * exactly what production code would produce.
 */
class ConsentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $consent = new RecordConsent;

        $consent->grant('alice-01', 'newsletter', source: 'web', occurredAt: Carbon::now()->subMonths(8));

        $consent->grant('bob-02', 'newsletter', source: 'web', occurredAt: Carbon::now()->subMonths(6));
        $consent->grant('bob-02', 'analytics', source: 'web', occurredAt: Carbon::now()->subMonths(6));

        $consent->grant('carol-03', 'newsletter', source: 'web', occurredAt: Carbon::now()->subMonths(10));
        $consent->withdraw('carol-03', 'newsletter', source: 'email', occurredAt: Carbon::now()->subMonths(4));

        $consent->grant('dan-04', 'profiling', version: '1', source: 'web', occurredAt: Carbon::now()->subYear());
        $consent->grant('dan-04', 'profiling', version: '2', source: 'web', occurredAt: Carbon::now()->subMonth());

        $consent->grant('eve-05', 'marketing', source: 'paper', occurredAt: Carbon::now()->subMonths(9));
        $consent->withdraw('eve-05', 'marketing', source: 'phone', occurredAt: Carbon::now()->subMonths(5));
        $consent->grant('eve-05', 'marketing', source: 'web', occurredAt: Carbon::now()->subMonths(2));
    }
}
