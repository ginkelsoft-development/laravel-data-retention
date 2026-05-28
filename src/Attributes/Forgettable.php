<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Attributes;

use Attribute;

/**
 * Declares that an Eloquent model participates in GDPR art. 17
 * ("right to be forgotten") sweeps for a given subject.
 *
 * Place the attribute on the class. Per-field anonymize strategies
 * cannot be expressed in an attribute (PHP attributes don't accept
 * closures), so models that need `anonymize` should declare the
 * full policy via the `$forgettable` property instead.
 *
 * Example (delete the user record itself when forgetting):
 *
 *   #[Forgettable(column: 'id', action: 'delete')]
 *   class User extends Model { use Forgettable; }
 *
 * Example (delete related orders when forgetting the user):
 *
 *   #[Forgettable(column: 'user_id', action: 'delete')]
 *   class Order extends Model { use Forgettable; }
 *
 * Example (anonymize a profile that other tables still link to):
 *
 *   class Profile extends Model {
 *       use Forgettable;
 *
 *       protected array $forgettable = [
 *           'column' => 'user_id',
 *           'action' => 'anonymize',
 *           'anonymize' => [
 *               'first_name' => 'placeholder',
 *               'last_name'  => 'placeholder',
 *               'email'      => 'hash',
 *           ],
 *       ];
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Forgettable
{
    /**
     * @param  string  $column  Column on this model that points to the
     *                          subject identifier. Defaults to `user_id`.
     * @param  string  $action  Either `delete` or `anonymize`.
     */
    public function __construct(
        public string $column = 'user_id',
        public string $action = 'delete',
    ) {}

    /**
     * @return array{column: string, action: string}
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'action' => $this->action,
        ];
    }
}
