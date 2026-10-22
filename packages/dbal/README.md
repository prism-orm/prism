# Prism ORM — DBAL

La couche d'accès aux bases de Prism, sans aucune dépendance Symfony : connexion PDO paresseuse, transactions imbriquées par savepoints, plateformes MySQL 8.0+, MariaDB 11.8+, PostgreSQL 15+ et SQLite 3.40+, types, schéma, introspection, et un diff de schéma vérifié par aller-retour sur les 4 bases.

```bash
composer require prism-orm/dbal
```

```php
use Prism\Dbal\DriverManager;

$connection = DriverManager::fromUrl('postgresql://app:secret@127.0.0.1:5432/app?serverVersion=16');

$rows = $connection->executeQuery('SELECT id, name FROM customer WHERE city = :city', ['city' => 'Paris'])->fetchAllAssociative();

$connection->transactional(static function () use ($connection): void {
    $connection->executeStatement('UPDATE invoice SET paid = :paid WHERE id = :id', ['paid' => true, 'id' => 42]);
});
```

Les valeurs sont toujours liées en paramètres, jamais concaténées ; un `decimal` est rendu en chaîne, jamais en flottant.

Dans une application Symfony, installez plutôt [`prism-orm/bundle`](https://github.com/prism-orm/bundle).

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
