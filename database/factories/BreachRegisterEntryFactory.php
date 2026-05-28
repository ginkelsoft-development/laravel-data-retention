<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Actions\BreachRegistry;
use Ginkelsoft\DataRetention\Models\BreachRegisterEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Factory for {@see BreachRegisterEntry} demo and test data.
 *
 * Note: this factory bypasses the event log on purpose — it produces
 * `breach_register` rows only, with NO matching `breach_event_log`
 * entries. For factory-driven end-to-end scenarios that exercise the
 * full audit trail, use the demo seeder which goes through
 * {@see BreachRegistry} so the
 * register row and the events stay in lockstep.
 *
 * Helper states:
 *  - `severity($name)`     — set severity (low / medium / high / critical).
 *  - `withCategories(...)` — pin the data categories.
 *  - `discoveredAt($at)`   — pin the discovery instant (resets deadlines).
 *  - `overdue()`           — discovered more than 72 hours ago and not yet reported.
 *  - `approaching()`       — discovered between 48 and 72 hours ago.
 *  - `reportedToAuthority()` — mark as already reported to the AP.
 *  - `reportedToSubjects()`  — mark as already reported to affected subjects.
 *  - `contained()` / `resolved()` — set the status.
 *
 * @extends Factory<BreachRegisterEntry>
 */
class BreachRegisterEntryFactory extends Factory
{
    /** @var class-string<BreachRegisterEntry> */
    protected $model = BreachRegisterEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $discoveredAt = Carbon::instance($this->faker->dateTimeBetween('-30 days', '-1 hour'));

        return [
            'reference' => 'BREACH-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
            'discovered_at' => $discoveredAt,
            'occurred_at' => $discoveredAt->copy()->subHours($this->faker->numberBetween(1, 48)),
            'description' => $this->faker->sentence(12),
            'severity' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'data_categories' => $this->faker->randomElements(
                ['name', 'email', 'phone', 'address', 'health', 'finance', 'bsn'],
                $this->faker->numberBetween(1, 4),
            ),
            'subjects_affected' => $this->faker->numberBetween(1, 5000),
            'cause' => $this->faker->sentence(8),
            'mitigation' => null,
            'reported_to_authority_at' => null,
            'reported_to_subjects_at' => null,
            'status' => 'open',
        ];
    }

    public function severity(string $severity): self
    {
        return $this->state(fn (): array => ['severity' => $severity]);
    }

    /**
     * @param  list<string>  $categories
     */
    public function withCategories(array $categories): self
    {
        return $this->state(fn (): array => ['data_categories' => $categories]);
    }

    public function discoveredAt(Carbon $at): self
    {
        return $this->state(fn (): array => ['discovered_at' => $at]);
    }

    /**
     * A breach whose 72-hour authority-notification deadline has
     * already passed. Discovery is set to 96 hours ago by default.
     */
    public function overdue(): self
    {
        return $this->state(fn (): array => [
            'discovered_at' => Carbon::now()->subHours(96),
            'reported_to_authority_at' => null,
            'severity' => 'high',
        ]);
    }

    /**
     * A breach whose 72-hour deadline lies within the next 24 hours
     * (discovered ~60 hours ago). Useful for testing alerting and
     * demo-seeding "this needs attention TODAY" rows.
     */
    public function approaching(): self
    {
        return $this->state(fn (): array => [
            'discovered_at' => Carbon::now()->subHours(60),
            'reported_to_authority_at' => null,
            'severity' => 'high',
        ]);
    }

    public function reportedToAuthority(?Carbon $when = null): self
    {
        return $this->state(fn (): array => [
            'reported_to_authority_at' => $when ?? Carbon::now(),
        ]);
    }

    public function reportedToSubjects(?Carbon $when = null): self
    {
        return $this->state(fn (): array => [
            'reported_to_subjects_at' => $when ?? Carbon::now(),
        ]);
    }

    public function contained(): self
    {
        return $this->state(fn (): array => ['status' => 'contained']);
    }

    public function resolved(): self
    {
        return $this->state(fn (): array => ['status' => 'resolved']);
    }
}
