<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Database\Factories;

use Ginkelsoft\DataRetention\Actions\RecordConsent;
use Ginkelsoft\DataRetention\Models\ConsentEntry;
use Ginkelsoft\DataRetention\Support\HashChain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for {@see ConsentEntry} demo and test data.
 *
 * Produces hash-chained rows: every generated row reads the latest
 * existing row and chains its hash to it, so a sequence of
 * `factory()->create()` calls yields a verifiable consent log without
 * needing to go through {@see RecordConsent}.
 *
 * Note about `count()`: Laravel's `factory()->count(N)->create()`
 * builds ALL N attribute arrays BEFORE inserting any of them. That
 * breaks the chain — every row would compute `previous_hash = ''`
 * because nothing is in the DB at the moment definition() runs.
 *
 * `createOneAtATime()` is provided for the rare case where you want
 * many factory rows that still chain correctly: it creates rows one
 * by one so each `chain()` call sees the previous insert. For most
 * tests, calling `factory()->create()` once per logical event is
 * cleaner.
 *
 * Helper states:
 *  - `granted()`            — the row is a grant (default).
 *  - `withdrawn()`          — the row is a withdrawal.
 *  - `forSubject($id)`      — pin the subject identifier.
 *  - `forPurpose($purpose)` — pin the purpose.
 *  - `version($version)`    — pin the consent text version.
 *  - `via($source)`         — pin the source (web / api / paper / ...).
 *  - `at($carbon)`          — backdate `occurred_at` to a specific instant.
 *
 * @extends Factory<ConsentEntry>
 */
class ConsentEntryFactory extends Factory
{
    /** @var class-string<ConsentEntry> */
    protected $model = ConsentEntry::class;

    /**
     * Create `$count` chained rows, inserting them one at a time so
     * each row's `previous_hash` correctly references the previous
     * row's `hash`.
     *
     * @return Collection<int, ConsentEntry>
     */
    public function createOneAtATime(int $count): Collection
    {
        /** @var Collection<int, ConsentEntry> $collection */
        $collection = new Collection;

        for ($i = 0; $i < $count; $i++) {
            /** @var ConsentEntry $entry */
            $entry = $this->create();
            $collection->push($entry);
        }

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = Carbon::instance($this->faker->dateTimeBetween('-1 year'));
        $occurredAtString = $occurredAt->utc()->format('Y-m-d H:i:s');

        $payload = [
            'subject_id' => (string) $this->faker->uuid(),
            'purpose' => $this->faker->randomElement(['newsletter', 'analytics', 'profiling', 'marketing']),
            'version' => '1',
            'action' => 'granted',
            'source' => $this->faker->randomElement(['web', 'api', 'paper', 'phone']),
            'metadata' => null,
            'occurred_at' => $occurredAtString,
        ];

        return $payload + $this->chain($payload);
    }

    /**
     * Pin the subject identifier.
     */
    public function forSubject(string $subjectId): self
    {
        return $this->state(function (array $attributes) use ($subjectId): array {
            $payload = $this->payloadFrom($attributes, ['subject_id' => $subjectId]);

            return ['subject_id' => $subjectId] + $this->chain($payload);
        });
    }

    /**
     * Pin the purpose.
     */
    public function forPurpose(string $purpose): self
    {
        return $this->state(function (array $attributes) use ($purpose): array {
            $payload = $this->payloadFrom($attributes, ['purpose' => $purpose]);

            return ['purpose' => $purpose] + $this->chain($payload);
        });
    }

    /**
     * Pin the consent-text version.
     */
    public function version(string $version): self
    {
        return $this->state(function (array $attributes) use ($version): array {
            $payload = $this->payloadFrom($attributes, ['version' => $version]);

            return ['version' => $version] + $this->chain($payload);
        });
    }

    /**
     * Pin the source (web, api, paper, phone, ...).
     */
    public function via(string $source): self
    {
        return $this->state(function (array $attributes) use ($source): array {
            $payload = $this->payloadFrom($attributes, ['source' => $source]);

            return ['source' => $source] + $this->chain($payload);
        });
    }

    /**
     * Backdate the event to a specific instant.
     */
    public function at(Carbon $occurredAt): self
    {
        return $this->state(function (array $attributes) use ($occurredAt): array {
            $occurredAtString = $occurredAt->utc()->format('Y-m-d H:i:s');
            $payload = $this->payloadFrom($attributes, ['occurred_at' => $occurredAtString]);

            return ['occurred_at' => $occurredAt] + $this->chain($payload);
        });
    }

    /**
     * Force the row to be a `granted` event (the default).
     */
    public function granted(): self
    {
        return $this->state(function (array $attributes): array {
            $payload = $this->payloadFrom($attributes, ['action' => 'granted']);

            return ['action' => 'granted'] + $this->chain($payload);
        });
    }

    /**
     * Force the row to be a `withdrawn` event.
     */
    public function withdrawn(): self
    {
        return $this->state(function (array $attributes): array {
            $payload = $this->payloadFrom($attributes, ['action' => 'withdrawn']);

            return ['action' => 'withdrawn'] + $this->chain($payload);
        });
    }

    /**
     * Compute previous_hash + hash by chaining onto the latest row
     * already in `consent_log`. The factory's outputs therefore form
     * a valid hash chain identical to what `RecordConsent` produces.
     *
     * @param  array<string, mixed>  $payload
     * @return array{previous_hash: string, hash: string}
     */
    private function chain(array $payload): array
    {
        /** @var ConsentEntry|null $previous */
        $previous = ConsentEntry::query()->orderByDesc('id')->first();
        $previousHash = $previous instanceof ConsentEntry ? $previous->hash : '';

        $secret = config('data-retention.log_secret');
        $hash = HashChain::compute(
            $payload,
            $previousHash,
            is_string($secret) ? $secret : '',
        );

        return ['previous_hash' => $previousHash, 'hash' => $hash];
    }

    /**
     * Merge attributes (which may still contain Carbon for occurred_at)
     * with state overrides into a chain-safe payload.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $overrides
     * @return array<string, scalar|null>
     */
    private function payloadFrom(array $attributes, array $overrides): array
    {
        $merged = array_merge($attributes, $overrides);

        $occurredAt = $merged['occurred_at'] ?? null;
        if ($occurredAt instanceof Carbon) {
            $merged['occurred_at'] = $occurredAt->utc()->format('Y-m-d H:i:s');
        }

        // Hash payload must not include previous_hash / hash themselves.
        unset($merged['previous_hash'], $merged['hash']);

        /** @var array<string, scalar|null> $merged */
        return $merged;
    }
}
