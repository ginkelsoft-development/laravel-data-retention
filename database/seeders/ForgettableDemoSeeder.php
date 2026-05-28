<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Seeders;

use Ginkelsoft\DataRetention\Tests\Models\ForgetOrder;
use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Ginkelsoft\DataRetention\Tests\Models\ForgetTicket;
use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic mix of subjects with related records across every
 * Forgettable model so a developer trying the package locally can
 * immediately see `retention:forget` do something meaningful.
 *
 * The seeder creates three subjects:
 *
 *  - "alice-01" — typical case: own user row, profile, two orders,
 *                 one ticket they reported, one ticket assigned to them.
 *  - "bob-02"   — same shape, exists to prove the sweep does not
 *                 over-reach across subjects.
 *  - "carol-03" — has only orders (no profile/tickets) to exercise
 *                 the "partial coverage" path.
 *
 * Run:
 *
 *   php artisan db:seed --class="Ginkelsoft\\DataRetention\\Database\\Seeders\\ForgettableDemoSeeder"
 *   php artisan retention:forget alice-01 --dry-run
 *   php artisan retention:forget alice-01
 *
 * After the run alice-01 is gone (her user row), her profile is
 * anonymized in place, her orders are deleted, and her tickets are
 * deleted. Bob and Carol are untouched. The action is recorded in the
 * `forget_log` table with `subject_hash = SHA-256("subject|alice-01|...")`.
 */
class ForgettableDemoSeeder extends Seeder
{
    /**
     * Seed the database.
     */
    public function run(): void
    {
        $this->seedSubject('alice-01');
        $this->seedSubject('bob-02');

        ForgetUser::factory()->withId('carol-03')->create();
        ForgetOrder::factory()->count(3)->forSubject('carol-03')->create();
    }

    /**
     * Seed a full subject "tree": user, profile, two orders, two tickets.
     */
    private function seedSubject(string $id): void
    {
        ForgetUser::factory()->withId($id)->create();

        ForgetProfile::factory()->forSubject($id)->create();

        ForgetOrder::factory()->count(2)->forSubject($id)->create();

        ForgetTicket::factory()->reportedBy($id)->create();
        ForgetTicket::factory()->assignedTo($id)->create();
    }
}
