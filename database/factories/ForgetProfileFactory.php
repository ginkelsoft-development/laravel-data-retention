<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\ForgetProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the demo ForgetProfile model. Anonymized (not deleted)
 * when the linked subject is forgotten — three fields are scrubbed
 * via the model's $forgettable['anonymize'] map.
 *
 * @extends Factory<ForgetProfile>
 */
class ForgetProfileFactory extends Factory
{
    /** @var class-string<ForgetProfile> */
    protected $model = ForgetProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => $this->faker->uuid(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->safeEmail(),
        ];
    }

    /**
     * Link this profile to a known subject identifier.
     */
    public function forSubject(string $userId): self
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
