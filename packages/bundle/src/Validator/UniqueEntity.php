<?php

declare(strict_types=1);

namespace Prism\Bundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Aucune autre entité de la même classe n'a ces valeurs : fields est un champ ou une relation
 * ToOne, seul ou combiné. Une valeur nulle ne compte pas (ignoreNull), comme une contrainte
 * d'unicité en base.
 *
 * #[UniqueEntity(fields: ['email'])]
 * #[UniqueEntity(fields: ['company', 'code'], errorPath: 'code')]
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class UniqueEntity extends Constraint
{
    public const string NOT_UNIQUE_ERROR = 'e3b2a9a6-6f2c-4a5e-9f45-3c8d1f0b7d21';

    protected const ERROR_NAMES = [self::NOT_UNIQUE_ERROR => 'NOT_UNIQUE_ERROR'];

    /** @var list<string> */
    public array $fields;

    /**
     * @param list<string>|string $fields
     * @param list<string>|null   $groups
     */
    #[HasNamedArguments]
    public function __construct(
        array|string $fields,
        public string $message = 'This value is already used.',
        public ?string $errorPath = null,
        public bool $ignoreNull = true,
        public ?string $entityManager = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
        $this->fields = \is_string($fields) ? [$fields] : $fields;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
