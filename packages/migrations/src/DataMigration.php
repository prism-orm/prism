<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Migrations\Exception\IrreversibleMigrationException;
use Prism\Orm\EntityManagerInterface;

/**
 * Une migration de données (RG-06.8), nommée VersionYYYYMMDDHHMMSSData : elle s'exécute après
 * la migration de schéma de la même version, dans une transaction, avec l'EntityManager.
 *
 *     final class Version20261021143000Data extends DataMigration
 *     {
 *         public function run(EntityManagerInterface $em): void
 *         {
 *             foreach ($em->getRepository(Client::class)->findBy(['country' => null]) as $client) {
 *                 $client->country = 'FR';
 *             }
 *         }
 *     }
 *
 * Le flush est fait à la fin de run(). iterate() détache les entités à chaque lot (RG-03.10) :
 * pour modifier un grand volume, appeler $em->flush() dans la boucle, avant la fin du lot.
 *
 * Attention : l'EntityManager utilise le mapping actuel des entités, pas celui de l'époque de
 * la migration. Rejouée plus tard sur une base vide, une migration qui lit un champ supprimé
 * depuis échouera ; pour une migration qui doit durer, préférer le SQL de
 * $em->getConnection(), qui ne dépend pas des entités.
 *
 * @api
 */
abstract class DataMigration
{
    abstract public function run(EntityManagerInterface $em): void;

    /**
     * L'inverse de run() ; sans lui, la migration est irréversible (RG-06.7).
     *
     * @throws IrreversibleMigrationException
     */
    public function revert(EntityManagerInterface $em): void
    {
        throw IrreversibleMigrationException::forVersion(AbstractMigration::versionOf(static::class), 'it has no revert()');
    }

    public function getDescription(): string
    {
        return '';
    }

    /**
     * Vrai si revert() est écrit.
     *
     * @internal
     */
    public function isReversible(): bool
    {
        return self::class !== new \ReflectionMethod($this, 'revert')->getDeclaringClass()->getName();
    }

    /**
     * Le checksum d'une migration de données : celui de son fichier, puisqu'elle exécute du code
     * et non des opérations (RG-06.4). Un reformatage la marque donc comme modifiée.
     *
     * @internal
     */
    public function checksum(): string
    {
        $file = new \ReflectionClass($this)->getFileName();

        return false === $file ? '' : (string) hash_file('sha256', $file);
    }
}
