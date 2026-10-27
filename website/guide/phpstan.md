# PHPStan

Prism's PHPStan extension checks at analysis time what Prism would otherwise check at run time: the fields of your repository calls, the DTOs of your projections, the type of your repositories and the properties that Prism writes for you.

## Install

```bash
composer require --dev prism-orm/phpstan phpstan/extension-installer
```

`phpstan/extension-installer` loads the extension. The extension reads your mapping and the generated metamodel; tell it where the metamodel is when it is not in `var/cache/prism/metamodel`:

```neon
# phpstan.neon
parameters:
    prism:
        metamodelDir: %currentWorkingDirectory%/var/cache/dev/prism/metamodel/default
```

In Symfony, the metamodel of each entity manager is in `var/cache/<env>/prism/metamodel/<entity manager>`, generated when the cache warms up: run `bin/console cache:warmup` before PHPStan in your CI.

## What it reports

Given this code, with the demo's `Customer` entity and a `CustomerRevenue` DTO whose constructor takes `string $customer, int $invoices, string $total, ?\DateTimeImmutable $lastInvoice`:

```php no-run
$this->em->getRepository(Customer::class)->findBy(['emial' => 'a@b.c']);
$this->em->getRepository(Customer::class)->findBy(['orders' => 3]);

$i = Invoice_::as('i');
$this->em->query($i)
    ->selectInto(CustomerRevenue::class, customer: $i->number, invoices: $i->total->sum(), total: $i->total->sum())
    ->getResult();
```

PHPStan reports:

```console
$ vendor/bin/phpstan analyse
 ------ ----------------------------------------------------------------------
  Line   Report.php
 ------ ----------------------------------------------------------------------
  19     Unknown field App\Entity\Customer::$emial in the criteria of
         findBy(). Did you mean "email"?
         🪪  prism.findBy.unknownField
  20     App\Entity\Customer::$orders is a collection: findBy() compares only
         fields and owning ToOne relations; join it with the typed query
         builder instead.
         🪪  prism.findBy.relation
  23     The parameter $lastInvoice of
         App\Report\CustomerRevenue::__construct() is required but not
         selected: select it as lastInvoice: ...
         🪪  prism.selectInto.missingParameter
  24     The selection "invoices" is string|null, but $invoices of
         App\Report\CustomerRevenue::__construct() accepts int.
         🪪  prism.selectInto.typeMismatch
  24     The selection "total" is string|null, but $total of
         App\Report\CustomerRevenue::__construct() accepts string.
         🪪  prism.selectInto.typeMismatch
 ------ ----------------------------------------------------------------------

 [ERROR] Found 5 errors
```

The last error is about `NULL`: `SUM()` of an empty set is `NULL`, so a sum is typed `string|null`, and the parameter that receives it must be `?string`.

| Check | Errors |
| --- | --- |
| The criteria and order keys of `findBy()`, `findOneBy()`, `count()` and `iterate()` are fields of the entity, with a suggestion for a typo | `prism.findBy.unknownField` |
| A criterion is a field or an owning `ManyToOne` / `OneToOne`, not a collection | `prism.findBy.relation` |
| An order value is a `Prism\Dbal\Query\Order` case | `prism.findBy.invalidOrder` |
| Every selection of `selectInto()` is named after a constructor parameter | `prism.selectInto.unnamed`, `prism.selectInto.unknownParameter` |
| Every required parameter is selected | `prism.selectInto.missingParameter` |
| Every selection has a type that its parameter accepts | `prism.selectInto.typeMismatch` |

The typed query builder needs no rule of its own: its fields are typed PHP methods, so `$o->status->eq(42)` or `$i->total->gt(100)` are plain type errors for PHPStan.

## Types it infers

- `$em->getRepository(Invoice::class)` returns the `repositoryClass` of the entity, `InvoiceRepository`, so its own methods are known without a `@var`. Without a declared repository, it is `EntityRepository<Invoice>`, whose `find()` returns `?Invoice` and `findBy()` returns `list<Invoice>`.
- `$em->query($o)->getResult()` returns `list<Order>`, `getOneOrNull()` returns `?Order`, and `selectInto(Dto::class, ...)->getResult()` returns `list<Dto>`.
- The mapped properties of an entity are written by Prism when it loads the entity, without the constructor. PHPStan does not report them as uninitialized, never written or never read. Properties that are not mapped are still checked.
