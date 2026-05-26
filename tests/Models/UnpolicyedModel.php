<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Concerns\HasRetention;
use Illuminate\Database\Eloquent\Model;

/**
 * Uses the trait but declares no policy — the resolver must
 * return null without throwing.
 */
class UnpolicyedModel extends Model
{
    use HasRetention;

    /** @var string */
    protected $table = 'unpolicyed_models';
}
