<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Factory;

use Prism\TestPack\Factory\Factory;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Author;

/**
 * Sans valeur pour l'argument obligatoire du constructeur.
 *
 * @extends Factory<Author>
 */
final class NamelessAuthorFactory extends Factory
{
    protected static function class(): string
    {
        return Author::class;
    }

    protected function defaults(): array
    {
        return [];
    }
}
