<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\ForgetOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the demo ForgetOrder model. Deleted in full when the
 * linked subject is forgotten (action = delete on column = user_id).
 *
 * @extends Factory<ForgetOrder>
 */
class ForgetOrderFactory extends Factory
{
    /** @var class-string<ForgetOrder> */
    protected $model = ForgetOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => $this->faker->uuid(),
            'reference' => 'ORD-'.strtoupper($this->faker->bothify('??##??')),
            'amount_cents' => $this->faker->numberBetween(99, 99999),
        ];
    }

    /**
     * Link this order to a known subject identifier.
     */
    public function forSubject(string $userId): self
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
