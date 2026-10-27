<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Support;

use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Mapping\Entity;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MappingSeverity;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Metadata\MetadataValidator;
use Prism\Orm\Query\Metamodel\MetamodelAutoloader;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantGuard;

/**
 * Le contexte d'un exemple de la documentation, dans son propre processus (Examples/prelude.php) :
 * la base de la démo remplie par ExamplesTest, le tenant de la société « acme », la garde du SQL
 * brut, une transaction annulée à la fin ; les entités déclarées par l'exemple sont validées avec
 * celles de la démo.
 *
 * @internal
 */
final class ExampleRuntime
{
    public const string METAMODEL_ENV = 'PRISM_DOCS_METAMODEL';

    public const string TENANT_ENV = 'PRISM_DOCS_TENANT';

    public static function entityDir(): string
    {
        return DocPages::root() . '/demo/src/Entity';
    }

    /**
     * @return array{EntityManager, Connection, StackTenantContext}
     */
    public static function start(): array
    {
        // une notice ou un avertissement fait échouer l'exemple
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (0 === (error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $metamodel = getenv(self::METAMODEL_ENV);
        $url = getenv('DATABASE_URL');
        $tenant = getenv(self::TENANT_ENV);
        if (!\is_string($metamodel) || !\is_string($url) || !\is_string($tenant)) {
            throw new \LogicException(\sprintf('DATABASE_URL, %s and %s are set by ExamplesTest.', self::METAMODEL_ENV, self::TENANT_ENV));
        }
        MetamodelAutoloader::register($metamodel);

        // comme dans une application : la garde du SQL brut sur la connexion (RG-08.7), en mode exception
        $tenants = new StackTenantContext();
        $metadata = new MetadataFactory(new AttributeDriver([self::entityDir()]));
        $connection = DriverManager::fromUrl($url, new Configuration(guards: [new TenantGuard($metadata, $tenants)]));
        $em = new EntityManager($connection, $metadata, tenantContext: $tenants);
        // aucune requête avant l'exemple : la garde chargerait les entités de la démo avant celles qu'il redéclare
        $tenants->setTenantId((int) $tenant);

        $connection->beginTransaction();
        $before = get_declared_classes();
        register_shutdown_function(static function () use ($connection, $before): void {
            $fatal = error_get_last();
            if (null !== $fatal && 0 !== ($fatal['type'] & (\E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR))) {
                return;
            }
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $errors = self::validateDeclaredEntities(array_diff(get_declared_classes(), $before), $connection);
            if ([] !== $errors) {
                fwrite(\STDERR, "Invalid mapping in the example:\n- " . implode("\n- ", $errors) . "\n");
                exit(1);
            }
        });

        return [$em, $connection, $tenants];
    }

    /**
     * @param array<string> $declared
     *
     * @return list<string>
     */
    private static function validateDeclaredEntities(array $declared, Connection $connection): array
    {
        $driver = new AttributeDriver([self::entityDir()]);
        $classes = $driver->getAllClassNames();
        $mine = false;
        foreach ($declared as $class) {
            if (class_exists($class) && new \ReflectionClass($class)->isUserDefined() && [] !== new \ReflectionClass($class)->getAttributes(Entity::class)) {
                $mine = true;
                // la démo complète l'exemple : une classe qu'il redéclare (App\Entity\Order) remplace la sienne
                if (!\in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }
        if (!$mine) {
            return [];
        }

        $metadata = [];
        foreach ($classes as $class) {
            $metadata[] = $driver->loadMetadata($class);
        }
        $errors = [];
        foreach (new MetadataValidator($connection->getTypeRegistry())->validate($metadata) as $error) {
            if (MappingSeverity::Error === $error->severity) {
                $errors[] = (string) $error;
            }
        }

        return $errors;
    }
}
