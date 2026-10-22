# Prism ORM — bundle Symfony

**Prism** est un ORM PHP 8.4 pour les applications métier, pensé comme une alternative à Doctrine ORM. `prism-orm/bundle` l'intègre à Symfony 7.4 et 8.

- **Anti-N+1 automatique** : les relations paresseuses sont chargées par lot ; 100 commandes et leur client coûtent 2 requêtes au lieu de 101, et le profiler montre la ligne de code qui provoque un N+1.
- **QueryBuilder typé** : un metamodel généré, des requêtes sans chaîne de nom de champ, vérifiées par PHPStan, au résultat typé.
- **Migrations portables** : une migration décrit des opérations, la même s'exécute sur MySQL, MariaDB, PostgreSQL et SQLite ; un linter signale les opérations dangereuses.
- **Sans proxy** : entités `final` et propriétés `readonly` supportées (lazy ghosts natifs de PHP 8.4).

> Version `0.x` : l'API peut encore changer. Multi-tenancy et audit arrivent en 0.8, la recherche vectorielle en 0.9.

## Installer

```bash
composer require prism-orm/bundle
composer require --dev symfony/maker-bundle prism-orm/test-pack
```

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16"
```

```bash
bin/console db:database:create
bin/console make:entity Order
bin/console make:migration
bin/console db:migrations:migrate
```

## En bref

```php
#[Prism\Entity(repositoryClass: OrderRepository::class)]
final class Order
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\ManyToOne(inversedBy: 'orders')]
    public Customer $customer;
}

// dans un contrôleur : {id} → Order, 404 s'il n'existe pas
#[Route('/orders/{id}')]
public function show(Order $order): Response

// une requête typée
$o = Order_::as('o');
$page = $em->query($o)->where($o->customer->eq($customer))->orderBy($o->id->desc())->paginate(1, 20);
```

## Documentation

- [Prism dans Symfony](docs/symfony.md) : configuration, entités, migrations, injection, requêtes, contrôleurs, listeners, validation, sécurité, profiler, mode worker, tests.
- [Référence des commandes `db:*`](docs/commands.md).

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
