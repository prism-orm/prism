# Prism

ORM PHP moderne pour les applications métier et l'IA.

- Anti-N+1 automatique (batch fetching)
- QueryBuilder typé et extension PHPStan
- Multi-tenancy et audit natifs
- Type `vector` et recherche hybride

> Statut : phases 0 à 8 livrées (DBAL, mapping, EntityManager, relations et anti-N+1, QueryBuilder typé et PHPStan, migrations et console, bundle Symfony, multi-tenancy et audit), `0.8.0` en préparation. Documentation : le site de [`website/`](website) (`make docs-install && make docs-dev`), en anglais, dont chaque exemple est exécuté en CI ; démonstration : [demo/](demo). Versions `0.x` : l'API peut encore changer. Voir [CHANGELOG.md](CHANGELOG.md).

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
| `packages/test-pack` | `Prism\TestPack` | Tests des applications : factories, `ResetDatabase`, `assertQueryCount()` |
| `website` | `Prism\Website\Tests` | Site de documentation (VitePress) et ses tests : exemples exécutés, complétude de la référence, liens |

Roadmap : [docs/ROADMAP.md](docs/ROADMAP.md) · Commandes `db:*` : [packages/bundle/docs/commands.md](packages/bundle/docs/commands.md) · Décisions : [docs/adr](docs/adr)

## Documentation

```bash
make docs-install   # dépendances npm du site (Docker, image node)
make docs-dev       # http://localhost:5173, rechargé à chaque modification
make docs-build     # website/.vitepress/dist, à publier ; échoue sur un lien cassé
```

Une page écrite dans `website/` ou `packages/<paquet>/docs/` est testée par `make ci` : ses blocs PHP sont exécutés sur la démo (`no-run` pour un fragment), ses liens et ses ancres vérifiés ; une page de référence doit couvrir tout le code qu'elle décrit.
