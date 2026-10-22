<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Factory;

use Prism\TestPack\Factory\Factory;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Book;

/**
 * @extends Factory<Book>
 */
final class BookFactory extends Factory
{
    protected static function class(): string
    {
        return Book::class;
    }

    protected function defaults(): array
    {
        return ['author' => AuthorFactory::new(), 'title' => static fn (int $n): string => 'Book ' . $n, 'note' => null];
    }
}
