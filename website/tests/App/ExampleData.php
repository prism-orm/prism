<?php

declare(strict_types=1);

namespace App;

use App\Entity\Company;
use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\OrderLine;
use App\Entity\Product;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Tenancy\TenantContext;

/**
 * Les données des exemples de la documentation (ADR 0023) : deux sociétés (acme et globex), un
 * catalogue commun, et pour chaque société ses clients, commandes et leurs lignes, factures pour
 * une partie des commandes. Chaque société s'écrit dans son runAs() (pilier 3). Déterministe
 * (mt_srand) : les exemples voient toujours les mêmes lignes.
 */
final readonly class ExampleData
{
    private const array CITIES = ['Paris', 'Lyon', 'Marseille', 'Lille', 'Nantes', 'Bordeaux', 'Toulouse'];

    private const array COMPANIES = ['acme' => 'Acme Industries', 'globex' => 'Globex Corporation'];

    public function __construct(private EntityManagerInterface $em, private TenantContext $tenants)
    {
    }

    public function fill(int $customers, int $orders): void
    {
        mt_srand(42);

        // le catalogue commun : des lignes sans tenant, écrites en mode système
        $catalog = $this->tenants->runAsSystem(function (): array {
            $products = [];
            for ($i = 1; $i <= 30; ++$i) {
                $products[] = $this->product(\sprintf('SKU-%03d', $i), 'Product ' . $i);
            }
            $this->em->flush();

            return array_map(static fn (Product $p): int => (int) $p->id, $products);
        });

        foreach (self::COMPANIES as $slug => $name) {
            $company = new Company();
            $company->slug = $slug;
            $company->name = $name;
            $this->em->persist($company);
            $this->em->flush();
            $this->tenants->runAs((int) $company->id, fn () => $this->company($slug, $catalog, $customers, $orders));
        }
    }

    private function product(string $sku, string $name): Product
    {
        $product = new Product();
        $product->sku = $sku;
        $product->name = $name;
        $product->price = Money::fromCents(mt_rand(500, 50_000));
        $this->em->persist($product);

        return $product;
    }

    /**
     * Les données d'une société, dans son tenant : deux produits à elle, puis clients, commandes,
     * lignes et factures.
     *
     * @param list<int> $catalog
     */
    private function company(string $slug, array $catalog, int $customers, int $orders): void
    {
        $own = [$this->product(strtoupper($slug) . '-1', ucfirst($slug) . ' service'), $this->product(strtoupper($slug) . '-2', ucfirst($slug) . ' support')];
        $people = [];
        for ($i = 1; $i <= $customers; ++$i) {
            $customer = new Customer();
            $customer->name = 'Customer ' . $i;
            $customer->email = \sprintf('customer%d@%s.example.test', $i, $slug);
            $customer->city = self::CITIES[$i % \count(self::CITIES)];
            $this->em->persist($customer);
            $people[] = $customer;
        }
        $this->em->flush();
        $products = [...array_map(fn (int $id): Product => $this->em->getReference(Product::class, $id), $catalog), ...$own];

        $start = new \DateTimeImmutable('2026-01-01 09:00:00');
        for ($i = 1; $i <= $orders; ++$i) {
            $order = new Order();
            $order->reference = \sprintf('ORD-%05d', $i);
            $order->placedAt = $start->modify(\sprintf('+%d hours', $i * 7));
            $order->status = 0 === $i % 3 ? 'shipped' : 'open';
            $order->customer = $people[mt_rand(0, \count($people) - 1)];
            $this->em->persist($order);
            for ($n = mt_rand(1, 5); $n > 0; --$n) {
                $line = new OrderLine();
                $line->order = $order;
                $line->product = $products[mt_rand(0, \count($products) - 1)];
                $line->quantity = mt_rand(1, 10);
                $line->unitPrice = $line->product->price;
                $order->lines->add($line);
                $this->em->persist($line);
            }
            if (0 === $i % 3) {
                $invoice = new Invoice();
                $invoice->number = \sprintf('INV-%05d', $i);
                $invoice->issuedAt = $order->placedAt->modify('+1 day');
                $invoice->total = $order->total();
                $invoice->paid = 0 === $i % 2;
                $invoice->order = $order;
                $this->em->persist($invoice);
            }
        }
        $this->em->flush();
        $this->em->clear();
    }
}
