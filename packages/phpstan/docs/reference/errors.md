# PHPStan errors

The errors reported by Prism's [PHPStan extension](/guide/phpstan), by identifier. Each identifier can be ignored in your PHPStan configuration like any other, although each one points at code that fails at run time.

## Repository calls

These rules check the criteria and order arguments of `findBy()`, `findOneBy()`, `count()`, `iterate()`, `nearest()` and `hybridSearch()` on a Prism repository, and the field that `nearest()` searches.

### `prism.findBy.unknownField`

A key of the criteria or of the order is not a field of the entity.

```text
Unknown field App\Entity\Customer::$emial in the criteria of findBy(). Did you mean "email"?
```

```php no-run
$customers->findBy(['emial' => 'ada@example.test']);
```

**Fix:** use the property name of the entity, not the column name: `['email' => ...]`. The suggestion is the closest field name.

### `prism.findBy.relation`

A key designates a collection, or the inverse side of a relation: neither has a column to compare.

```text
App\Entity\Customer::$orders is a collection: findBy() compares only fields and owning ToOne relations; join it with the typed query builder instead.
```

```php no-run
$customers->findBy(['orders' => $order]);
```

**Fix:** compare from the owning side (`$orders->findBy(['customer' => $customer])`), or join the relation in a [typed query](/guide/query-builder#joins).

### `prism.findBy.invalidOrder`

A value of the order argument is not a case of `Prism\Dbal\Query\Order`.

```text
The order of App\Entity\Customer::$name in findBy() is 'ASC', not a Prism\Dbal\Query\Order case.
```

```php no-run
$customers->findBy([], ['name' => 'ASC']);
```

**Fix:** `['name' => Order::Asc]`, with `use Prism\Dbal\Query\Order;`.

### `prism.nearest.notVector`

The field searched by `nearest()` is not a field of type `vector` of the entity. The message lists the vector fields the entity has.

```text
App\Entity\Article::$title is not a vector field: nearest() searches a field of type "vector" (embedding).
```

```php no-run
$articles->nearest('title', $question);
```

**Fix:** name the vector field, here `'embedding'`.

## Projections

These rules check the named arguments of `selectInto(Dto::class, ...)` against the constructor of the DTO, as Prism does before running the query.

### `prism.selectInto.unnamed`

A selection is passed without a name.

```text
Each selection of selectInto(App\Report\CustomerRevenue::class, ...) is named after a parameter of its constructor, such as total: $o->total->sum().
```

```php no-run
$query->selectInto(CustomerRevenue::class, $c->name, $i->total->sum());
```

**Fix:** name each selection after the constructor parameter it fills: `customer: $c->name, total: $i->total->sum()`.

### `prism.selectInto.unknownParameter`

A selection is named after no parameter of the constructor.

```text
The selection "amount" matches no parameter of App\Report\CustomerRevenue::__construct() (customer, invoices, total, lastInvoice).
```

```php no-run
$query->selectInto(CustomerRevenue::class, customer: $c->name, amount: $i->total->sum());
```

**Fix:** use one of the parameter names listed in the message.

### `prism.selectInto.missingParameter`

A required parameter of the constructor is not selected.

```text
The parameter $lastInvoice of App\Report\CustomerRevenue::__construct() is required but not selected: select it as lastInvoice: ...
```

**Fix:** select it, or give the parameter a default value in the DTO.

### `prism.selectInto.typeMismatch`

A selection has a type that its parameter does not accept.

```text
The selection "total" is string|null, but $total of App\Report\CustomerRevenue::__construct() accepts string.
```

**Fix:** align the parameter with the selection. An aggregate other than `count()` is `null` on an empty set, so its parameter is nullable: `?string $total`. A `decimal` is a `string`, never a `float`.
