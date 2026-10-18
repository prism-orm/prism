<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;

/**
 * Nom de la table et options propres à la plateforme (engine, charset, collation).
 *
 * Sans cet attribut, le nom est déduit par la NamingStrategy (OrderLine → order_line).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table implements MappingAttribute
{
    private const array ALLOWED_OPTIONS = ['engine', 'charset', 'collation', 'comment'];

    public readonly ?string $name;

    /** @var array<string, string> */
    public readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(?string $name = null, array $options = [])
    {
        $this->name = AttributeGuard::name('Table', 'name', $name);

        foreach (array_keys($options) as $option) {
            if (!\in_array($option, self::ALLOWED_OPTIONS, true)) {
                throw MappingException::invalidAttributeArgument('Table', 'options', \sprintf('unknown option "%s", allowed: %s.', $option, implode(', ', self::ALLOWED_OPTIONS)));
            }
        }
        $this->options = $options;
    }
}
