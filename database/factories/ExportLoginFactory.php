<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\ExportLogin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the demo ExportLogin model. Exercises the `transform`
 * callable on an Exportable field — the `logged_in_at` timestamp is
 * rendered as a deterministic UTC string in the export.
 *
 * @extends Factory<ExportLogin>
 */
class ExportLoginFactory extends Factory
{
    /** @var class-string<ExportLogin> */
    protected $model = ExportLogin::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => $this->faker->uuid(),
            'ip_address' => $this->faker->ipv4(),
            'logged_in_at' => $this->faker->dateTimeBetween('-1 year')->format('Y-m-d H:i:s'),
        ];
    }

    public function forSubject(string $userId): self
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
