<?php

declare(strict_types=1);

namespace Prism\Orm\Tools;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Id\GenerationStrategyResolver;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Mapping\OnDelete;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\MetadataValidator;

/**
 * Traduit les metadata en Schema DBAL ; le Comparator et SchemaManager::migrate() l'appliquent.
 *
 * - Stratégie Auto (RG-02.4) : SEQUENCE sous PostgreSQL ({table}_id_seq), IDENTITY ailleurs.
 * - Une colonne de jointure a le type de l'identifiant visé, une clé étrangère et un index
 *   (unique pour un OneToOne), sauf si un index la couvre déjà.
 * - Un ManyToMany propriétaire produit sa table pivot.
 * - Une entité #[Tenanted] a sa colonne du tenant, indexée et en tête de ses index et de ses
 *   contraintes d'unicité (RG-08.1).
 * - Les contraintes d'unicité d'une entité #[SoftDeletable] ne couvrent que les lignes non
 *   supprimées (index partiel, RG-08.15).
 * - Les noms générés sont déterministes (RG-01.7) : le diff reste stable.
 *
 * @api
 */
final class SchemaTool
{
    private readonly MetadataValidator $validator;

    public function __construct(
        private readonly AbstractPlatform $platform,
        ?TypeRegistry $types = null,
    ) {
        $this->validator = new MetadataValidator($types);
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     *
     * @throws MappingException mapping invalide, ou impossible à traduire sur la plateforme
     */
    public function fromMetadata(array $metadata): Schema
    {
        $this->validator->assertValid($metadata);

        $byClass = [];
        foreach ($metadata as $class) {
            $byClass[$class->className] = $class;
        }

        $tables = [];
        $sequences = [];
        foreach ($metadata as $class) {
            $tables[] = $this->entityTable($class, $byClass);
            if (null !== $sequence = $this->sequence($class)) {
                $sequences[] = $sequence;
            }
        }
        foreach ($metadata as $class) {
            foreach ($class->getAssociations() as $association) {
                if (AssociationType::ManyToMany === $association->type && $association->isOwningSide()) {
                    $tables[] = $this->joinTable($class, $association, $byClass[$association->targetEntity]);
                }
            }
        }

        return new Schema($tables, $sequences);
    }

    /**
     * Stratégie effective sur la plateforme (Auto résolu).
     */
    public function resolveStrategy(?GenerationStrategy $strategy): ?GenerationStrategy
    {
        return GenerationStrategyResolver::resolve($strategy, $this->platform);
    }

    /**
     * @param ClassMetadata<object>                      $class
     * @param array<class-string, ClassMetadata<object>> $byClass
     */
    private function entityTable(ClassMetadata $class, array $byClass): Table
    {
        $identity = GenerationStrategy::Identity === $this->resolveStrategy($class->id->strategy);
        $columns = [];
        foreach ($class->getFields() as $field) {
            $columns[] = self::column($field, $identity && $field->fieldName === $class->id->fieldName);
        }
        foreach ($class->getEmbedded() as $embedded) {
            foreach ($embedded->getFields() as $field) {
                $columns[] = self::column($field, false);
            }
        }
        $joins = array_values(array_filter($class->getAssociations(), static fn (AssociationMapping $a): bool => $a->isToOne() && $a->isOwningSide()));
        foreach ($joins as $association) {
            $columns[] = self::joinColumn((string) $association->joinColumn, $byClass[$association->targetEntity]->getIdField(), $association->nullable);
        }

        $tenancy = $class->tenancy;
        if (null !== $tenancy && null === $tenancy->field) {
            $columns[] = new Column($tenancy->column, $tenancy->type, $tenancy->global);
        }

        $table = new Table($class->tableName, $columns, [$class->getIdField()->columnName]);

        // RG-08.15 : une ligne supprimée logiquement ne compte plus pour l'unicité
        $live = null === $class->behaviors->deletedAt ? null : $class->getField($class->behaviors->deletedAt)->columnName;
        foreach ($class->getFields() as $field) {
            if ($field->unique) {
                // unique par tenant : un numéro de facture se répète d'un tenant à l'autre
                $table = $table->withIndexOn(self::tenantFirst($class, [$field->columnName]), unique: true, whereNull: $live);
            }
        }
        foreach ($class->indexes as $index) {
            if ($index->unique && null !== $index->where) {
                throw MappingException::invalidMetadata($class->className, \sprintf('the unique constraint on "%s" has a "where" condition; partial indexes arrive with phase 8 (RG-08.15). Enforcing it without its condition would reject valid rows.', implode(', ', $index->fields)));
            }
            // un index non unique sans sa condition est plus large, jamais faux
            $table = $table->withIndexOn(self::tenantFirst($class, array_map(fn (string $field): string => $this->columnOf($class, $field), $index->fields)), $index->unique, $index->name, $index->unique ? $live : null);
        }
        if (null !== $tenancy && !self::isCovered($table, $tenancy->column)) {
            $table = $table->withIndexOn([$tenancy->column]);
        }

        foreach ($joins as $association) {
            $target = $byClass[$association->targetEntity];
            $column = (string) $association->joinColumn;
            $table = $table->withForeignKeyTo([$column], $target->tableName, [$target->getIdField()->columnName], self::action($association->onDelete), $association->foreignKeyName);
            // une contrainte unique déjà déclarée sur la colonne suffit (db:import la reprend sous son nom)
            if (AssociationType::OneToOne === $association->type && !self::isUnique($table, $column)) {
                $table = $table->withIndexOn([$column], unique: true);
            } elseif (!self::isCovered($table, $column)) {
                $table = $table->withIndexOn([$column]);
            }
        }

        return $table;
    }

    /**
     * @param ClassMetadata<object> $source
     * @param ClassMetadata<object> $target
     */
    private function joinTable(ClassMetadata $source, AssociationMapping $association, ClassMetadata $target): Table
    {
        $join = $association->joinTable ?? throw MappingException::invalidField($source->className, $association->fieldName, 'the owning side of a ManyToMany needs a join table.');
        $action = self::action($join->onDelete);

        return new Table($join->name, [
            self::joinColumn($join->joinColumn, $source->getIdField(), false),
            self::joinColumn($join->inverseJoinColumn, $target->getIdField(), false),
        ], [$join->joinColumn, $join->inverseJoinColumn])
            ->withForeignKeyTo([$join->joinColumn], $source->tableName, [$source->getIdField()->columnName], $action)
            ->withForeignKeyTo([$join->inverseJoinColumn], $target->tableName, [$target->getIdField()->columnName], $action)
            // la clé primaire couvre déjà la première colonne
            ->withIndexOn([$join->inverseJoinColumn]);
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function sequence(ClassMetadata $class): ?Sequence
    {
        if (GenerationStrategy::Sequence !== $this->resolveStrategy($class->id->strategy)) {
            return null;
        }

        return new Sequence(GenerationStrategyResolver::sequenceName($class, $this->platform));
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function columnOf(ClassMetadata $class, string $field): string
    {
        return $class->hasField($field) ? $class->getField($field)->columnName : (string) $class->getAssociation($field)->joinColumn;
    }

    private static function column(FieldMapping $field, bool $autoincrement): Column
    {
        return new Column($field->columnName, $field->type, $field->nullable, $field->default, $field->length, $field->precision, $field->scale, $autoincrement, $field->fixed);
    }

    // même type et même longueur que l'identifiant visé, jamais auto-incrémentée
    private static function joinColumn(string $name, FieldMapping $targetId, bool $nullable): Column
    {
        return new Column($name, $targetId->type, $nullable, length: $targetId->length, fixed: $targetId->fixed);
    }

    /**
     * RG-08.1 : la colonne du tenant en tête d'un index d'entité tenantée, sauf s'il la nomme déjà.
     *
     * @param ClassMetadata<object> $class
     * @param list<string>          $columns
     *
     * @return list<string>
     */
    private static function tenantFirst(ClassMetadata $class, array $columns): array
    {
        $tenant = $class->tenancy?->column;
        if (null === $tenant || \in_array(strtolower($tenant), array_map(strtolower(...), $columns), true)) {
            return $columns;
        }

        return [$tenant, ...$columns];
    }

    private static function isUnique(Table $table, string $column): bool
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->unique && [strtolower($column)] === array_map(strtolower(...), $index->columns)) {
                return true;
            }
        }

        return false;
    }

    private static function isCovered(Table $table, string $column): bool
    {
        if (0 === strcasecmp($table->primaryKey[0] ?? '', $column)) {
            return true;
        }
        foreach ($table->getIndexes() as $index) {
            if (0 === strcasecmp($index->columns[0], $column)) {
                return true;
            }
        }

        return false;
    }

    private static function action(OnDelete $onDelete): ForeignKeyAction
    {
        return match ($onDelete) {
            OnDelete::Restrict => ForeignKeyAction::Restrict,
            OnDelete::Cascade => ForeignKeyAction::Cascade,
            OnDelete::SetNull => ForeignKeyAction::SetNull,
            OnDelete::NoAction => ForeignKeyAction::NoAction,
        };
    }
}
