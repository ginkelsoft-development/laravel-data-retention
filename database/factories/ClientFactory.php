<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the example Client model used in tests and the
 * `data-retention:demo-seed` Artisan command.
 *
 * Provides realistic-looking PII so the anonymize strategies have
 * something meaningful to obscure. Two state helpers are exposed:
 *
 *  - `expired()` — sets `ended_at` past the configured retention
 *                  period so the record qualifies for retention.
 *  - `active()`  — leaves `ended_at` NULL.
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /** @var class-string<Client> */
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'bsn' => (string) $this->faker->numerify('#########'),
            'phone' => $this->faker->e164PhoneNumber(),
            'ended_at' => null,
        ];
    }

    /**
     * A client whose relationship has ended long enough ago that
     * the retention period (5 years on the Client test model) has
     * already lapsed.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'ended_at' => now()->subYears(6),
        ]);
    }

    /**
     * A client whose relationship has just ended; not yet expired.
     */
    public function recentlyEnded(): self
    {
        return $this->state(fn (): array => [
            'ended_at' => now()->subMonths(3),
        ]);
    }

    /**
     * A still-active client (`ended_at` NULL).
     */
    public function active(): self
    {
        return $this->state(fn (): array => [
            'ended_at' => null,
        ]);
    }
}
