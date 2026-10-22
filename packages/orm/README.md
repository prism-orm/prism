# Prism ORM — ORM

Le cœur de Prism : mapping par attributs, EntityManager et UnitOfWork, relations paresseuses par lazy ghosts natifs (PHP 8.4), batch fetching automatique contre le N+1, QueryBuilder typé par un metamodel généré, projections et pagination.

```bash
composer require prism-orm/orm
```

```php
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

$em = new EntityManager(
    DriverManager::fromUrl((string) getenv('DATABASE_URL')),
    new MetadataFactory(new AttributeDriver([__DIR__ . '/src/Entity'])),
);

$order = $em->find(Order::class, 42);
$order->status = 'shipped';
$em->flush();
```

Dans une application Symfony, installez [`prism-orm/bundle`](https://github.com/prism-orm/bundle) : configuration, injection, profiler et commandes sont fournis. Hors Symfony, les commandes `db:*` sont disponibles avec [`prism-orm/migrations`](https://github.com/prism-orm/migrations) (`bin/prism`).

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
