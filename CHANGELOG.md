# Changelog

Toutes les évolutions notables de Prism, par version. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions [SemVer](https://semver.org/lang/fr/), en `0.x` jusqu'à la 1.0 (RT-16). Les règles citées (RG-xx, RT-xx) sont décrites dans `docs/ROADMAP.md`, les décisions (ADR) dans `docs/adr/`.

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
