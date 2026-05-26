<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Seeders;

use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Ginkelsoft\DataRetention\Tests\Models\Client;
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic mix of expired and active records so a developer
 * trying the package locally can immediately see `retention:run` do
 * something meaningful.
 *
 * Run:
 *
 *   php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\RetentionDemoSeeder"
 *   php artisan retention:run --dry-run
 *   php artisan retention:run
 *
 * Important: the seeder operates on the package's own test models;
 * register equivalent factories on your own models to seed your app.
 */
class RetentionDemoSeeder extends Seeder
{
    /**
     * Seed the database.
     */
    public function run(): void
    {
        AuditEntry::factory()->count(10)->expired()->create();
        AuditEntry::factory()->count(10)->fresh()->create();

        Client::factory()->count(5)->expired()->create();
        Client::factory()->count(5)->recentlyEnded()->create();
        Client::factory()->count(5)->active()->create();
    }
}
