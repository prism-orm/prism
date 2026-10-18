<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Index plein texte utilisé par hybridSearch() (pilier 4). Répétable.
 *
 * FULLTEXT sous MySQL et MariaDB ; colonne tsvector générée + index GIN sous PostgreSQL,
 * avec la configuration de langue indiquée (french, english, simple…).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class FullTextIndex implements MappingAttribute
{
    /** @var list<string> */
    public readonly array $fields;

    public readonly string $language;

    public readonly ?string $name;

    /**
     * @param list<string> $fields
     */
    public function __construct(array $fields, string $language = 'simple', ?string $name = null)
    {
        $this->fields = AttributeGuard::fieldList('FullTextIndex', 'fields', $fields);
        $this->language = (string) AttributeGuard::name('FullTextIndex', 'language', $language);
        $this->name = AttributeGuard::name('FullTextIndex', 'name', $name);
    }
}
