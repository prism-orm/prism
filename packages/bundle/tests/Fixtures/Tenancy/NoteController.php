<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Tenancy;

use Prism\Dbal\Query\Order;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class NoteController
{
    // les titres des notes du tenant de la requête, puis leur nombre tous tenants confondus
    public function list(EntityManagerInterface $em, TenantContext $context): Response
    {
        try {
            $titles = array_map(static fn (Note $note): string => $note->title, $em->getRepository(Note::class)->findBy([], ['title' => Order::Asc]));
        } catch (MissingTenantException) {
            return new Response('no tenant');
        }
        $all = $context->runAsSystem(static fn (): int => $em->getRepository(Note::class)->count());

        return new Response(implode(',', $titles) . ' / ' . $all);
    }
}
