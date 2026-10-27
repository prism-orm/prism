<?php

declare(strict_types=1);

namespace Prism\Orm\Audit;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;

/**
 * La table prism_audit_log (RG-08.10, ADR 0015) : ajoutée au schéma cible dès qu'une entité est
 * #[Auditable], gérée par db:migrations:diff comme une table mappée.
 *
 * @internal
 */
final class AuditLog
{
    public const string TABLE = 'prism_audit_log';

    public const string INSERT = 'insert';

    public const string UPDATE = 'update';

    public const string DELETE = 'delete';

    public const string SOFT_DELETE = 'soft-delete';

    public const string RESTORE = 'restore';

    // valeur d'un champ #[Column(sensitive: true)] (RG-08.11)
    public const string MASK = '***';

    public static function table(): Table
    {
        return new Table(self::TABLE, [
            new Column('id', 'bigint', autoincrement: true),
            new Column('entity_class', 'string', length: 255),
            new Column('entity_id', 'string', length: 64),
            new Column('tenant_id', 'string', nullable: true, length: 64),
            new Column('action', 'string', length: 16),
            new Column('changes', 'json'),
            new Column('actor', 'string', nullable: true, length: 255),
            new Column('request_id', 'string', nullable: true, length: 64),
            new Column('system', 'boolean'),
            new Column('occurred_at', 'datetime_immutable'),
        ], ['id'])
            ->withIndexOn(['entity_class', 'entity_id'])
            ->withIndexOn(['occurred_at'])
            ->withIndexOn(['tenant_id']);
    }
}
