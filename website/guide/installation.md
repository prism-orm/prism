# Installation

Prism needs PHP 8.4 or later with the PDO driver of your database (`pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`). The supported database versions are listed in the [requirements](/guide/introduction#requirements).

::: tip Trying the release candidate
Until 1.0.0 is released, `composer require prism-orm/bundle` installs the last stable version, 0.9. To try `1.0.0-RC1`, let Composer accept release candidates, then require the 1.x versions:

```bash
composer config minimum-stability RC
composer config prefer-stable true
composer require prism-orm/bundle:^1.0
```

`prism-orm/bundle:^1.0@RC` alone is not enough: Composer applies `@RC` to the packages you require, not to `prism-orm/orm` and the other packages the bundle depends on. With `prefer-stable`, Composer still picks a stable version as soon as there is one.
:::

## In a Symfony application

Prism replaces Doctrine in a Symfony 7.4 or 8 application:

```bash
composer require prism-orm/bundle
```

Symfony Flex registers the bundle in `config/bundles.php`. Without Flex, register it yourself:

```php
// config/bundles.php
return [
    // ...
    Prism\Bundle\PrismBundle::class => ['all' => true],
];
```

No configuration file is needed to start: Prism reads `DATABASE_URL` and maps the entities of `src/Entity/`. Set `DATABASE_URL` in `.env.local` (see [the database URL](#the-database-url)); every option is described in the [configuration reference](/reference/configuration).

The development tools are separate packages:

```bash
composer require --dev symfony/maker-bundle prism-orm/test-pack
```

`symfony/maker-bundle` brings `make:entity` and `make:migration` for Prism, and `prism-orm/test-pack` the factories and database helpers for your tests.

## Without Symfony

Install the ORM and the migrations package, which provides the `vendor/bin/prism` console:

```bash
composer require prism-orm/orm prism-orm/migrations
```

Create a `prism.php` file at the root of your project. It returns the `EntityManager` that your code and the console share:

```php
<?php // prism.php

declare(strict_types=1);

use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

return new EntityManager(
    DriverManager::fromUrl((string) getenv('DATABASE_URL')),
    new MetadataFactory(new AttributeDriver([__DIR__ . '/src/Entity'])),
);
```

Check that the console finds it:

```console
$ vendor/bin/prism list db
```

## The database URL

Prism reads the same URL format as Symfony. `serverVersion` is required: it selects the SQL dialect without connecting first.

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16"
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.4"
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=11.8.0-MariaDB"
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db?serverVersion=3.46.0"
```

Create the database if it does not exist yet:

::: code-group

```console [Symfony]
$ bin/console db:database:create
```

```console [Without Symfony]
$ vendor/bin/prism db:database:create
```

:::

## PHPStan

The PHPStan extension checks your queries and repository calls during analysis:

```bash
composer require --dev prism-orm/phpstan phpstan/extension-installer
```

With `phpstan/extension-installer`, the extension loads automatically. It reads the generated metamodel; in a Symfony application, tell it where the metamodel of your entity manager is:

```neon
# phpstan.neon
parameters:
    prism:
        metamodelDir: %currentWorkingDirectory%/var/cache/dev/prism/metamodel/default
```

## Next

[Create your first entity →](/guide/getting-started)
