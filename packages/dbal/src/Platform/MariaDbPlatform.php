<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\LockMode;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\VectorDistance;

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

    public function supportsVectors(): bool
    {
        return true;
    }

    // un paramètre lié en texte n'est pas converti implicitement en VECTOR, binaire compris
    public function getVectorParameterSql(string $placeholder): string
    {
        return 'VEC_FromText(' . $placeholder . ')';
    }

    public function getVectorDistanceSql(VectorDistance $distance, string $column, string $vector): string
    {
        return match ($distance) {
            VectorDistance::Cosine => \sprintf('VEC_DISTANCE_COSINE(%s, %s)', $column, $vector),
            VectorDistance::Euclidean => \sprintf('VEC_DISTANCE_EUCLIDEAN(%s, %s)', $column, $vector),
            VectorDistance::InnerProduct => throw InvalidSchemaException::unsupportedFeature($this->getName(), 'the inner product distance (cosine and euclidean only)'),
        };
    }

    // un seul index vectoriel par table, sur une colonne NOT NULL (vérifié par le validateur du mapping)
    protected function getCreateVectorIndexSql(string $table, Index $index): string
    {
        $distance = match ($index->distance) {
            VectorDistance::Cosine => 'cosine',
            VectorDistance::Euclidean => 'euclidean',
            VectorDistance::InnerProduct, null => throw InvalidSchemaException::unsupportedFeature($this->getName(), 'the inner product distance (cosine and euclidean only)'),
        };

        return \sprintf(
            'CREATE VECTOR INDEX %s ON %s (%s)%s DISTANCE=%s',
            $this->quoteIdentifier($index->name),
            $this->quoteIdentifier($table),
            $this->quoteIdentifiers($index->columns),
            null === $index->m ? '' : ' M=' . $index->m,
            $distance,
        );
    }

    /**
     * Le CREATE TABLE tel que MariaDB le rend : M et DISTANCE d'un index vectoriel n'existent
     * qu'ici.
     *
     * @internal
     */
    public function getShowCreateTableSql(string $table): string
    {
        return 'SHOW CREATE TABLE ' . $this->quoteIdentifier($table);
    }

    protected function getVectorDeclarationSql(Column $column): string
    {
        return \sprintf('VECTOR(%d)', $column->length);
    }

    // ADR 0003 : première LTS avec le type VECTOR
    public function getMinimumVersion(): string
    {
        return '11.8.0';
    }
}
