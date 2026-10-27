<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Data\FindBy;

use Prism\Dbal\Query\Order;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\PHPStan\Tests\Fixtures\Client;
use Prism\PHPStan\Tests\Fixtures\Invoice;
use Prism\PHPStan\Tests\Fixtures\Status;

function criteria(EntityManagerInterface $em, Client $client): void
{
    $invoices = $em->getRepository(Invoice::class);

    $invoices->findBy(['reference' => 'F-1']);                       // champ
    $invoices->findBy(['billing.city' => 'Lyon']);                   // champ d'embeddable
    $invoices->findBy(['client' => $client, 'status' => Status::Paid]); // relation ToOne possédée
    $invoices->findBy(['refrence' => 'F-1']);                        // faute de frappe
    $invoices->findBy(['billing.town' => 'Lyon']);                   // champ d'embeddable inconnu
    $invoices->findBy(['amount' => '1.00']);                         // inconnu, rien de proche
    $invoices->findOneBy(['issuedAt' => new \DateTimeImmutable(), 'paidat' => null]);
    $invoices->count(['statut' => Status::Paid]);
    foreach ($invoices->iterate(['clients' => $client]) as $invoice) {
    }
}

function relations(EntityManagerInterface $em): void
{
    $clients = $em->getRepository(Client::class);

    $clients->findBy(['invoices' => 1]);                             // collection
    $clients->findBy(['contract' => 1]);                             // côté inverse
    $clients->findBy(['nickname' => 'x']);                           // non mappé (RG-02.7)
}

function order(EntityManagerInterface $em): void
{
    $invoices = $em->getRepository(Invoice::class);

    $invoices->findBy([], ['issuedAt' => Order::Desc, 'client' => Order::Asc]);
    $invoices->findBy([], ['totl' => Order::Desc]);
    $invoices->findBy([], ['total' => 'DESC']);
    $invoices->findOneBy(['status' => Status::Paid], orderBy: ['reference' => Order::Asc, 'shipping.zip' => Order::Asc]);
    $invoices->count(['reference' => 'x'], ['ignored' => 'count() has no order']);
}

function namedAndDynamic(EntityManagerInterface $em, string $field): void
{
    $invoices = $em->getRepository(Invoice::class);

    $invoices->findBy(criteria: ['totl' => '1.00']);                // argument nommé
    $invoices->findBy([$field => 1]);                                // clé dynamique : rien à dire
    $invoices->findBy(...[['totl' => 1]]);                           // argument déplié : rien à dire
    $em->getRepository(Customer::class)->findBy(['name' => 'Ada', 'nme' => 'Ada']); // autre entité, repository générique
}

function nearest(EntityManagerInterface $em, string $field): void
{
    $docs = $em->getRepository(\Prism\Orm\Tests\Functional\VectorFixtures\Doc::class);

    $docs->nearest('embedding', [1.0, 0.0, 0.0]);                     // Vector
    $docs->nearest('raw', [1.0, 0.0, 0.0], criteria: ['title' => 'x']); // list<float>
    $docs->nearest('title', [1.0]);                                   // pas un vecteur
    $docs->nearest('embeding', [1.0]);                                // inconnu
    $docs->nearest('embedding', [1.0], 5, ['titel' => 'x']);          // critère inconnu
    $docs->nearest($field, [1.0]);                                    // champ dynamique : rien à dire
    $em->getRepository(Invoice::class)->nearest('total', [1.0]);      // entité sans vecteur
}
