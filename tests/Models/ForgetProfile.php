<?php

declare(strict_types=1);

namespace Ginkelsoft\DataRetention\Tests\Models;

use Ginkelsoft\DataRetention\Concerns\Exportable;
use Ginkelsoft\DataRetention\Concerns\Forgettable;
use Ginkelsoft\DataRetention\Contracts\Exportable as ExportableContract;
use Ginkelsoft\DataRetention\Contracts\Forgettable as ForgettableContract;
use Ginkelsoft\DataRetention\Database\Factories\ForgetProfileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test model carrying two AVG policies:
 *  - Forgettable: three fields are anonymized when the linked subject
 *    is forgotten.
 *  - Exportable: a different, explicit list of fields is included in
 *    a subject access export. `internal_note` is deliberately omitted
 *    from the export to demonstrate that the field list is opt-in.
 *
 * @method static ForgetProfileFactory factory(...$arguments)
 */
class ForgetProfile extends Model implements ExportableContract, ForgettableContract
{
    use Exportable, Forgettable {
        Forgettable::forSubjectQuery insteadof Exportable;
    }

    /** @use HasFactory<ForgetProfileFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'forget_profiles';

    /** @var list<string> */
    protected $fillable = ['user_id', 'first_name', 'last_name', 'email', 'internal_note'];

    /** @var array<string, mixed> */
    protected $forgettable = [
        'column' => 'user_id',
        'action' => 'anonymize',
        'anonymize' => [
            'first_name' => 'placeholder',
            'last_name' => 'placeholder',
            'email' => 'hash',
        ],
    ];

    /** @var array<string, mixed> */
    protected $exportable = [
        'column' => 'user_id',
        'fields' => [
            'first_name' => 'Voornaam',
            'last_name' => 'Achternaam',
            'email' => ['label' => 'E-mailadres'],
            // 'internal_note' is intentionally absent: internal field, not exported.
        ],
    ];

    /**
     * @return Factory<ForgetProfile>
     */
    protected static function newFactory(): Factory
    {
        return ForgetProfileFactory::new();
    }
}
