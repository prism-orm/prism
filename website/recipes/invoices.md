---
examples: sequence
---

# Invoices

This recipe issues invoices from orders, computes their amounts without rounding errors, follows their payment and keeps their history. It uses the `Order`, `OrderLine` and `Invoice` entities of the example ERP; the examples of the page run one after the other, as Acme.

## The model

```php no-run
#[Prism\Entity(repositoryClass: InvoiceRepository::class)]
#[Prism\Tenanted]
#[Prism\Auditable]
#[UniqueEntity(fields: ['number'])]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20, unique: true)]
    public string $number;

    #[Prism\Column]
    public \DateTimeImmutable $issuedAt;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total;

    #[Prism\Column]
    public bool $paid;

    #[Prism\OneToOne(inversedBy: 'invoice')]
    public Order $order;
}
```

Each choice has a reason:

- **`#[Prism\Tenanted]`**: each company sees only its invoices, and `unique: true` makes the number unique *per company*: two companies can both have an `INV-00001`.
- **`#[Prism\Auditable]`**: an invoice is a legal document. Every change, the payment included, is recorded with its author and date, in the same transaction.
- **`decimal` as `string`**: the total is exact. It is never a `float`, which would turn `0.1 + 0.2` into `0.30000000000000004`.
- **`OneToOne` to the order**: an order has one invoice at most, enforced by a unique foreign key.

## Exact amounts

PHP has no decimal type. Compute in integer cents, and convert at the edges:

```php
final class Cents
{
    public static function of(string $amount): int
    {
        [$units, $decimals] = explode('.', $amount . '.');

        return (int) $units * 100 + ('-' === $amount[0] ? -1 : 1) * (int) str_pad(substr($decimals, 0, 2), 2, '0');
    }

    public static function format(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
```

The order total is the sum of its lines, each `quantity × unitPrice`:

```php
$order = $em->getRepository(Order::class)->findOneBy(['reference' => 'ORD-00001']);

$cents = 0;
foreach ($order->lines as $line) {
    $cents += $line->quantity * Cents::of($line->unitPrice);
}
$total = Cents::format($cents); // "1234.50", a string
```

The `bcmath` extension (`bcadd()`, `bcmul()`) works on the decimal strings directly, if you prefer it.

## Orders to invoice

The orders without an invoice: a left join on the inverse side of the `OneToOne`, kept when nothing matches:

```php
$o = Order_::as('o');
$i = $o->invoice->as('i');

$toInvoice = $em->query($o)
    ->leftJoin($i)
    ->where($i->id->isNull())
    ->orderBy($o->placedAt->asc())
    ->getResult();
```

## Issuing an invoice

Numbers follow each other per company. Read the last one and increment it, in the transaction that inserts the invoice:

```php
use Prism\Dbal\Query\Order as Sort;

$order = $toInvoice[0];

$invoice = $em->wrapInTransaction(function () use ($em, $order): Invoice {
    $last = $em->getRepository(Invoice::class)->findOneBy([], ['number' => Sort::Desc]);
    $next = null === $last ? 1 : (int) substr($last->number, 4) + 1;

    $cents = 0;
    foreach ($order->lines as $line) {
        $cents += $line->quantity * Cents::of($line->unitPrice);
    }

    $invoice = new Invoice();
    $invoice->number = sprintf('INV-%05d', $next);
    $invoice->issuedAt = new \DateTimeImmutable();
    $invoice->total = Cents::format($cents);
    $invoice->paid = false;
    $invoice->order = $order;
    $em->persist($invoice);

    return $invoice;
});

echo $invoice->number, ' ', $invoice->total, "\n";
```

Two users issuing invoices at the same instant could read the same last number. The unique constraint then refuses the second `INSERT`, and its transaction is rolled back: catch the `QueryFailedException` and try again. Under high volume, keep a counter row per company and lock it with `LockMode::PessimisticWrite` inside the transaction instead.

In Symfony, validate the invoice before saving it: `#[UniqueEntity(fields: ['number'])]` turns a duplicate number into a form error, checked within the current company.

## Payments

```php
$invoice->paid = true;
$em->flush();
```

The audit log records the change. To show the history of an invoice:

```php
use Prism\Orm\Audit\AuditReader;

foreach (new AuditReader($em)->history(Invoice::class, $invoice->id) as $entry) {
    printf("%s  %-7s %s\n", $entry->occurredAt->format('Y-m-d H:i'), $entry->action, json_encode($entry->changes));
}
```

`stateAt()` answers "what did this invoice look like on December 31st?", replayed from the log.

## Reports

Unpaid invoices, oldest first:

```php
$i = Invoice_::as('i');

$unpaid = $em->query($i)
    ->where($i->paid->eq(false))
    ->orderBy($i->issuedAt->asc())
    ->paginate(page: 1, perPage: 50);
```

Revenue per customer, computed by the database into a DTO:

```php
final readonly class CustomerBalance
{
    public function __construct(
        public string $customer,
        public int $invoices,
        public ?string $invoiced,
        public ?\DateTimeImmutable $lastInvoice,
    ) {
    }
}

$i = Invoice_::as('i');
$o = $i->order->as('o');
$c = $o->customer->as('c');

$balances = $em->query($i)
    ->join($o)
    ->join($c)
    ->groupBy($c->id, $c->name)
    ->orderBy($i->total->sum()->desc())
    ->selectInto(CustomerBalance::class, customer: $c->name, invoices: $i->id->count(), invoiced: $i->total->sum(), lastInvoice: $i->issuedAt->max())
    ->getResult(); // list<CustomerBalance>
```

The sum is a `decimal`, so a string, and nullable like every `SUM()`. In the demo, this report is the `/reports/revenue` page.
