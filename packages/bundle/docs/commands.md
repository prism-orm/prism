# Commandes console

Référence des commandes `db:*` de Prism : rôle, arguments, options, codes de retour et exemples. Les règles citées (RG-xx) sont celles de la feuille de route de Prism ; pour l'usage dans Symfony, voir [Prism dans Symfony](symfony.md).

Les exemples sont des sorties réelles de `bin/prism` sur SQLite, avec deux entités (`Customer`, `Invoice`) ; seuls les chemins absolus ont été raccourcis. Le SQL affiché dépend de la plateforme de la connexion.

## Lancer les commandes

### `bin/prism` et `prism.php`

Hors Symfony, l'exécutable `vendor/bin/prism` (paquet `prism-orm/migrations`) charge le fichier `prism.php` du répertoire courant, ou celui de `--config=<fichier>`. Ce fichier retourne soit l'`EntityManager`, soit un `PrismConfig` pour régler le reste.

La forme courte suffit quand les réglages par défaut conviennent (migrations dans `migrations/`, namespace `App\Migrations`, environnement `dev`) :

```php
<?php // prism.php

declare(strict_types=1);

use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

// bin/prism a déjà chargé l'autoloader de Composer
return new EntityManager(
    DriverManager::fromUrl((string) getenv('DATABASE_URL')),
    new MetadataFactory(new AttributeDriver([__DIR__ . '/src/Entity'])),
);
```

La forme complète :

```php
return new PrismConfig(
    $entityManager,
    migrations: new MigrationsConfiguration(__DIR__ . '/migrations', 'App\\Migrations', environment: 'prod'),
    metamodelDir: 'var/cache/prism/metamodel', // db:metamodel:generate
    largeTable: 1_000_000,                     // seuil de la règle L10 du linter
);
```

| Paramètre de `PrismConfig` | Défaut | Rôle |
| --- | --- | --- |
| `entityManager` | — | L'EntityManager ; sa connexion sert à toutes les commandes |
| `migrations` | `new MigrationsConfiguration()` | Répertoire et namespace des migrations, environnement (`dev`, `prod`…) |
| `metadata` | celle de l'`EntityManager` | À donner pour une autre implémentation d'`EntityManagerInterface` |
| `metamodelDir` | `var/cache/prism/metamodel` | Répertoire par défaut de `db:metamodel:generate` |
| `largeTable` | `1_000_000` | Nombre de lignes au-delà duquel le linter signale un `ALTER` sous MySQL (L10) |

```console
$ vendor/bin/prism list db
$ vendor/bin/prism --config=config/prism.php db:migrations:status
```

Dans une application Symfony, le bundle déclare les mêmes commandes dans `bin/console` (ADR 0013). Elles y gagnent l'option `--em=<nom>`, qui choisit l'EntityManager (par défaut, celui par défaut) : `bin/console db:migrations:migrate --em=billing`. Chaque EntityManager a ses propres migrations.

### Conventions communes

- **Codes de retour** : `0` (`Command::SUCCESS`), `1` (`Command::FAILURE` : erreur, base désynchronisée, linter en échec), `2` (`Command::INVALID` : option manquante ou invalide, ou commande destructrice lancée sans `--force`).
- **Commandes destructrices** : rien n'est exécuté sans `--force` ; la commande dit ce qu'elle aurait fait et rend `2`.
- **Environnement `prod`** : `db:migrations:migrate`, `db:migrations:execute` et `db:database:drop` demandent une confirmation, sauf avec `--no-interaction` (RG-06.9).
- **Options globales** de Symfony Console, valables partout : `--no-interaction` (`-n`), `--quiet` (`-q`), `--verbose` (`-v`), `--ansi` / `--no-ansi`, `--help` (`-h`).
- Les tables et séquences préfixées `prism_` appartiennent à Prism (`prism_migration_versions`) : aucune commande ne les supprime comme « non mappées » (ADR 0012).

## Base de données

### `db:database:create`

Crée la base nommée dans l'URL de la connexion. Sous PostgreSQL, Prism se connecte à la base `postgres` pour la créer ; sous SQLite, il crée le fichier.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--if-not-exists` | | Ne rien faire si la base existe déjà (code `0`) |

```console
$ vendor/bin/prism db:database:create

 [ERROR] Database "/srv/app/var/app.db" already exists; pass --if-not-exists to accept it.

$ vendor/bin/prism db:database:create --if-not-exists
```

### `db:database:drop`

Supprime la base de la connexion, avec toutes ses données. Exige `--force` ; en `prod`, demande en plus une confirmation.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--force` | `-f` | Supprimer la base |
| `--if-exists` | | Ne rien faire si la base n'existe pas (code `0`) |

```console
$ vendor/bin/prism db:database:drop

 ! [CAUTION] This would drop the database "/srv/app/var/app.db" and all its data; nothing was done.
 !           Run with --force to drop it.

$ vendor/bin/prism db:database:drop --force --if-exists
```

## Schéma sans migrations

Pour le développement et les tests : la base suit le mapping directement. En production, on passe par les migrations.

### `db:schema:create`

Crée les tables, index, clés étrangères et séquences du mapping. Refuse si l'une des tables existe déjà.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--dump-sql` | | Afficher le SQL au lieu de l'exécuter |

```console
$ vendor/bin/prism db:schema:create --dump-sql

 [ERROR] These tables already exist: customer, invoice.
         Run db:schema:update to bring an existing database in line with the mapping.
```

### `db:schema:update`

Aligne le schéma de la base sur le mapping. Sans `--force`, rien n'est exécuté (RG-02.9) : la commande compte les requêtes et rend `2`. Les tables non mappées ne sont supprimées qu'avec `--complete`.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--dump-sql` | | Afficher le SQL qui serait exécuté |
| `--force` | `-f` | Exécuter le SQL |
| `--complete` | | Supprimer aussi les tables et séquences non mappées (sauf `prism_*`) |

```console
$ vendor/bin/prism db:schema:update --dump-sql
$ vendor/bin/prism db:schema:update --force
$ vendor/bin/prism db:schema:update --dump-sql

 [OK] Nothing to update: the database is in sync with the mapping.
```

### `db:schema:drop`

Supprime les tables et séquences du mapping, et leurs données. Exige `--force`.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--dump-sql` | | Afficher le SQL au lieu de l'exécuter |
| `--force` | `-f` | Exécuter le SQL (détruit les données des tables mappées) |

```console
$ vendor/bin/prism db:schema:drop

 ! [CAUTION] This drops 2 table(s) and all their data: customer, invoice.
 !           Run with --force to execute, or --dump-sql to see the SQL.
```

### `db:schema:validate`

Valide le mapping (`MetadataValidator`), puis vérifie que la base y correspond. Rend `1` si le mapping est invalide ou si la base n'est pas synchronisée ; les avertissements (RG-04.6) ne font pas échouer.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--skip-sync` | | Valider le mapping seulement, sans se connecter à la base |

```console
$ vendor/bin/prism db:schema:validate

Mapping
-------

 [OK] The mapping of 2 entities is valid.

Database
--------

 [ERROR] The database is not in sync with the mapping: 4 queries pending. Run db:schema:update
         --dump-sql to see them.
```

## Migrations

Une migration est une suite d'opérations de schéma portables : la même migration s'exécute sur les 4 bases, et son SQL est produit par la plateforme au moment de l'exécution (ADR 0012). Les fichiers s'appellent `VersionYYYYMMDDHHMMSS` et s'exécutent dans cet ordre (RG-06.1) ; leur état est tenu dans la table `prism_migration_versions`.

### `db:migrations:generate`

Crée une migration vide à compléter. Avec `--data`, une migration de données (`VersionYYYYMMDDHHMMSSData`), qui reçoit l'EntityManager et s'exécute après la migration de schéma de la même version (RG-06.8).

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--data` | | Créer une migration de données |
| `--for` | | Avec `--data` : la migration de schéma qu'elle suit (`VersionYYYYMMDDHHMMSS`) ; sinon, une nouvelle version |

```console
$ vendor/bin/prism db:migrations:generate --data --for=Version20261021205005

 [OK] Migration Version20261021205005Data created:
      migrations/Version20261021205005Data.php
```

Deux migrations générées dans la même seconde auraient le même nom : la commande refuse alors d'écraser le fichier et demande d'attendre une seconde.

### `db:migrations:diff`

Compare les entités à la base et écrit la migration qui les rapproche.

- **Sans changement**, aucun fichier n'est créé et la commande rend `0` (RG-06.2).
- **Renommages** (RG-06.3) : une colonne ou une table supprimée et une autre ajoutée, de même type, font poser la question « renommage ? » en mode interactif. Confirmé, c'est un `renameColumn()` ou un `renameTable()` qui garde les données. En mode non interactif, la migration garde la suppression et l'ajout, avec un avertissement en commentaire.
- **`down()`** n'est écrit que s'il est sûr ; une migration qui détruit des données lève `IrreversibleMigrationException` dans son `down()` (RG-06.7).
- Les tables non mappées ne sont supprimées qu'avec `--complete` ; les tables `prism_*` jamais.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--complete` | | Supprimer aussi les tables et séquences non mappées (sauf `prism_*`) |
| `--dry-run` | | Afficher la migration au lieu de l'écrire |

```console
$ vendor/bin/prism db:migrations:diff --no-interaction

 [WARNING] customer.name is dropped and customer.full_name added, both string: if it is a rename,
           replace the dropColumn() and addColumn() below with $m->renameColumn('customer', 'name',
           'full_name') to keep the data.

 ! [NOTE] Its down() is not generated: it drops customer.name, whose data cannot be restored
 !        (RG-06.7).

 [OK] Migration Version20261021205005 generated with 3 operation(s):
      migrations/Version20261021205005.php. Review it, then run db:migrations:migrate.
```

Le fichier généré :

```php
final class Version20261021205005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(MigrationBuilder $m): void
    {
        // RG-06.3 : customer.name is dropped and customer.full_name added, both string: if it is a rename, replace the dropColumn() and addColumn() below with $m->renameColumn('customer', 'name', 'full_name') to keep the data.
        $m->addColumn('customer', new Column('full_name', 'string', length: 120));
        $m->dropColumn('customer', 'name');
        $m->addColumn('invoice', new Column('issued_at', 'datetime_immutable'));
    }

    public function down(MigrationBuilder $m): void
    {
        throw IrreversibleMigrationException::forVersion('Version20261021205005', 'it drops customer.name, whose data cannot be restored');
    }
}
```

### `db:migrations:migrate`

Exécute les migrations en attente, ou migre jusqu'à une version : les migrations en attente jusqu'à elle, puis celles exécutées après elle, revertées de la plus récente à la plus ancienne. `0` revertit tout.

- Une migration exécutée puis modifiée bloque la commande, sauf `--allow-modified` (RG-06.4 : checksum des opérations).
- Une migration tourne dans une transaction quand la plateforme sait annuler son DDL (PostgreSQL). Sinon, la commande le dit avant d'exécuter : un échec peut laisser un état partiel (RG-06.6).
- En `prod`, la commande demande confirmation, sauf `--no-interaction` (RG-06.9).

| Argument / option | Raccourci | Rôle |
| --- | --- | --- |
| `<version>` | | Version visée (`VersionYYYYMMDDHHMMSS`), ou `0` pour tout revertir ; facultatif |
| `--dry-run` | | Afficher le SQL de chaque migration sans l'exécuter |
| `--allow-modified` | | Exécuter même si une migration exécutée a été modifiée depuis (RG-06.4) |

```console
$ vendor/bin/prism db:migrations:migrate --dry-run
 up   Version20261021204946 (without transaction)
      CREATE TABLE "customer" ("id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, "name" VARCHAR(120) NOT NULL, "email" VARCHAR(180) NOT NULL);
      CREATE TABLE "invoice" ("id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, "total" NUMERIC(12, 2) NOT NULL, "customer_id" INTEGER NOT NULL, CONSTRAINT "fk_invoice_b335a48f" FOREIGN KEY ("customer_id") REFERENCES "customer" ("id") ON DELETE RESTRICT);
      CREATE UNIQUE INDEX "uniq_customer_45b166be" ON "customer" ("email");
      CREATE INDEX "idx_invoice_b335a48f" ON "invoice" ("customer_id");

 [WARNING] 1 migration(s) will run without a transaction: if one fails, the statements before the
           failure stay applied and the database is left half-migrated (RG-06.6).

 [OK] Dry run: 1 migration(s) planned, nothing executed.

$ vendor/bin/prism db:migrations:migrate --no-interaction
 up   Version20261021204946 (without transaction)
 ✔ up   Version20261021204946 (22 ms)

 [OK] 1 migration(s) executed in 22 ms.

$ vendor/bin/prism db:migrations:migrate 0 --dry-run
 down Version20261021204946 (without transaction)
      DROP TABLE "customer";
      DROP TABLE "invoice";
```

### `db:migrations:execute`

Exécute une seule migration, dans un sens : exactement une option parmi `--up` et `--down` (sinon code `2`). Une migration de schéma seule, sans sa migration de données, se joue ainsi. Confirmation en `prod` (RG-06.9).

| Argument / option | Raccourci | Rôle |
| --- | --- | --- |
| `<version>` | | Version à exécuter (`VersionYYYYMMDDHHMMSS`) ; obligatoire |
| `--up` | | Exécuter son `up()` |
| `--down` | | Exécuter son `down()` |
| `--dry-run` | | Afficher son SQL sans l'exécuter |

```console
$ vendor/bin/prism db:migrations:execute Version20261021204946 --down --dry-run
 down Version20261021204946 (without transaction)
      DROP TABLE "customer";
      DROP TABLE "invoice";

 [OK] Dry run: nothing executed.
```

### `db:migrations:status`

Résume la position de la base : version courante, dernière version, nombre de migrations exécutées et en attente. Signale une migration modifiée après exécution (que `migrate` refusera) ou dont le fichier a disparu.

```console
$ vendor/bin/prism db:migrations:status
 ----------------- -----------------------
  Directory         migrations
  Namespace         App\Migrations
  Current version   0
  Latest version    Version20261021204946
  Executed          0
  Pending           1
 ----------------- -----------------------
```

### `db:migrations:list`

Liste chaque migration avec son état (`pending`, `executed`, `modified`, `missing`), sa date d'exécution en UTC, sa durée et sa description.

```console
$ vendor/bin/prism db:migrations:list
 ----------------------- ---------- --------------------- ---------- -------------
  Version                 State      Executed at (UTC)     Duration   Description
 ----------------------- ---------- --------------------- ---------- -------------
  Version20261021204946   executed   2026-10-21 20:49:47   22 ms
 ----------------------- ---------- --------------------- ---------- -------------
```

### `db:migrations:lint`

Analyse les migrations en attente avant leur exécution (RG-06.5) : leurs opérations, appliquées une à une au schéma, croisées avec les données de la base, le mapping et la plateforme. Une erreur fait échouer la commande (code `1`) ; un avertissement aussi avec `--strict`. `#[IgnoreLint('L12', reason: '…')]` sur une migration ignore une règle assumée.

| Argument / option | Raccourci | Rôle |
| --- | --- | --- |
| `<version>` | | Analyser les migrations en attente jusqu'à cette version ; facultatif |
| `--strict` | | Échouer aussi sur les avertissements |

| Code | Sévérité | Cas |
| --- | --- | --- |
| L00 | erreur | Opération impossible sur le schéma |
| L01 | erreur | Colonne NOT NULL sans défaut ajoutée à une table non vide |
| L02 | erreur | Colonne passée à NOT NULL alors qu'elle contient des NULL |
| L03 | erreur | Colonne supprimée, encore mappée |
| L04 | erreur | Table supprimée, encore mappée |
| L05 | erreur | Table ou colonne renommée dont l'ancien nom est encore mappé |
| L06 | erreur | Table supprimée, encore visée par une clé étrangère |
| L07 | erreur | Index unique sur des doublons |
| L08 | erreur | Clé étrangère sur des lignes orphelines |
| L09 | avertissement | Index sans `CONCURRENTLY` sur une table non vide, sous PostgreSQL |
| L10 | avertissement | DDL sur une table de plus d'un million de lignes, sous MySQL et MariaDB |
| L11 | avertissement | Table non vide supprimée |
| L12 | avertissement | Colonne supprimée d'une table non vide |
| L13 | avertissement | Colonne rétrécie sur une table non vide |
| L14 | avertissement | Type de colonne changé sur une table non vide |
| L15 | avertissement | Clé primaire changée sur une table non vide |
| L16 | avertissement | SQL brut (`$m->sql()`), ni portable ni analysable |
| L17 | avertissement | Plusieurs DDL sans transaction, sous MySQL et MariaDB (RG-06.6) |
| L18 | avertissement | Table créée sans clé primaire |
| L19 | avertissement | Migration irréversible (RG-06.7) |

```console
$ vendor/bin/prism db:migrations:lint
 ----------------------- ------ ---------- ------------------------------------------------------
  Version                 Code   Severity   Message
 ----------------------- ------ ---------- ------------------------------------------------------
  Version20261021205005   L01    error      customer.full_name is added NOT NULL without a default
                                            to a table with rows: the existing rows have no value
                                            for it. Add it nullable, fill it, then make it NOT
                                            NULL; or give it a default.
  Version20261021205005   L12    warning    customer.name is dropped from a table with rows: its
                                            data is lost.
  Version20261021205005   L01    error      invoice.issued_at is added NOT NULL without a default
                                            to a table with rows: […]
 ----------------------- ------ ---------- ------------------------------------------------------

 [ERROR] 1 pending migration(s) linted: 2 error(s), 1 warning(s).
```

## Mapping et outils

### `db:mapping:info`

Liste les entités mappées, avec leur table et leur repository.

```console
$ vendor/bin/prism db:mapping:info
 --------------------- ---------- ------------
  Entity                Table      Repository
 --------------------- ---------- ------------
  App\Entity\Customer   customer   -
  App\Entity\Invoice    invoice    -
 --------------------- ---------- ------------

 [OK] 2 mapped entities.
```

### `db:query:sql`

Exécute une requête SQL brute, avec des paramètres nommés liés (`:name`), jamais concaténés. Une requête qui n'est pas une lecture exige `--force` (sinon code `2`).

| Argument / option | Raccourci | Rôle |
| --- | --- | --- |
| `<sql>` | | La requête, avec des paramètres `:nommés` ; obligatoire |
| `--param` | `-p` | Un paramètre lié, `nom=valeur` ; répétable |
| `--force` | `-f` | Exécuter une requête qui n'est pas une lecture |
| `--max-rows` | | Nombre maximal de lignes affichées (défaut : 100) |

```console
$ vendor/bin/prism db:query:sql "SELECT version, execution_time_ms FROM prism_migration_versions"
 ----------------------- -------------------
  version                 execution_time_ms
 ----------------------- -------------------
  Version20261021204946   22
 ----------------------- -------------------

 1 row(s).

$ vendor/bin/prism db:query:sql "DELETE FROM invoice"

 ! [CAUTION] This query is not a read: nothing was executed. Run with --force to execute it.

$ vendor/bin/prism db:query:sql --force "INSERT INTO customer (name, email) VALUES (:n, :e)" -p n=Ada -p e=ada@example.test

 [OK] 1 row(s) affected.
```

### `db:import`

Génère les entités d'une base existante (RG-06.10) : une classe `final` par table, typée strictement, avec un `ManyToOne` (ou un `OneToOne` si la colonne est unique) par clé étrangère. Le mapping écrit est exact, si bien que le premier `db:migrations:diff` après l'import est vide, aux approximations signalées près.

- **Jamais d'écrasement** d'un fichier existant sans `--force`.
- **Signalé, jamais approximé en silence** :
  - les tables sans clé primaire ou à clé composite (non importées, RG-02.4) ;
  - les clés étrangères composites ou vers une table non importée (gardées en colonne) ;
  - les colonnes de jointure sans index ou avec un défaut ;
  - les colonnes `ENUM`, lues comme un `string` (ADR 0012, section 8).
- **Toujours exclues** : `prism_*` et `doctrine_migration_versions`.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--namespace` | | Namespace des entités (défaut : `App\Entity`) |
| `--dir` | | Répertoire des fichiers (défaut : `src/Entity`) |
| `--strip-prefix` | | Préfixe de table retiré des noms de classe |
| `--exclude` | | Expression régulière des tables à ignorer ; répétable |
| `--dry-run` | | Afficher les entités au lieu de les écrire |
| `--force` | `-f` | Remplacer les fichiers existants |

```console
$ vendor/bin/prism db:import --dry-run --namespace='App\Imported' --exclude=invoice

src/Entity/Customer.php
-----------------------

<?php

declare(strict_types=1);

namespace App\Imported;

use Prism\Orm\Mapping as Prism;

// Importé par db:import depuis la table customer : à relire.
#[Prism\Entity]
#[Prism\Table('customer')]
#[Prism\UniqueConstraint(['email'], name: 'uniq_customer_45b166be')]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $name;

    #[Prism\Column(length: 180)]
    public string $email;
}

 [OK] 1 table(s) imported, 0 skipped. Review the entities, then run db:metamodel:generate.
```

### `db:metamodel:generate`

Génère les classes du metamodel du QueryBuilder typé (`Customer_`, `Invoice_`…), et les aides aux IDE (ADR 0009, RG-05.1, RG-05.2). Seuls les fichiers qui changent sont réécrits ; ceux des entités disparues sont supprimés. Les fichiers générés ne sont pas versionnés.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--dir` | | Répertoire des classes générées (défaut : `metamodelDir` de `PrismConfig`, `var/cache/prism/metamodel`) |
| `--ide-stub` | | Stub du metamodel pour les IDE, hors des répertoires qu'ils excluent (défaut : `.prism/metamodel.php`, à ignorer dans git) |
| `--phpstorm-meta` | | Répertoire des métadonnées PhpStorm, où `prism.php` associe chaque entité à son repository (défaut : `.phpstorm.meta.php`) |
| `--no-ide` | | Générer le metamodel seul, sans les aides aux IDE |

```console
$ vendor/bin/prism db:metamodel:generate --no-ide
 written   App/Entity/Customer_.php
 written   App/Entity/Invoice_.php

 [OK] Metamodel in var/cache/prism/metamodel: 2 written, 0 unchanged, 0 deleted.
```

### `db:cache:clear-metadata`

Vide le cache PSR-6 des metadata, s'il y en a un. Sans cache configuré, les attributs sont relus à chaque exécution : il n'y a rien à vider.

```console
$ vendor/bin/prism db:cache:clear-metadata

 ! [NOTE] No metadata cache is configured: the attributes are read on each run, there is nothing to
 !        clear.
```

### `db:audit:purge`

Supprime les lignes du journal d'audit (`prism_audit_log`) plus anciennes que la rétention de leur entité, `#[Auditable(retention: 'P10Y')]`. C'est la seule voie de suppression d'une ligne d'audit (RGPD). Une entité sans rétention n'est purgée qu'avec `--older-than`. Sans `--force`, la commande montre ce qu'elle supprimerait.

| Option | Raccourci | Rôle |
| --- | --- | --- |
| `--older-than` | | Rétention ISO 8601 des entités auditées qui n'en déclarent pas, par exemple `P1Y` |
| `--force` | `-f` | Supprimer les lignes |

```console
$ vendor/bin/prism db:audit:purge --older-than=P1Y

 ------------------ ----------- --------------------- ------
  Entity             Retention   Rows before           Rows
 ------------------ ----------- --------------------- ------
  App\Entity\Order   P1Y         2025-10-26 09:00:00   1240
 ------------------ ----------- --------------------- ------

 ! [CAUTION] This would delete 1240 audit log row(s); nothing was done. Run with --force to delete them.

$ vendor/bin/prism db:audit:purge --older-than=P1Y --force
```
