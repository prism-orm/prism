<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Controller;

use Prism\Orm\EntityManagerInterface;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Author;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthorController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function count(): Response
    {
        return new Response((string) $this->em->getRepository(Author::class)->count());
    }

    // une écriture de l'application, avec son flush transactionnel
    public function add(): Response
    {
        $this->em->persist(new Author('Added'));
        $this->em->flush();

        return new Response('', 201);
    }
}
