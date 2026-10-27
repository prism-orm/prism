# Multi-company ERP

This recipe serves several companies from one application and one database: each company reaches the ERP on its own subdomain and sees only its own customers, orders and invoices, while the product catalog is partly shared. It is the architecture of the demo application, where Acme and Globex share one database.

## The company registry

The companies themselves are not tenanted: they are the list of tenants, read before a request knows its tenant.

```php no-run
#[Prism\Entity]
final class Company
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    // the subdomain: acme.example.com
    #[Prism\Column(length: 40, unique: true)]
    public string $slug;

    #[Prism\Column(length: 120)]
    public string $name;
}
```

Everything that belongs to a company is `#[Prism\Tenanted]`: customers, orders, order lines, invoices. The tenant identifier is the company's `id`.

## One subdomain per company

```yaml
# config/packages/prism.yaml
prism:
    tenancy:
        resolver:
            subdomain: example.com
        loader: App\Tenancy\CompanyLoader
```

The resolver reads `acme` in `acme.example.com`, before the firewall. The loader turns that name into the company's identifier:

```php
<?php

namespace App\Tenancy;

use App\Entity\Company_;
use Prism\Bundle\Tenancy\TenantLoader;
use Prism\Orm\EntityManagerInterface;

final readonly class CompanyLoader implements TenantLoader
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function load(string $identifier): ?int
    {
        $c = Company_::as('c');

        return $this->em->query($c)->where($c->slug->eq($identifier))->getOneOrNull()?->id;
    }
}
```

An unknown subdomain has no tenant. Its pages read nothing: the first query on a tenanted entity throws `MissingTenantException`. Turn it into a 404:

```php
<?php

namespace App\EventSubscriber;

use Prism\Orm\Exception\MissingTenantException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsEventListener]
final class UnknownCompanySubscriber
{
    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof MissingTenantException) {
            $event->setThrowable(new NotFoundHttpException('Unknown company.', $event->getThrowable()));
        }
    }
}
```

With a user per company, the `user: true` resolver is an alternative: the logged-in user, a `TenantAwareUserInterface`, carries the company. The user entity is then not tenanted, since the firewall loads it before the tenant is known.

## A shared catalog

The products are a mixed reference table: 30 products shared by every company, plus a few of each company's own.

```php no-run
#[Prism\Entity]
#[Prism\Tenanted(global: true)]
final class Product
{
    // ...
}
```

A product without a tenant is shared: every company sees it and can order it, and only the system mode can change it. A company's own products follow the usual rules:

```php
$p = Product_::as('p');

$visible = $em->query($p)->orderBy($p->sku->asc())->getResult(); // the shared catalog and Acme's products
$own = $em->getRepository(Product::class)->findBy(['sku' => ['ACME-1', 'ACME-2']]);
```

Order lines of every company point to the shared products: a relation from a tenanted entity to a shared row is allowed, a relation to another company's row is refused at flush.

## Creating a company

A new company is a row in the registry, then its first data, written as that company:

```php
$company = new Company();
$company->slug = 'initech';
$company->name = 'Initech';
$em->persist($company);
$em->flush();

$tenants->runAs($company->id, function () use ($em): void {
    $customer = new Customer();
    $customer->name = 'First customer';
    $customer->email = 'first@initech.example.test';
    $em->persist($customer);
    $em->flush();
});
```

`runAs()` clears the entity manager when it enters and when it leaves: flush inside the callback, and load again what you need after it.

## Reports across companies

An administrator's dashboard reads every company, explicitly:

```php
$companies = $em->getRepository(Company::class)->findAll();

$orders = [];
foreach ($companies as $company) {
    $orders[$company->name] = $tenants->runAs($company->id, fn (): int => $em->getRepository(Order::class)->count());
}

$total = $tenants->runAsSystem(fn (): int => $em->getRepository(Order::class)->count());
```

The `db:*` commands run in system mode: they administer the whole database. A data migration that changes rows of every company writes inside a `runAs()` per company, so that each row keeps its tenant.

## Testing the isolation

Test that a company never sees another's data, with real requests on both subdomains. The demo does it in `tests/Controller/TenancyTest.php`:

```php no-run
public function testEachCompanySeesOnlyItsOrders(): void
{
    $client = static::createClient();                   // acme.localhost by default
    $acme = OrderFactory::new(['reference' => 'ORD-ACME'])->create();
    self::company('globex');
    OrderFactory::new(['reference' => 'ORD-GLOBEX'])->create();

    $client->request('GET', '/orders', server: ['HTTP_HOST' => 'globex.localhost']);
    self::assertSelectorTextContains('tbody', 'ORD-GLOBEX');
    self::assertSelectorTextNotContains('tbody', 'ORD-ACME');

    // Acme's order does not exist for Globex
    $client->request('GET', '/orders/' . $acme->id, server: ['HTTP_HOST' => 'globex.localhost']);
    self::assertResponseStatusCodeSame(404);
}
```

`company()` is a helper of the demo's test case that switches the tenant used by the factories.

Raw SQL is covered too: in tests, the tenant guard throws on any query on a tenanted table without a tenant condition, so a forgotten filter fails the test suite instead of leaking in production.

In a worker (FrankenPHP, RoadRunner), the tenant and the entity manager are reset between two requests. The demo serves 10,000 requests from one worker, alternating companies, without memory growth.
