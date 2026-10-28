# Changelog

Toutes les évolutions notables de Prism, par version. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions [SemVer](https://semver.org/lang/fr/), en `0.x` jusqu'à la 1.0 (RT-16). Les règles citées (RG-xx, RT-xx) sont décrites dans `docs/ROADMAP.md` et `docs/roadmap/`, les décisions (ADR) dans `docs/adr/`.

## [0.9.0] — à publier

Phase 9 : pilier 4, vector et recherche hybride. Stocker des embeddings à côté des données métier, les indexer, chercher par le sens et par les mots depuis le repository, et garder chaque vecteur à jour de son texte ; guide : [Vector search](website/guide/vector-search.md).

### Ajouté

- Type DBAL `vector` (ADR 0017) : MariaDB `VECTOR(n)`, PostgreSQL `vector(n)` (pgvector 0.8 exigé, extension créée avec le schéma), SQLite en texte avec distances calculées en PHP pour les tests ; refusé sous MySQL, qui n'a ni distance ni index vectoriel en Community. `Type::getParameterSql()` : MariaDB ne lit un vecteur lié qu'à travers `VEC_FromText()`.
- Index HNSW et plein texte : `#[VectorIndex(distance:, m:, efConstruction:)]`, `#[FullTextIndex(fields:, language:)]` (`FULLTEXT` sous MySQL et MariaDB, index GIN sur `to_tsvector()` sous PostgreSQL) ; introspection et aller-retour RG-01.8 sur les 4 bases ; `createVectorIndex()`, `createFullTextIndex()` et `db:migrations:diff` ; règle L20 du linter ; ce qu'une plateforme ne sait pas faire est refusé à la validation du mapping (RG-09.3).
- Value object `Vector` (`normalize()`, `cosine()`, `distance()`) ; dimension vérifiée avant l'envoi en base, `VectorDimensionMismatchException` (RG-09.1, RG-09.2) ; un champ `Vector` est paresseux, chargé par lot au premier accès, ou avec `->withVectors()` (RG-09.6).
- `nearest()` sur le repository : `list<ScoredResult>` (entité, distance, score), filtres et critères appliqués avant le classement ; `hnsw.iterative_scan` sous PostgreSQL et relance exacte sous MariaDB quand un filtre sélectif laisse l'index rendre trop peu de lignes (RG-09.4, RG-09.11). `VectorField::distanceTo()` en tri et en projection dans le QueryBuilder typé ; règle PHPStan `prism.nearest.notVector`.
- `hybridSearch()` : plein texte (un OU sur les mots, sur les 4 plateformes) et vecteurs fusionnés par Reciprocal Rank Fusion, `HybridOptions` (k, poids, candidats, champ, index), `HybridResult` avec ses deux rangs (RG-09.5).
- `#[EmbeddedVector(source:, model:)]` (ADR 0018) : colonnes `<colonne>_stale` et `<colonne>_model` ; vecteur périmé à l'INSERT et quand une source change, remis à un `EmbeddingScheduler` après le commit, jamais pendant la transaction ni après un rollback (RG-09.7) ; vecteur périmé exclu de `nearest()` sauf `includeStale` (RG-09.8) ; `EmbeddingUpdater` : calcul hors transaction, écriture seulement si les sources n'ont pas changé entre-temps, filtre du tenant ; `markEmbeddingsStale()` pour changer de modèle (RG-09.10).
- `EmbeddingProvider` (RG-09.9), `EmbeddingProviderRegistry`, `HashingEmbeddingProvider` (déterministe, sans réseau), planificateurs différé et synchrone, `EmbeddingException` ; `db:embeddings:compute` et `db:embeddings:status`.
- Paquet `prism-orm/embedding-openai` : `OpenAiCompatibleProvider` pour l'API `/v1/embeddings` (OpenAI, Mistral, Ollama, vLLM, LocalAI, Text Embeddings Inference), par lots, réponses vérifiées, préfixe par texte (`search_document: ` de nomic-embed-text).
- Bundle : configuration `prism.embeddings` (scheduler `auto`, `messenger`, `synchronous` ou `deferred`, taille de lot, fournisseurs `service` ou `openai`), tag `prism.embedding_provider` et `#[AsEmbeddingProvider]`, fournisseurs vérifiés au `cache:warmup` ; message `ComputeEmbedding` et son handler par lots de messages (`BatchHandlerInterface`), dans le tenant des lignes.
- Démo : recherche dans la documentation de Prism (page `/docs`), second EntityManager `docs` sur PostgreSQL avec pgvector, 324 sections, vecteurs `nomic-embed-text:v1.5` précalculés dans `demo/data` (`make embeddings-snapshot`), jeu de 60 questions annotées, `app:docs:evaluate`. Rappel@10 : `hybridSearch()` 0,922, vectoriel seul 0,903, plein texte seul 0,711.
- Linter de migrations, règle L21 : index vectoriel sur une table `#[Tenanted]` de MariaDB dont un tenant, parmi d'autres, dépasse 10 000 lignes ; `nearest()` filtré par ce tenant y retombe sur une recherche exacte (RG-09.11).
- `make bench-vector` : `nearest()` sur 100 000 vecteurs de 768 dimensions, p95 de 22 ms sous MariaDB (cache du graphe dimensionné) et de 31 ms sous PostgreSQL.

### Modifié

- `EntityManager` accepte en fin de constructeur un `EmbeddingScheduler` ; `MetadataFactory` et `MetadataValidator` un `EmbeddingProviderRegistry`.
- Dépendances : `symfony/http-client-contracts`, déclarée par `prism-orm/embedding-openai` seulement.

### Corrigé

- SQLite : une table créée par une migration avait perdu ses index vectoriels et plein texte.
- MariaDB : un vecteur dont le premier octet float32 vaut `0x5B` (« [ ») était lu comme du texte et refusé ; trouvé par `make bench-vector`.
- `nearest()` et `withVectors()` : l'UnitOfWork ne réencode plus chaque vecteur hydraté pour son snapshot (environ 2 ms par vecteur de 768 dimensions) ; un vecteur égal à sa relecture n'est plus réencodé au flush. `nearest()` passe de 42 à 22 ms au p95 sur 100 000 lignes MariaDB.
- L'`EmbeddingScheduler` est appelé à la fin de `flush()`, après `PostFlush` : un message traité en synchrone ne peut plus vider l'EntityManager au milieu du flush.

## [0.8.0] — 2026-10-27

Phase 8 : pilier 3, multi-tenancy et audit. Isoler les données de chaque client B2B sans oubli possible, et tracer chaque modification ; guide : [Multi-tenancy](website/guide/multi-tenancy.md) et [Audit and behaviors](website/guide/audit.md).

### Ajouté

- `#[Tenanted]` (ADR 0014) : colonne `tenant_id` (`integer`, `bigint` ou `uuid`) indexée et en tête des index et des contraintes d'unicité (RG-08.1) ; propriété du tenant facultative, remplie par l'ORM ; `#[Tenanted(global: true)]` pour les référentiels mixtes, lignes partagées lisibles par tous, écrites en mode système seulement.
- `TenantContext` et `StackTenantContext` : `runAs()`, `runAsSystem()` (RG-08.5), `reset()`. Sans tenant, toute opération sur une entité tenantée lève `MissingTenantException` : il n'y a pas de mode « tous les tenants » implicite (RG-08.4).
- `TenantFilter` sur chaque lecture et écriture de l'ORM : `find()`, `findBy()`, `count()`, `iterate()`, `refresh()`, `lock()`, ghosts, batch fetching, collections et `EXTRA_LAZY`, QueryBuilder typé (racine dans le `WHERE`, entité jointe dans son `ON`), `Paginator`, projections (RG-08.2). Isolation : 36 scénarios (42 cas) sur les 4 bases, aucune fuite.
- `tenant_id` écrit par l'UnitOfWork, `TenantMismatchException` pour un autre tenant ou un tenant changé (RG-08.3) ; relations entre tenants refusées au flush, même en mode système (`CrossTenantRelationException`, RG-08.6).
- Identity map vidée à chaque changement de tenant ; changement refusé avec des modifications non flushées (`TenantSwitchException`, RG-08.8) ; `TenantSwitchListener`, `TenantScope`.
- `TenantGuard` et `QueryGuard` (DBAL) : le SQL brut sur une table tenantée doit filtrer `tenant_id` ; refus en cas de doute (sous-requête, `WITH`, `UNION`, découpage incertain), exception ou log critique (RG-08.7).
- Filtres d'entité : `EntityFilter`, `FilterCollection`, tag `prism.filter` du bundle.
- `#[Timestampable]` et `#[Blameable]` (ADR 0015) : heure UTC (horloge PSR-20) et auteur (`ActorProvider`) à l'INSERT, puis à chaque UPDATE qui change un autre champ (RG-08.13, RG-08.16).
- `#[SoftDeletable]` : `remove()` écrit la date de suppression, `remove($entity, hardDelete: true)` supprime la ligne, `EntityManager::restore()` ; lignes supprimées exclues des lectures, `->withDeleted()` ; une référence vers une ligne supprimée reste lisible ; unicité sur les seules lignes non supprimées (RG-08.14, RG-08.15).
- `#[Auditable]` : table `prism_audit_log` gérée par `db:migrations:diff`, une ligne par entité modifiée dans la transaction du flush, changeset, tenant, auteur, identifiant de requête, mode système ; champs `sensitive` masqués, `exclude` ; `AuditReader` (historique, état à une date), `AuditRecordedEvent`, `db:audit:purge` (RG-08.5, RG-08.10 à RG-08.12).
- Bundle : configuration `prism.tenancy`, `TenantResolver` (en-tête, sous-domaine et `TenantLoader`, utilisateur `TenantAwareUserInterface`), contexte remis à zéro par `kernel.reset`, commandes `db:*` en mode système (aussi dans `bin/prism`), auteur depuis Security ou la commande, `X-Request-Id`, horloge de Symfony, panneaux « Tenant » et « Audit » du profiler.
- DBAL : index partiel « colonne IS NULL » (`Index::$whereNull`), en index partiel sous PostgreSQL et SQLite, en colonne virtuelle masquée sous MySQL et MariaDB ; `Configuration::withGuards()`.
- Migrations : `createIndex(whereNull:)`, généré par `db:migrations:diff` pour l'unicité avec suppression logique.
- Démo multi-sociétés : `acme.localhost` et `globex.localhost` (`TenantLoader` sur le sous-domaine), catalogue partagé, clients supprimés logiquement, commandes horodatées, factures auditées ; 14 tests, worker FrankenPHP sur 10 000 requêtes qui alternent les deux sociétés, sans fuite ni croissance de mémoire.
- `make bench-tenancy` : surcoût du filtre tenant sur une lecture de 10 000 entités (critère < 5 %).

### Modifié

- `EntityManagerInterface` : `remove()` gagne `hardDelete`, et la nouvelle méthode `restore()` ; une implémentation tierce de l'interface doit les ajouter (ADR 0015).
- L'ORM lit toujours la colonne du tenant, gardée dans le snapshot de l'UnitOfWork, jamais sur l'entité.
- `EntityManager` accepte en fin de constructeur un `TenantContext`, une `FilterCollection`, une horloge, un `ActorProvider` et un `RequestIdProvider`.
- Dépendances : `psr/log` et `psr/clock` déclarés par `prism-orm/orm`.
- Mesures : couverture `orm` 96,2 %, `dbal` 88,9 %, `bundle` 95,1 % ; MSI couvert `UnitOfWork` 85 %, `Comparator` 94 %, `CommitOrderCalculator` 97 %, `BatchLoader` 94 %, `TenantFilter` 100 %, analyse de la garde 86 %.

### Documentation

Phase 10a, avancée avant la phase 9 (ADR 0016) : un site de documentation complet, en anglais, dont chaque exemple est exécuté.

- Site VitePress (`website/`, `make docs-dev`, `make docs-build`, job CI `website`) : démarrer, guides du cœur et des piliers 1 à 3, Symfony, recettes ERP (factures, stocks, multi-sociétés), migrer depuis Doctrine, référence. Les pages d'un paquet restent dans `packages/<paquet>/docs`, publiées aussi par le split.
- Exemples exécutés en CI sur les 4 bases (RG-10.3), dans un processus chacun, sur les données de la démo, sous la garde du SQL brut ; une page peut s'exécuter en séquence (`examples: sequence`) ; les blocs `no-run` sont listés par la CI.
- Référence vérifiée contre le code dans les deux sens : attributs et paramètres, options de `prism.yaml`, commandes, événements, exceptions, erreurs PHPStan ; liens internes et ancres vérifiés.
- `symfony.md` et `commands.md` traduits en anglais ; exemple `selectInto(CustomerRevenue::class)` corrigé (paramètre `lastInvoice`).
- Écarts trouvés en écrivant, reportés au backlog (phase 10b) : deux enums `Order`, docblock de `#[Index(where:)]`, `contains()` en `Fetch::ExtraLazy`, commentaire en français de `db:import`.

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
