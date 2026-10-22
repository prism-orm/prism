<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Factory;

use Prism\TestPack\Factory\Factory;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Author;

/**
 * @extends Factory<Author>
 */
final class AuthorFactory extends Factory
{
    protected static function class(): string
    {
        return Author::class;
    }

    protected function defaults(): array
    {
        return ['name' => static fn (int $n): string => 'Author ' . $n];
    }
}
