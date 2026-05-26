<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\AuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the example AuditEntry model — a model that uses the
 * `delete` retention action with a 2-year cutoff from `created_at`.
 *
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    /** @var class-string<AuditEntry> */
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => $this->faker->randomElement(['login', 'export', 'access', 'update']),
            'created_at' => now()->subMonths(1),
            'updated_at' => now()->subMonths(1),
        ];
    }

    /**
     * An audit entry that is older than the configured 2-year window.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'created_at' => now()->subYears(3),
            'updated_at' => now()->subYears(3),
        ]);
    }

    /**
     * A fresh audit entry, still within the retention window.
     */
    public function fresh(): self
    {
        return $this->state(fn (): array => [
            'created_at' => now()->subWeek(),
            'updated_at' => now()->subWeek(),
        ]);
    }
}
