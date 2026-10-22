# Prism ORM — migrations

Migrations versionnées de Prism : une migration décrit des opérations de schéma portables, la même s'exécute sur MySQL, MariaDB, PostgreSQL et SQLite. Génération depuis les entités (`db:migrations:diff`, renommages proposés), migrations de données, linter de 19 règles avant exécution, `db:import` depuis une base existante.

```bash
composer require prism-orm/migrations
```

Hors Symfony, `vendor/bin/prism` charge un fichier `prism.php` qui retourne l'EntityManager :

```php
<?php // prism.php
return new Prism\Orm\EntityManager(
    Prism\Dbal\DriverManager::fromUrl((string) getenv('DATABASE_URL')),
    new Prism\Orm\Metadata\MetadataFactory(new Prism\Orm\Metadata\Driver\AttributeDriver([__DIR__ . '/src/Entity'])),
);
```

```bash
vendor/bin/prism db:migrations:diff
vendor/bin/prism db:migrations:lint
vendor/bin/prism db:migrations:migrate
```

Toutes les commandes : [référence des commandes](https://github.com/prism-orm/bundle/blob/main/docs/commands.md). Dans Symfony, ces commandes sont dans `bin/console` avec [`prism-orm/bundle`](https://github.com/prism-orm/bundle).

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
