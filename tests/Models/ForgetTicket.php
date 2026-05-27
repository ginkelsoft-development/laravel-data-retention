<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Ginkelsoft\DataRetention\Database\Factories\ForgetTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model that overrides `forSubjectQuery` because its link to the
 * subject is not a simple `column = subject` predicate. Here the
 * subject can appear either as `reporter_id` OR as `assignee_id`.
 *
 * @method static ForgetTicketFactory factory(...$arguments)
 */
#[Forgettable(column: 'reporter_id', action: 'delete')]
class ForgetTicket extends Model implements ForgettableContract
{
    use ForgettableTrait;

    /** @use HasFactory<ForgetTicketFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'forget_tickets';

    /** @var list<string> */
    protected $fillable = ['reporter_id', 'assignee_id', 'subject'];

    /**
     * Override: match the subject in either of two columns.
     *
     * @return Builder<static>
     */
    public static function forSubjectQuery(string $subject): Builder
    {
        /** @var Builder<static> $query */
        $query = static::query();

        return $query->where(function (Builder $q) use ($subject): void {
            $q->where('reporter_id', '=', $subject)
                ->orWhere('assignee_id', '=', $subject);
        });
    }

    /**
     * @return Factory<ForgetTicket>
     */
    protected static function newFactory(): Factory
    {
        return ForgetTicketFactory::new();
    }
}
