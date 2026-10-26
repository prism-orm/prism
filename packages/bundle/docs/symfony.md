# Prism dans Symfony

Le bundle `prism-orm/bundle` remplace Doctrine dans une application Symfony 7.4 ou 8 : configuration, injection, contrôleurs, migrations, profiler, tests. Chaque exemple vient de l'application de démonstration de Prism, un ERP minimal écrit avec Prism seul.

Les commandes `db:*` sont décrites dans la [référence des commandes](commands.md).

## Installer

```bash
composer require prism-orm/bundle
composer require --dev symfony/maker-bundle prism-orm/test-pack
```

La recette Flex enregistre le bundle, crée `config/packages/prism.yaml`, `src/Entity/` et `migrations/`, et ajoute `DATABASE_URL` au `.env`. Sans recette (sans Flex), ajoutez `Prism\Bundle\PrismBundle::class => ['all' => true]` à `config/bundles.php` : sans `prism.yaml`, Prism lit `DATABASE_URL`.

L'URL suit le format de Symfony ; `serverVersion` est obligatoire :

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16"
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.4"
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=11.8.0-MariaDB"
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db?serverVersion=3.46.0"
```

## Configurer

Une base, la forme courte :

```yaml
# config/packages/prism.yaml
prism:
    url: '%env(resolve:DATABASE_URL)%'
    entity_dirs: ['%kernel.project_dir%/src/Entity']
    migrations:
        dir: '%kernel.project_dir%/migrations'
        namespace: 'App\Migrations'
```

Plusieurs bases : des connexions et des EntityManagers nommés, le premier déclaré étant celui par défaut. Chaque EntityManager a ses propres migrations (`migrations/Billing` et `App\Migrations\Billing` par défaut) :

```yaml
prism:
    connections:
        default: { url: '%env(resolve:DATABASE_URL)%' }
        billing: { url: '%env(resolve:BILLING_DATABASE_URL)%' }
    entity_managers:
        default: { entity_dirs: ['%kernel.project_dir%/src/Entity'] }
        billing: { entity_dirs: ['%kernel.project_dir%/src/Billing/Entity'], batch_size: 50 }
```

| Option | Défaut | Rôle |
| --- | --- | --- |
| `entity_managers.*.connection` | la connexion du même nom, ou l'unique connexion | La connexion de l'EntityManager |
| `entity_managers.*.batch_size` | `100` | Taille des lots du batch fetching (RG-04.11) |
| `default_entity_manager` | le premier déclaré | Celui derrière `EntityManagerInterface` |
| `connection_idle_ttl` | `60` | Mode worker : secondes d'inactivité avant de tester la connexion (`0` : à chaque requête) |
| `metamodel.dir` | `%kernel.cache_dir%/prism/metamodel` | Dossier du metamodel, un sous-dossier par EntityManager |

Une option inconnue est refusée au démarrage, avec un message qui dit laquelle.

## Créer des entités

```bash
bin/console make:entity Order
```

`make:entity` crée l'entité et son repository, puis ajoute champs et relations à la demande. Pour une relation, il propose le côté inverse et écrit les deux (`inversedBy`, `mappedBy`) ; une collection est initialisée dans le constructeur. Sur une entité existante, il n'ajoute que les nouveaux membres : votre code et vos commentaires sont gardés.

```php
#[Prism\Entity(repositoryClass: OrderRepository::class)]
final class Order
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20)]
    public string $reference;

    #[Prism\ManyToOne(inversedBy: 'orders')]
    public Customer $customer;

    /** @var Collection<int, OrderLine> */
    #[Prism\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order')]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
```

Les entités `final` et les propriétés `readonly` sont supportées : Prism n'utilise pas de proxy. À côté de DoctrineBundle (pendant une migration), les commandes s'appellent `make:prism:entity` et `make:prism:migration`.

## Migrations

```bash
bin/console make:migration            # ou db:migrations:diff
bin/console db:migrations:lint        # les opérations dangereuses (19 règles)
bin/console db:migrations:migrate
```

Une migration décrit des opérations portables : la même s'exécute sur les 4 bases. Plusieurs EntityManagers : `--em=billing`.

## Injecter

`EntityManagerInterface`, `Connection` et les repositories s'injectent par autowiring ; un EntityManager nommé, par le nom de l'argument :

```php
public function __construct(
    private EntityManagerInterface $em,               // celui par défaut
    private EntityManagerInterface $billingEntityManager,
    private OrderRepository $orders,                   // même instance que $em->getRepository(Order::class)
) {}
```

Un repository métier étend `EntityRepository` et atteint son EntityManager par `getEntityManager()`.

## Interroger sans chaîne de nom de champ

Le metamodel (une classe `Order_` par entité) est généré automatiquement : en dev à chaque modification d'entité, en prod au `cache:warmup`. Les requêtes sont vérifiées par PHPStan :

```php
$o = Order_::as('o');
$orders = $em->query($o)->orderBy($o->placedAt->desc())->paginate($page, 20);

$i = Invoice_::as('i');
$o = $i->order->as('o');
$c = $o->customer->as('c');
$rows = $em->query($i)->join($o)->join($c)
    ->groupBy($c->id, $c->name)
    ->selectInto(CustomerRevenue::class, customer: $c->name, invoices: $i->id->count(), total: $i->total->sum())
    ->getResult();
```

Les relations sont chargées par lot (pilier 1) : dans la démo, 20 commandes, leur client, leur facture et leurs lignes coûtent 5 requêtes, quel que soit le nombre de commandes.

## Dans les contrôleurs

Un argument typé par une entité est chargé depuis la route : `{id}`, ou le paramètre qui porte le nom de l'argument. Une entité absente donne une 404 ; un argument nullable reçoit `null`.

```php
#[Route('/orders/{id}')]
public function show(Order $order): Response

#[Route('/invoices/{number}')]
public function byNumber(#[MapEntity(mapping: ['number' => 'number'])] Invoice $invoice): Response
```

`#[MapEntity]` accepte aussi `id:`, `entityManager:` et `disabled: true`.

## Événements et listeners

Chaque EntityManager a son dispatcher : les événements Prism (`PrePersistEvent`, `PostFlushEvent`…) s'écoutent avec `#[AsPrismListener]`, pas `#[AsEventListener]`.

```php
#[AsPrismListener(entityManager: 'billing')]
final class InvoiceAudit
{
    public function __invoke(PostFlushEvent $event): void {}
}
```

Un listener d'entité, cité dans `#[EntityListeners]`, devient un service avec ses dépendances par `#[AsEntityListener]`. Un type personnalisé (une classe qui étend `Type`) est enregistré automatiquement.

## Validation et sécurité

```php
#[UniqueEntity(fields: ['number'])]
final class Invoice {}
```

```yaml
# config/packages/security.yaml
security:
    providers:
        users:
            prism: { class: App\Entity\User, property: email }
```

Sans `property`, le repository implémente `Prism\Bundle\Security\UserLoaderInterface`. Le hash d'un mot de passe est mis à niveau par le repository s'il implémente `PasswordUpgraderInterface`.

## Multi-tenancy

Une entité `#[Tenanted]` reçoit une colonne `tenant_id`, et **toute** lecture et écriture de Prism la filtre : `find()`, `findBy()`, le QueryBuilder typé, les relations paresseuses, le batch fetching, les collections, la pagination. Sans tenant, toute opération sur une entité tenantée lève une exception : il n'existe pas de mode « tous les tenants » implicite.

```php
#[Prism\Entity]
#[Prism\Tenanted]
final class Invoice
{
    // ...
}
```

### Configurer

```yaml
prism:
    tenancy:
        type: integer          # integer, bigint ou uuid : la colonne tenant_id de toutes les entités
        resolver:
            header: X-Tenant-Id
        # guard: log           # par défaut : exception en debug, log critique sinon
```

Le tenant d'une requête vient d'**une** de ces sources :

| `resolver` | Source | Quand |
| --- | --- | --- |
| `header: X-Tenant-Id` | un en-tête | avant le firewall ; **uniquement derrière une passerelle qui pose l'en-tête elle-même** |
| `subdomain: example.com` | `acme` dans `acme.example.com` | avant le firewall |
| `user: true` | l'utilisateur connecté, qui implémente `TenantAwareUserInterface` | après le firewall |

Un sous-domaine est un nom, pas un identifiant : un service `TenantLoader` fait la traduction (`loader: App\Tenancy\SlugLoader`). Sans `resolver`, l'application pose le tenant elle-même :

```php
public function __construct(private readonly StackTenantContext $tenants) {}

// ...
$this->tenants->setTenantId($company->getId());
```

Avec la stratégie `user`, l'entité utilisateur ne doit pas être tenantée : le firewall la charge avant que le tenant soit connu.

### Changer de tenant, administrer

```php
$tenants->runAs(42, fn () => $reports->generate());                  // le tenant 42, le temps du callback
$total = $tenants->runAsSystem(fn () => $invoices->count());          // tous les tenants, explicitement
```

À chaque changement de tenant, l'EntityManager est vidé : aucune entité ne passe d'un tenant à l'autre. **Les entités chargées avant un `runAs()` ou un `runAsSystem()` sont donc détachées ensuite** ; rechargez-les. S'il reste des modifications non flushées, le changement est refusé (`TenantSwitchException`) ; flushez avant d'entrer, et avant de sortir.

Une relation entre deux entités de tenants différents est refusée au flush, même en mode système. Une ligne d'une entité `#[Tenanted(global: true)]` sans tenant est partagée : tous les tenants la lisent, seul le mode système l'écrit.

Les commandes `db:*` s'exécutent en mode système : ce sont des outils d'administration de toute la base. Dans une migration de données, écrivez dans un `runAs()` par tenant.

### SQL brut

Le SQL que vous écrivez vous-même passe par une garde : une requête sur une table tenantée sans condition sur `tenant_id` est refusée en dev et en test, et journalisée en log critique en prod.

```php
$connection->executeQuery('SELECT id FROM invoice WHERE tenant_id = ? AND total > ?', [$tenantId, 1000]); // accepté
$connection->executeQuery('SELECT id FROM invoice WHERE total > ?', [1000]);                            // refusé
```

En cas de doute, la garde refuse : sous-requête, `WITH`, `UNION`, `INSERT … SELECT` sur une table tenantée, `TRUNCATE`. Elle vérifie la présence de la condition, pas la valeur que vous liez. En mode système, elle laisse tout passer.

### Filtres

Un service qui implémente `EntityFilter` ajoute sa condition à chaque table d'entité, comme celle du tenant :

```php
final class ActiveCustomersFilter implements EntityFilter
{
    public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression
    {
        return Customer::class === $metadata->className ? $target->expr()->eq($target->column('active'), true) : null;
    }
}
```

Il est autoconfiguré (tag `prism.filter`) ; `name` et `entity_manager` dans le tag le nomment ou le limitent à un EntityManager. Le filtre du tenant, lui, ne se désactive pas.

### Profiler et tests

En dev, le panneau **Tenant** montre le tenant de la requête, comment il a été résolu, et les passages en `runAs()` et `runAsSystem()`.

Dans un test, posez le tenant avant d'utiliser les entités :

```php
static::getContainer()->get(StackTenantContext::class)->setTenantId(1);
```

## Profiler

En dev, le panneau Prism montre les requêtes et leur durée, les **N+1 détectés avec la ligne de votre code qui les provoque**, les chargements par lot et les changesets de chaque flush. En prod, ni le panneau, ni l'enregistrement des requêtes, ni le détecteur n'existent.

## Mode worker et Messenger

Sous FrankenPHP ou RoadRunner, rien à faire : entre deux requêtes, les EntityManagers sont vidés, une transaction restée ouverte est annulée, et une connexion perdue est reconnectée au début de la requête suivante. La démo sert 10 000 requêtes d'un seul worker sans croissance de mémoire (`make worker-test`).

Pour Messenger, le middleware `prism_reset` fait de même entre deux messages :

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware: [prism_reset]
```

## Tester

```php
final class OrderPagesTest extends WebTestCase
{
    use ResetDatabase;          // schéma créé une fois, une transaction annulée par test
    use QueryCountAssertions;

    public function testList(): void
    {
        $client = static::createClient();
        OrderFactory::new()->many(30);

        $this->assertQueryCount(5, fn () => $client->request('GET', '/orders'));
    }
}
```

Une factory décrit une entité « normale » ; chaque test ne précise que ce qui compte pour lui :

```php
final class OrderFactory extends Factory
{
    protected static function class(): string { return Order::class; }

    protected function defaults(): array
    {
        return ['reference' => fn (int $n) => sprintf('ORD-%05d', $n), 'customer' => CustomerFactory::new()];
    }
}
```

`#[WithRealTransactions]` désactive la transaction englobante pour un test qui a besoin de vrais commits ; sous MySQL et MariaDB, c'est aussi le cas d'un test qui exécute du DDL.

## Limites connues (0.7)

- Un type personnalisé convertit ses valeurs, mais ne sait pas encore déclarer sa colonne (`db:schema:create`).
- Un EntityManager accepte une entité d'un autre EntityManager et l'interroge dans sa propre base ; le resolver des contrôleurs, `#[UniqueEntity]` et le test-pack choisissent, eux, le bon.
- L'audit arrive en 0.8, la recherche vectorielle en 0.9.
