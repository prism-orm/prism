# Stock

This recipe keeps stock levels right when several users and processes move goods at the same time: an append-only journal of movements, a level per product kept in step with it, and locks that prevent two orders from taking the same last item.

The example ERP has no stock tables. The entities below are checked against its mapping, and the query on order lines runs on its data; the operations on movements are shown without being run.

## Movements: the journal

Every change of stock is a movement, never an update of a quantity:

```php
enum MovementReason: string
{
    case Receipt = 'receipt';
    case Shipment = 'shipment';
    case Adjustment = 'adjustment';
}

#[Prism\Entity(readOnly: true)]
#[Prism\Tenanted]
#[Prism\Timestampable(updatedAt: null)]
#[Prism\Blameable(updatedBy: null)]
#[Prism\Index(fields: ['product', 'createdAt'])]
final class StockMovement
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\ManyToOne]
    public Product $product;

    // positive in, negative out
    #[Prism\Column]
    public int $quantity;

    #[Prism\Column(length: 20)]
    public MovementReason $reason;

    #[Prism\Column(length: 40)]
    public ?string $reference = null;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    #[Prism\Column(length: 180)]
    public ?string $createdBy = null;
}
```

- **`readOnly: true`**: a movement is inserted, never updated. Prism does not even compare it at flush. A mistake is corrected by another movement, an `Adjustment`.
- **`Timestampable` and `Blameable` without update fields**: when and by whom, set at insert.
- **The index on `product` and `createdAt`**: on a `#[Tenanted]` entity, it becomes `(tenant_id, product_id, created_at)`, the order of the queries below.

## The level: sum or cache

The stock of a product is the sum of its movements, which a projection computes:

```php no-run
final readonly class StockLine
{
    public function __construct(public string $sku, public ?int $quantity)
    {
    }
}

$m = StockMovement_::as('m');
$p = $m->product->as('p');

$levels = $em->query($m)
    ->join($p)
    ->groupBy($p->id, $p->sku)
    ->selectInto(StockLine::class, sku: $p->sku, quantity: $m->quantity->sum())
    ->getResult();
```

The same aggregate runs on the order lines of the example ERP, the quantities sold per product:

```php
final readonly class SoldQuantity
{
    public function __construct(public string $sku, public ?int $sold)
    {
    }
}

$l = OrderLine_::as('l');
$p = $l->product->as('p');

$sold = $em->query($l)
    ->join($p)
    ->groupBy($p->id, $p->sku)
    ->orderBy($l->quantity->sum()->desc())
    ->limit(10)
    ->selectInto(SoldQuantity::class, sku: $p->sku, sold: $l->quantity->sum())
    ->getResult();
```

A sum over the whole journal is exact, but grows with it. When the level is read often, keep it in a row per product, updated in the transaction that inserts the movement:

```php
#[Prism\Entity]
#[Prism\Tenanted]
#[Prism\UniqueConstraint(fields: ['product'])]
final class StockLevel
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\ManyToOne]
    public Product $product;

    #[Prism\Column]
    public int $quantity = 0;

    #[Prism\Version, Prism\Column]
    public int $version = 1;
}
```

The journal stays the truth: the level can always be rebuilt from it.

## Moving goods

```php no-run
use Prism\Dbal\LockMode;

function ship(EntityManagerInterface $em, Product $product, int $quantity, string $reference): void
{
    $em->wrapInTransaction(function () use ($em, $product, $quantity, $reference): void {
        $level = $em->getRepository(StockLevel::class)->findOneBy(['product' => $product]);
        $em->lock($level, LockMode::PessimisticWrite); // SELECT ... FOR UPDATE

        if ($level->quantity < $quantity) {
            throw new OutOfStock($product, $level->quantity);
        }

        $movement = new StockMovement();
        $movement->product = $product;
        $movement->quantity = -$quantity;
        $movement->reason = MovementReason::Shipment;
        $movement->reference = $reference;
        $em->persist($movement);

        $level->quantity -= $quantity;
    });
}
```

The pessimistic lock makes a second shipment of the same product wait until the first one commits, then read the new quantity: two orders can never both take the last item. The movement and the level are written in one transaction, so they never disagree.

Without the lock, the `#[Prism\Version]` field still protects the level: the second flush fails with `OptimisticLockException` instead of writing a wrong quantity, and the operation can be retried. Prefer the version when conflicts are rare and waiting is not acceptable; prefer the lock on hot products, where retries would pile up.

## Many products at once

To ship an order, lock its products in a fixed order, by identifier, so that two orders sharing products cannot wait for each other forever:

```php no-run
$products = array_map(fn (OrderLine $line): Product => $line->product, $order->lines->toArray());
usort($products, fn (Product $a, Product $b): int => $a->id <=> $b->id);
```

The lines of the order, and their products, are loaded in batches: two queries, whatever the size of the order.
