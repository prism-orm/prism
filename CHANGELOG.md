# Changelog

Toutes les évolutions notables de Prism, par version. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions [SemVer](https://semver.org/lang/fr/), en `0.x` jusqu'à la 1.0 (RT-16). Les règles citées (RG-xx, RT-xx) sont décrites dans `docs/ROADMAP.md`, les décisions (ADR) dans `docs/adr/`.

## [0.7.0] — 2026-10-22

Phase 7 : bundle Symfony. `composer require prism-orm/bundle` suffit pour remplacer Doctrine dans une application Symfony 7.4 ou 8 ; guide : [Prism dans Symfony](packages/bundle/docs/symfony.md).

### Ajouté

- `prism-orm/bundle` (ADR 0013) : configuration `prism.yaml` validée (forme courte ou complète), plusieurs connexions et EntityManagers nommés, le premier par défaut (RG-07.1) ; `DATABASE_URL` lue par défaut sans `prism.yaml`, et seulement au premier usage de la connexion.
- Autowiring de `EntityManagerInterface`, `Connection` et des repositories, même instance que `getRepository()` (RG-07.2) ; arguments nommés pour les autres EntityManagers (`$billingEntityManager`).
- Commandes `db:*` dans `bin/console`, avec `--em` ; migrations propres à chaque EntityManager.
- Types personnalisés par le tag `prism.type`, `#[AsEntityListener]`, `#[AsPrismListener]` et un dispatcher PSR-14 par EntityManager ; metamodel chargé au démarrage, regénéré en dev quand une entité change et au `cache:warmup` (RG-05.1) ; cache des metadata en prod.
- `EntityValueResolver` et `#[MapEntity]` : `{id}` → entité dans les contrôleurs, 404 si absente ou identifiant invalide, `null` pour un argument nullable (RG-07.3).
- Panneau du profiler : requêtes et durées (1 000 enregistrées au plus par requête), N+1 avec leur origine, chargements par lot, flushs et changesets ; absent du conteneur en prod (RG-07.4, RG-04.16).
- Mode worker : EntityManagers vidés et transaction restée ouverte annulée par `kernel.reset`, connexion perdue reconnectée au début de la requête suivante (`connection_idle_ttl`) ; middleware Messenger `prism_reset` (RG-07.5). Testé sur 10 000 requêtes d'un worker FrankenPHP, mémoire stable.
- `#[UniqueEntity]` (champs et relations, `ignoreNull`) et user provider « prism » de `security.yaml`.
- `make:entity` (classe, repository, champs, 4 sortes de relations avec leurs deux côtés, code existant gardé) et `make:migration` ; `make:prism:*` à côté de DoctrineBundle (RG-07.7).
- Paquet `prism-orm/test-pack` : factories, `ResetDatabase` (schéma préparé une fois, une transaction annulée par test, `KernelTestCase` et `WebTestCase`), `#[WithRealTransactions]`, `assertQueryCount()` (RG-07.6).
- Recette Flex (`recipes/prism-orm/bundle/0.7`), et sa vérification sur `symfony/skeleton` et `webapp-pack` sans Doctrine, sous Symfony 7.4 et 8 (`make compat`, CI).
- Application de démonstration `demo/` : un ERP minimal écrit avec Prism seul, sans chaîne de nom de champ dans ses requêtes, testé avec le test-pack ; `make worker-test`.
- ORM : `BatchLoadedEvent` à chaque chargement par lot ; `EntityRepository::getEntityManager()` et `getClassMetadata()` (ADR 0006).
- DBAL : `Connection::ping()`.

### Modifié

- Symfony 8.1 et Infection 0.35 dans le monorepo (Infection 0.29 bloquait `symfony/console` en 7.x, et les 8.0 sont visées par des avis de sécurité) ; MSI : `UnitOfWork` 91 %, `CommitOrderCalculator` 98 %.

## [0.6.0] — 2026-10-21

Phase 6 : migrations et console.

### Ajouté

- Migrations décrites par des opérations de schéma portables (`AbstractMigration`, `MigrationBuilder`) : la même migration s'exécute sur les 4 bases, son SQL est produit par la plateforme à l'exécution, avec la reconstruction de table de SQLite ; `$m->sql()` pour le SQL brut (ADR 0012, RG-06.1).
- Table `prism_migration_versions` et checksum des opérations : une migration modifiée après exécution bloque `migrate`, sauf `--allow-modified` (RG-06.4). Le préfixe `prism_` est réservé : ni `db:schema:update --complete` ni `db:migrations:diff --complete` ne suppriment ces tables.
- `Migrator` : migration jusqu'à une version ou `0`, `--dry-run` ; transaction quand la plateforme sait annuler son DDL (PostgreSQL), avertissement d'état partiel sinon (RG-06.6) ; `down()` absent → `IrreversibleMigrationException` (RG-06.7).
- Commandes `db:migrations:generate`, `migrate`, `execute`, `status`, `list` ; confirmation en `prod` sauf `--no-interaction` (RG-06.9).
- `db:migrations:diff` : gabarit déterministe, rien à écrire sans changement (RG-06.2), `down()` seulement s'il est sûr, `--complete`, `--dry-run` ; `RenameDetector` pour les tables et les colonnes, avec la séquence `{table}_id_seq` gardée (RG-06.3).
- `DataMigration` (`VersionXData`) : après la migration de schéma de la même version, dans une transaction, avec l'EntityManager ; `generate --data` (RG-06.8).
- `MigrationLinter` et `db:migrations:lint` : 19 règles sur les opérations, les données et le mapping, `#[IgnoreLint]`, `--strict` ; `createIndex(concurrently: true)` sous PostgreSQL (RG-06.5).
- `db:database:create` et `drop`, `db:mapping:info`, `db:query:sql` (`--param`, `--force` hors lecture), `db:cache:clear-metadata`.
- `db:import` : entités `final` typées depuis une base existante, relations des clés étrangères, mapping exact ; tables sans clé primaire, clés composites, `ENUM` et colonnes de jointure approximées signalés ; `--force` pour remplacer un fichier (RG-06.10). Vérifié sur un schéma ERP réel de 165 tables.
- `bin/prism` : toutes les commandes `db:*` hors Symfony, depuis un `prism.php` qui retourne l'EntityManager ou un `PrismConfig`.
- Référence des commandes avec exemples : [commands.md](packages/bundle/docs/commands.md), vérifiée par un test contre les définitions des commandes.
- Mapping : `#[ManyToOne|OneToOne(foreignKey: '…')]` nomme la contrainte ; `OnDelete::NoAction` ; `#[Column(length: 3, fixed: true)]` pour `CHAR` et `BINARY`.
- DBAL : renommage de table, de colonne et de séquence ; `Column::$fixed` (`CHAR`, `BINARY`) ; `supportsTransactionalDdl()`, création d'index concurrente, SQL de création, suppression et liste des bases ; `Connection::withoutDatabase()` ; `SchemaManager::getIntrospectionNotes()`.

### Modifié

- Introspection MySQL et MariaDB élargie aux types créés par d'autres outils (`mediumtext`, `char`, `blob`, `tinyint(4)`, `timestamp`, `year`, `enum`…) ; les défauts que Prism ne gère pas (texte, JSON, `CURRENT_TIMESTAMP`) sont ignorés au lieu d'être lus comme des littéraux.
- PostgreSQL et SQLite relisent `CHAR(n)` en chaîne de longueur fixe, au lieu de le refuser (PostgreSQL) ou de le lire comme un `uuid` (SQLite, hors `CHAR(36)`).
- `Column`, `ColumnDiff` et `FieldMapping` gagnent `fixed` en dernier paramètre ; `Column::withType()` repart d'une longueur variable, sauf `fixed: true`.
- `Introspector` (`@internal`) gagne `notes()`.

## [0.5.0] — 2026-10-21

Phase 5 : pilier 2, QueryBuilder typé et PHPStan.

### Ajouté

- Metamodel généré : une classe `Order_` par entité (et `Address_` par embeddable), une propriété typée par champ et relation (`StringField`, `DecimalField`, `EnumField<Status>`, `Relation<Client_>`, `CollectionRelation<Line_>`…) ; gabarits déterministes, fichiers réécrits seulement s'ils changent, chargement par `MetamodelAutoloader`, commande `db:metamodel:generate` (ADR 0009, RG-05.1, RG-05.2).
- `EntityQueryBuilder` immuable, via `$em->query(Order_::as('o'))` : `where`, `andWhere`, `orWhere`, `orderBy`, `limit`, `offset` ; expressions typées `eq` à `between`, `in`, `like`, `isNull`, conditions `all`, `any`, `not` ; valeurs toujours liées et converties par le Type du champ ; `getResult(): list<T>`, `getOneOrNull(): ?T` ; `toSql()` et `getConnection()` restent accessibles (RG-05.3, RG-05.4, RG-05.6, RG-05.10).
- Jointures sur les 4 sortes de relation (pivot pour ManyToMany), `fetchJoin()` et `leftFetchJoin()` sans requête ensuite, collections chargées dans leur ordre, racines sans doublon ; `withoutBatchFetch()` (RG-05.5, RG-04.13).
- Agrégats typés (`count`, `countDistinct`, `sum`, `avg`, `min`, `max`), `groupBy`, `having`, tri sur un agrégat ; un champ ni agrégé ni groupé est refusé avant l'envoi, comme PostgreSQL et MySQL le feraient (ADR 0010).
- Projection `selectInto(Dto::class, total: $o->total->sum(), …)` : arguments nommés comme le constructeur, vérifié par Reflection dès l'appel, valeurs converties par leur Type, hors de l'UoW (RG-05.6, RG-05.7).
- Chemins JSON : `$o->meta->path('lines', 0, 'qty')->asInt()`, `asString()`, `asDecimal()` ; même résultat sur les 4 bases (null JSON et chemin absent à `NULL`), groupables et triables (ADR 0010).
- `paginate(page:, perPage:)` : identifiant ajouté en dernier critère de tri, une requête par page, identifiants puis entités avec une collection jointe, `COUNT(DISTINCT)` paresseux (RG-05.9).
- Extension PHPStan `prism-orm/phpstan` : clés de `findBy()`, `findOneBy()`, `count()`, `iterate()` ; noms et types des sélections de `selectInto()` ; `getRepository()` typé par le repository déclaré ; propriétés mappées des entités et embeddables considérées initialisées (ADR 0011, RG-05.7, RG-05.8). Suite de 71 cas.
- Aide aux IDE : stub du metamodel (`.prism/metamodel.php`) et `.phpstorm.meta.php/prism.php`, écrits par `db:metamodel:generate` (RG-05.2).
- DBAL : `between()`, `not()` et fonctions d'agrégat dans l'`ExpressionBuilder` ; `Platform::getJsonExtractTextSql()` et `getCastSql()`.

### Modifié

- `EntityManagerInterface` gagne `query()` : une implémentation tierce de l'interface doit l'ajouter (admis en `0.x`, ADR 0009).
- `UnitOfWork::hydrate()` charge depuis la ligne lue une référence déjà connue mais non initialisée, au lieu de la recharger.

## [0.4.0] — 2026-10-20

Phase 4 : relations, lazy loading et pilier 1 anti-N+1.

### Ajouté

- Relations `ManyToOne` et `OneToOne` : FK écrite depuis l'identifiant de la cible, relue en référence de l'identity map (ghost natif) ; critères `findBy(['relation' => $entité ou id])` (RG-04.7, RG-04.8).
- Ordre de commit par tri topologique stable (`CommitOrderCalculator`), rupture de cycle sur une FK nullable (INSERT à `NULL` puis UPDATE), `CyclicDependencyException` sinon ; DELETE en ordre inverse (RG-04.3, RG-04.4).
- `cascade: persist` et `UnpersistedRelationException` qui nomme l'entité et le chemin des relations (RG-04.2).
- Collections `OneToMany` et `ManyToMany` : interface `Collection`, `ArrayCollection`, `PersistentCollection` chargée au premier accès ; suivi par différence, la collection de l'application n'est jamais remplacée, ce qui permet les collections `readonly` dans des entités `final` (ADR 0007) ; `orderBy`, `indexBy`, `EXTRA_LAZY`.
- Cascades `remove` et `detach`, `orphanRemoval` (collections et `OneToOne`), `OrphanReattachedException` (RG-04.5, RG-04.6) ; `InverseSideChangedEvent` quand seul le côté inverse change (RG-04.1).
- Batch fetching par cohorte : une relation chargée pour une entité l'est pour toute la cohorte, en une requête `IN` par lot de 100 (`batchSize` par relation ou sur l'`EntityManager`, `batch: false`) ; collections, côté inverse des `OneToOne` et `Fetch::Eager` compris ; une cohorte par page d'`iterate()` (RG-04.9 à RG-04.14). 100 commandes et leur client : 2 requêtes au lieu de 101.
- `N1Detector` et `NPlusOneDetected` : lectures identiques comptées par SQL normalisé, origine `fichier:ligne` dans le code de l'application (RG-04.15).
- DBAL : observateurs de requêtes `QueryObserver` sur la `Connection` (ADR 0008).
- Mapping : avertissements `MappingSeverity::Warning` pour les combinaisons de suppression redondantes ou contradictoires (RG-04.6) ; une relation de collection doit être typée `Collection` (ADR 0007).

### Corrigé

- Une relation typée `?self` (forme produite par PHP-CS-Fixer) visait une classe `self` inexistante.
- L'INSERT d'une entité dont la seule colonne est un identifiant auto-incrémenté était refusé ; syntaxe par plateforme (`DEFAULT VALUES`, `() VALUES ()`).
- Une entité `NEW` affectée au côté inverse d'un `OneToOne` avec `cascade: persist` n'était pas persistée.

## [0.3.0] — 2026-10-19

Phase 3 : EntityManager et UnitOfWork.

### Ajouté

- `UnitOfWork` : identity map, quatre états (RG-03.1, RG-03.2), détection des changements sur les valeurs converties en base (RG-03.4), UPDATE des seules colonnes modifiées (RG-03.5), flush atomique (RG-03.6), flush réentrant refusé (RG-03.7), verrouillage optimiste (RG-03.9), politiques de suivi `IMPLICIT` et `EXPLICIT` (RG-03.11).
- `EntityManager`, `EntityRepository` extensible (ADR 0006), `iterate()` par pagination sur clé avec détachement par lot (RG-03.10), `refresh()`, `getReference()`, `lock()` optimiste et pessimiste.
- Générateurs d'identifiants IDENTITY, SEQUENCE et UUID v7 monotone (RG-02.4).
- Hydratation sans appel du constructeur (RG-03.12), `ObjectHydrator` et `ArrayHydrator`.
- Événements PSR-14 (`PreFlush`, `OnFlush`, `PostFlush`, `PostLoad`, `OnClear`, `Pre*` et `Post*`), callbacks de cycle de vie et listeners d'entité.
- DBAL : clause de verrou par plateforme et `QueryBuilder::lock()` ; dernier identifiant inséré et valeur suivante d'une séquence.
- Benchmarks PHPBench : hydratation de 10 000 entités, flush de 1 000 UPDATE, `iterate()` sur 1 million de lignes en moins de 64 Mo.

### Performance

- Snapshot calculé depuis les valeurs hydratées, types de colonnes mis en cache.

## [0.2.0] — 2026-10-19

Phase 2 : mapping et metadata.

### Ajouté

- Attributs de mapping, `ClassMetadata`, `AttributeDriver` avec déduction du type et de la nullabilité (RG-02.1, RG-02.3, RG-02.6, RG-02.7).
- `MetadataValidator` : toutes les erreurs de mapping, avec leur message (RG-02.2 à RG-02.5, RG-02.8).
- `MetadataFactory` avec cache PSR-6, sans lecture d'attributs après échauffement.
- `SchemaTool::fromMetadata()` et commandes `db:schema:create`, `update`, `drop`, `validate` (RG-02.9).

## [0.1.0] — 2026-10-19

Phase 1 : DBAL.

### Ajouté

- `Connection` : PDO paresseux, transactions imbriquées par savepoints, logger PSR-3 (RG-01.1, RG-01.2).
- Plateformes MySQL, MariaDB, PostgreSQL et SQLite : identifiants et littéraux cités (RG-01.3, RG-01.4).
- 14 types natifs, registre immuable, `decimal` en chaîne et stockage UTC (RG-01.5, RG-01.6).
- `QueryBuilder` SQL immuable, valeurs toujours liées.
- Objets de schéma immuables aux noms déterministes (RG-01.7), introspection par plateforme, `Comparator` et SQL de migration dans l'ordre RG-01.10, reconstruction de table SQLite (RG-01.11) ; aller-retour RG-01.8 vérifié sur 30 schémas.

### Corrigé

- SQLite : décimaux en `NUMERIC`, garde de 15 chiffres significatifs.

## [0.0.1] — 2026-10-18

Phase 0 : fondations.

### Ajouté

- Monorepo, CI sur PHP 8.4 et 8.5 et les 4 bases, split des paquets vers `prism-orm/*` (ADR 0004, ADR 0005).
- Une base de test créée et détruite par lancement (RG-00.1), spike des lazy ghosts PHP 8.4 (ADR 0001).
