<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Attributes\Forgettable;
use Ginkelsoft\DataRetention\Concerns\Forgettable as ForgettableTrait;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model that overrides `forSubjectQuery` because its link to the
 * subject is not a simple `column = subject` predicate. Here the
 * subject can appear either as `reporter_id` OR as `assignee_id`.
 */
#[Forgettable(column: 'reporter_id', action: 'delete')]
class ForgetTicket extends Model implements ForgettableContract
{
    use ForgettableTrait;

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
}
