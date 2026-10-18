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
            'self reference' => new Schema([new Table('ref_category', [new Column('id', 'integer'), new Column('parent_id', 'integer', nullable: true), new Column('name', 'string', length: 80)], ['id'])
                ->withForeignKeyTo(['parent_id'], 'ref_category', ['id'], ForeignKeyAction::Cascade)]),
        ];

        if ($withSequences) {
            $schemas['sequence'] = new Schema([new Table('ref_seq_owner', [new Column('id', 'bigint')], ['id'])], [new Sequence('ref_invoice_number_seq', 1000, 10)]);
        }

        return $schemas;
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
