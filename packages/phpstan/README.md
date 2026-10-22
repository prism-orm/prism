# Prism ORM — extension PHPStan

Vérifie à l'analyse ce que Prism vérifierait à l'exécution : les champs de `findBy()`, `findOneBy()`, `count()`, les noms et types de `selectInto()`, le repository rendu par `getRepository()`, et les propriétés mappées considérées comme initialisées.

```bash
composer require --dev prism-orm/phpstan phpstan/extension-installer
```

Avec `phpstan/extension-installer`, l'extension est chargée automatiquement. Elle lit le metamodel généré (les classes `Order_`) ; dans une application Symfony, indiquez son dossier :

```neon
# phpstan.neon
parameters:
    prism:
        metamodelDir: %currentWorkingDirectory%/var/cache/dev/prism/metamodel/default
```

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
