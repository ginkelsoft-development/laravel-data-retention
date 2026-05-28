<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Tests\Models\ForgetTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the demo ForgetTicket model. Demonstrates a model where
 * the subject can appear in either of two columns (reporter_id OR
 * assignee_id), handled by the custom forSubjectQuery() override on
 * the model itself.
 *
 * @extends Factory<ForgetTicket>
 */
class ForgetTicketFactory extends Factory
{
    /** @var class-string<ForgetTicket> */
    protected $model = ForgetTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_id' => $this->faker->uuid(),
            'assignee_id' => null,
            'subject' => $this->faker->sentence(4),
        ];
    }

    /**
     * The subject is the reporter of the ticket.
     */
    public function reportedBy(string $userId): self
    {
        return $this->state(fn (): array => ['reporter_id' => $userId]);
    }

    /**
     * The subject is the assignee of the ticket.
     */
    public function assignedTo(string $userId): self
    {
        return $this->state(fn (): array => [
            'reporter_id' => $this->faker->uuid(),
            'assignee_id' => $userId,
        ]);
    }
}
