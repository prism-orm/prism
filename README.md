# Prism

ORM PHP moderne pour les applications métier et l'IA.

- Anti-N+1 automatique (batch fetching)
- QueryBuilder typé et extension PHPStan
- Multi-tenancy et audit natifs
- Type `vector` et recherche hybride

> Statut : `0.6.0`, phases 0 à 6 livrées (DBAL, mapping, EntityManager, relations et anti-N+1, QueryBuilder typé et PHPStan, migrations et console). Versions `0.x` : l'API peut encore changer. Voir [CHANGELOG.md](CHANGELOG.md).

## Démarrer

Prérequis : Docker et `make`.

```bash
make build      # image PHP 8.4
make up         # MySQL 8.4, MariaDB 11.8, PostgreSQL 16 + pgvector
make install    # composer install
make ci         # style + PHPStan + tests sur les 4 bases
```

`make help` liste toutes les commandes.

## Structure

| Paquet | Namespace | Rôle |
| --- | --- | --- |
| `packages/dbal` | `Prism\Dbal` | Connexion, plateformes, types, schéma |
| `packages/orm` | `Prism\Orm` | Mapping, UnitOfWork, requêtes |
| `packages/migrations` | `Prism\Migrations` | Migrations et linter |
| `packages/bundle` | `Prism\Bundle` | Intégration Symfony |
| `packages/phpstan` | `Prism\PHPStan` | Extension PHPStan |

Roadmap : [docs/ROADMAP.md](docs/ROADMAP.md) · Commandes `db:*` : [docs/commands.md](docs/commands.md) · Décisions : [docs/adr](docs/adr)
