<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Support;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * Schémas de référence du test aller-retour RG-01.8 (critère de sortie : 30 schémas).
 * Chaque schéma isole un cas ; les noms de tables sont préfixés « ref_ » pour le nettoyage.
 *
 * @internal
 */
final class ReferenceSchemas
{
    /**
     * @return array<string, Schema>
     */
    public static function all(bool $withSequences): array
    {
        $schemas = [
            'every type' => new Schema([self::everyType()]),
            'nullable and defaults' => new Schema([self::defaults()]),
            'autoincrement primary key' => new Schema([new Table('ref_auto', [new Column('id', 'bigint', autoincrement: true), new Column('label', 'string', length: 50)], ['id'])]),
            'reserved words' => new Schema([new Table('ref_order', [
                new Column('group', 'integer'),
                new Column('user', 'string', length: 30),
                new Column('select', 'boolean', default: true),
                new Column('Mixed Case', 'string', nullable: true, length: 10),
            ], ['group'])->withIndexOn(['user', 'select'])]),
            'indexes' => new Schema([new Table('ref_indexed', [
                new Column('id', 'integer'),
                new Column('email', 'string', length: 180),
                new Column('tenant_id', 'integer'),
                new Column('created_on', 'date_immutable'),
            ], ['id'])->withIndexOn(['email'], unique: true)->withIndexOn(['tenant_id', 'created_on'])->withIndexOn(['tenant_id', 'email'], unique: true)]),
            'composite primary key with cascades' => new Schema([...self::clientAndInvoice(), new Table('ref_invoice_tag', [new Column('invoice_id', 'bigint'), new Column('tag', 'string', length: 30)], ['invoice_id', 'tag'])
                ->withForeignKeyTo(['invoice_id'], 'ref_invoice', ['id'], ForeignKeyAction::Cascade)]),
            'foreign key set null' => new Schema([...self::clientAndInvoice(ForeignKeyAction::SetNull)]),
            'foreign key restrict' => new Schema([...self::clientAndInvoice(ForeignKeyAction::Restrict)]),
            'cross references' => new Schema([
                new Table('ref_a', [new Column('id', 'integer'), new Column('b_id', 'integer', nullable: true)], ['id'])->withForeignKeyTo(['b_id'], 'ref_b', ['id'], ForeignKeyAction::SetNull),
                new Table('ref_b', [new Column('id', 'integer'), new Column('a_id', 'integer', nullable: true)], ['id'])->withForeignKeyTo(['a_id'], 'ref_a', ['id'], ForeignKeyAction::SetNull),
            ]),
            'fixed length' => new Schema(self::currencyAndAccount('EUR')),
            'self reference' => new Schema([new Table('ref_category', [new Column('id', 'integer'), new Column('parent_id', 'integer', nullable: true), new Column('name', 'string', length: 80)], ['id'])
                ->withForeignKeyTo(['parent_id'], 'ref_category', ['id'], ForeignKeyAction::Cascade)]),
        ];

        if ($withSequences) {
            $schemas['sequence'] = new Schema([new Table('ref_seq_owner', [new Column('id', 'bigint')], ['id'])], [new Sequence('ref_invoice_number_seq', 1000, 10)]);
        }

        return $schemas;
    }

    /**
     * Évolutions A → B du test RG-01.8 : diff(introspect(apply(diff(A, B))), B) doit être vide.
     *
     * @return array<string, array{Schema, Schema}>
     */
    public static function evolutions(bool $withSequences): array
    {
        $client = new Table('ref_client', [new Column('id', 'bigint'), new Column('name', 'string', length: 100)], ['id']);
        $invoice = new Table('ref_invoice', [
            new Column('id', 'bigint'),
            new Column('client_id', 'bigint', nullable: true),
            new Column('number', 'string', length: 20),
            new Column('qty', 'integer', default: 1),
            new Column('total', 'decimal', default: '0.00', precision: 10, scale: 2),
        ], ['id']);
        $base = new Schema([$client, $invoice]);
        $with = static fn (Table ...$tables): Schema => array_reduce($tables, static fn (Schema $s, Table $t): Schema => $s->withTable($t), $base);
        $linked = $with($invoice->withForeignKeyTo(['client_id'], 'ref_client', ['id'], ForeignKeyAction::Cascade));

        $evolutions = [
            'add nullable column' => [$base, $with($invoice->withColumn(new Column('note', 'string', nullable: true, length: 200)))],
            'add not null column with default' => [$base, $with($invoice->withColumn(new Column('status', 'string', default: 'draft', length: 20)))],
            'drop column' => [$base, $with($invoice->withoutColumn('qty'))],
            'widen string' => [$base, $with($invoice->withColumn(new Column('number', 'string', length: 60)))],
            'make column not null' => [$base, $with($invoice->withColumn(new Column('client_id', 'bigint')))],
            'make column nullable' => [$base, $with($invoice->withColumn(new Column('number', 'string', nullable: true, length: 20)))],
            'change default' => [$base, $with($invoice->withColumn(new Column('qty', 'integer', default: 10)))],
            'drop default' => [$base, $with($invoice->withColumn(new Column('qty', 'integer')))],
            'integer to bigint' => [$base, $with($invoice->withColumn(new Column('qty', 'bigint', default: 1)))],
            'decimal precision and scale' => [$base, $with($invoice->withColumn(new Column('total', 'decimal', default: '0.000', precision: 14, scale: 3)))],
            'add composite index' => [$base, $with($invoice->withIndexOn(['client_id', 'number']))],
            'drop index' => [$with($invoice->withIndexOn(['number'])), $base],
            'index becomes unique' => [$with($invoice->withIndexOn(['number'], name: 'ref_number_idx')), $with($invoice->withIndexOn(['number'], unique: true, name: 'ref_number_idx'))],
            'add foreign key' => [$base, $linked],
            'change on delete action' => [$linked, $with($invoice->withForeignKeyTo(['client_id'], 'ref_client', ['id'], ForeignKeyAction::SetNull))],
            'drop foreign key' => [$linked, $base],
            'add referencing table' => [$base, $base->withTable(new Table('ref_line', [new Column('id', 'bigint'), new Column('invoice_id', 'bigint')], ['id'])->withForeignKeyTo(['invoice_id'], 'ref_invoice', ['id'], ForeignKeyAction::Cascade))],
            'drop referenced table' => [$linked, new Schema([$invoice])],
            'widen primary key' => [$base, $with($invoice->withPrimaryKey(['id', 'number']))],
            'string to text' => [$base, $with($client->withColumn(new Column('name', 'text')))],
            'string becomes fixed' => [$base, $with($client->withColumn(new Column('name', 'string', length: 100, fixed: true)))],
            // la clé étrangère reste en place : le type de la colonne ne doit pas changer (CHAR → VARCHAR)
            'default of a fixed foreign key column' => [new Schema(self::currencyAndAccount('EUR')), new Schema(self::currencyAndAccount(null))],
        ];

        if ($withSequences) {
            $evolutions['sequence increment'] = [$base->withSequence(new Sequence('ref_evolution_seq', 1, 1)), $base->withSequence(new Sequence('ref_evolution_seq', 1, 5))];
        }

        return $evolutions;
    }

    public static function everyType(): Table
    {
        return new Table('ref_types', [
            new Column('id', 'integer'),
            new Column('c_string', 'string', length: 120),
            new Column('c_string_default_length', 'string', nullable: true),
            new Column('c_text', 'text', nullable: true),
            new Column('c_smallint', 'smallint'),
            new Column('c_bigint', 'bigint', nullable: true),
            new Column('c_boolean', 'boolean'),
            new Column('c_decimal', 'decimal', precision: 12, scale: 2),
            new Column('c_float', 'float', nullable: true),
            new Column('c_datetime', 'datetime_immutable'),
            new Column('c_date', 'date_immutable', nullable: true),
            new Column('c_time', 'time_immutable', nullable: true),
            new Column('c_json', 'json', nullable: true),
            new Column('c_uuid', 'uuid', nullable: true),
            new Column('c_binary', 'binary', nullable: true, length: 64),
            new Column('c_blob', 'binary', nullable: true),
        ], ['id']);
    }

    /**
     * Une clé primaire CHAR(3) et la clé étrangère CHAR(3) qui la vise, avec un défaut.
     *
     * @return list<Table>
     */
    private static function currencyAndAccount(?string $default): array
    {
        return [
            new Table('ref_currency', [new Column('code', 'string', length: 3, fixed: true), new Column('hash', 'binary', nullable: true, length: 16, fixed: true)], ['code']),
            new Table('ref_account', [new Column('id', 'integer'), new Column('currency_code', 'string', default: $default, length: 3, fixed: true)], ['id'])
                ->withIndexOn(['currency_code'])
                ->withForeignKeyTo(['currency_code'], 'ref_currency', ['code'], ForeignKeyAction::Restrict),
        ];
    }

    private static function defaults(): Table
    {
        return new Table('ref_defaults', [
            new Column('id', 'integer'),
            new Column('status', 'string', default: 'draft', length: 20),
            new Column('quote', 'string', default: "O'Reilly \\ co", length: 40),
            new Column('qty', 'integer', default: 0),
            new Column('negative', 'integer', default: -5),
            new Column('active', 'boolean', default: true),
            new Column('archived', 'boolean', default: false),
            new Column('amount', 'decimal', default: '9.90', precision: 10, scale: 2),
            new Column('ratio', 'float', default: 1.5),
            new Column('since', 'date_immutable', default: '2026-01-01'),
            new Column('note', 'string', nullable: true, length: 100),
        ], ['id']);
    }

    /**
     * @return list<Table>
     */
    private static function clientAndInvoice(ForeignKeyAction $onDelete = ForeignKeyAction::Cascade): array
    {
        return [
            new Table('ref_client', [new Column('id', 'bigint'), new Column('name', 'string', length: 100)], ['id']),
            new Table('ref_invoice', [
                new Column('id', 'bigint'),
                new Column('client_id', 'bigint', nullable: ForeignKeyAction::SetNull === $onDelete),
                new Column('number', 'string', length: 20),
            ], ['id'])->withForeignKeyTo(['client_id'], 'ref_client', ['id'], $onDelete)->withIndexOn(['client_id']),
        ];
    }
}
