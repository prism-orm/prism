# Prism ORM — test-pack

Les outils de test des applications Symfony qui utilisent Prism. À installer en `require-dev` : rien de ce paquet n'arrive en production.

```bash
composer require --dev prism-orm/test-pack
```

```php
final class OrderPagesTest extends WebTestCase
{
    use ResetDatabase;          // schéma créé une fois, une transaction annulée par test
    use QueryCountAssertions;

    public function testList(): void
    {
        $client = static::createClient();
        OrderFactory::new()->many(30);

        $this->assertQueryCount(5, fn () => $client->request('GET', '/orders'));
    }
}

final class OrderFactory extends Factory
{
    protected static function class(): string { return Order::class; }

    protected function defaults(): array
    {
        return ['reference' => fn (int $n) => sprintf('ORD-%05d', $n), 'customer' => CustomerFactory::new()];
    }
}
```

`#[WithRealTransactions]` désactive la transaction englobante pour un test qui a besoin de vrais commits. Détails : [Prism dans Symfony](https://github.com/prism-orm/bundle/blob/main/docs/symfony.md#tester).

## Paquets

| Paquet | Rôle |
| --- | --- |
| [`prism-orm/dbal`](https://github.com/prism-orm/dbal) | Connexion, plateformes, types, schéma et diff |
| [`prism-orm/orm`](https://github.com/prism-orm/orm) | Mapping, EntityManager, UnitOfWork, QueryBuilder typé |
| [`prism-orm/migrations`](https://github.com/prism-orm/migrations) | Migrations, linter, `bin/prism` hors Symfony |
| [`prism-orm/bundle`](https://github.com/prism-orm/bundle) | Intégration Symfony |
| [`prism-orm/phpstan`](https://github.com/prism-orm/phpstan) | Extension PHPStan |
| [`prism-orm/test-pack`](https://github.com/prism-orm/test-pack) | Tests des applications : factories, `ResetDatabase`, `assertQueryCount()` |
Ce dépôt est en lecture seule : il est publié automatiquement depuis le dépôt de développement de Prism. Licence MIT.
