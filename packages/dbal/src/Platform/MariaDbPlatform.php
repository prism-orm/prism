<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\LockMode;

/**
 * @api
 */
final class MariaDbPlatform extends AbstractMySqlPlatform
{
    public function getName(): string
    {
        return 'MariaDB';
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    // une séquence MariaDB est une table : elle se renomme comme une table
    public function getRenameSequenceSql(string $from, string $to): string
    {
        return \sprintf('RENAME TABLE %s TO %s', $this->quoteIdentifier($from), $this->quoteIdentifier($to));
    }

    // FOR SHARE n'existe pas sous MariaDB
    public function getLockSql(LockMode $mode): string
    {
        return LockMode::PessimisticRead === $mode ? ' LOCK IN SHARE MODE' : parent::getLockSql($mode);
    }

    public function getSequenceNextValSql(string $sequence): string
    {
        return 'SELECT NEXTVAL(' . $this->quoteIdentifier($sequence) . ')';
    }

    /**
     * MariaDB stocke JSON en LONGTEXT + CHECK (json_valid(col)) : la contrainte distingue json de text.
     *
     * @internal un paramètre lié : le nom de la table
     */
    public function getListCheckConstraintsSql(): string
    {
        return 'SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    }

    /**
     * @internal
     */
    public function getListSequencesSql(): string
    {
        return "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'SEQUENCE' ORDER BY TABLE_NAME";
    }

    /**
     * Une séquence MariaDB se lit comme une table d'une ligne.
     *
     * @internal
     */
    public function getSequenceDefinitionSql(string $sequence): string
    {
        return 'SELECT start_value, increment FROM ' . $this->quoteIdentifier($sequence);
    }

    // type natif depuis MariaDB 10.7 : 16 octets, rendu sous forme canonique
    protected function getUuidDeclarationSql(): string
    {
        return 'UUID';
    }

    // ADR 0003 : première LTS avec le type VECTOR
    public function getMinimumVersion(): string
    {
        return '11.8.0';
    }
}
