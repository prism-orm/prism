<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata\Naming;

/**
 * Noms de tables et de colonnes déduits des classes et des propriétés quand le mapping ne les
 * donne pas.
 *
 * @api
 */
interface NamingStrategy
{
    /**
     * @param class-string $class
     */
    public function classToTableName(string $class): string;

    public function propertyToColumnName(string $property): string;

    /** Préfixe des colonnes d'un embeddable (« billing_address_ »). */
    public function embeddedColumnPrefix(string $property): string;

    /** Colonne de jointure d'une relation to-one (« client_id »). */
    public function joinColumnName(string $property, string $referencedColumn = 'id'): string;

    /**
     * Table pivot d'un ManyToMany, construite par le côté propriétaire (« invoice_tag »).
     *
     * @param class-string $owningClass
     * @param class-string $targetClass
     */
    public function joinTableName(string $owningClass, string $targetClass): string;

    /**
     * Colonne d'une table pivot qui référence une entité (« invoice_id »).
     *
     * @param class-string $class
     */
    public function joinKeyColumnName(string $class, string $referencedColumn = 'id'): string;
}
