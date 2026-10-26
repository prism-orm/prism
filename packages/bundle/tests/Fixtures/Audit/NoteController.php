<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Audit;

use Prism\Orm\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class NoteController
{
    public function create(EntityManagerInterface $em): Response
    {
        $em->persist($note = new Note('hello'));
        $em->flush();

        return new Response((string) $note->id);
    }
}
