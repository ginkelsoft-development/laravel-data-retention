<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\ForgetUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for the demo ForgetUser model — the row that represents the
 * subject themselves. When the subject is forgotten, this row is
 * hard-deleted (action = delete on column = id).
 *
 * @extends Factory<ForgetUser>
 */
class ForgetUserFactory extends Factory
{
    /** @var class-string<ForgetUser> */
    protected $model = ForgetUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'email' => $this->faker->unique()->safeEmail(),
        ];
    }

    /**
     * Force a known subject identifier so demo seeders can produce a
     * deterministic record set that the `retention:forget` command can
     * be invoked against.
     */
    public function withId(string $id): self
    {
        return $this->state(fn (): array => ['id' => $id]);
    }
}
