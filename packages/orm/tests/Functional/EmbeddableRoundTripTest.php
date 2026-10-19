<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Person;

/**
 * Critère de sortie de la phase 2 : embeddable nullable de bout en bout (schéma, écriture,
 * lecture) sur les 4 bases (RG-02.5).
 */
final class EmbeddableRoundTripTest extends MappingSchemaTestCase
{
    protected static function entities(): array
    {
        return [Person::class];
    }

    public function testNullableEmbeddableIsWrittenAndReadBack(): void
    {
        $withoutWork = new Person(new Address('1 rue de la Paix', '75002', 'Paris'));
        $withoutWork->uuid = 'a0000000-0000-7000-8000-000000000001';
        $withWork = new Person(new Address('2 quai Voltaire', '75007', 'Paris'));
        $withWork->uuid = 'a0000000-0000-7000-8000-000000000002';
        $withWork->work = new Address('3 avenue Foch', '69006', 'Lyon');

        $this->insert($withoutWork);
        $this->insert($withWork);

        $read = $this->read();
        self::assertCount(2, $read);
        self::assertNull($read[0]->work, 'all columns NULL: the embeddable is null');
        self::assertEquals($withoutWork, $read[0]);
        self::assertInstanceOf(Address::class, $read[1]->work);
        self::assertEquals($withWork, $read[1]);
    }

    private function insert(Person $person): void
    {
        $metadata = $this->metadata()[0];
        $types = $metadata->getColumnTypes();

        $qb = $this->connection->createQueryBuilder()->insert($metadata->tableName);
        foreach ($metadata->extractRow($person) as $column => $value) {
            $qb = $qb->set($column, $value, $types[$column]);
        }
        $qb->executeStatement();
    }

    /**
     * @return list<Person>
     */
    private function read(): array
    {
        $metadata = $this->metadata()[0];
        $types = $metadata->getColumnTypes();
        $people = [];

        $rows = $this->connection->createQueryBuilder()->select('*')->from($metadata->tableName)->orderBy($this->connection->getPlatform()->quoteIdentifier('uuid'))->executeQuery()->fetchAllAssociative();
        foreach ($rows as $row) {
            $converted = [];
            foreach ($row as $column => $value) {
                $converted[$column] = $this->connection->convertToPhp($value, $types[$column]);
            }
            $person = new \ReflectionClass(Person::class)->newInstanceWithoutConstructor();
            $metadata->hydrateRow($person, $converted);
            $people[] = $person;
        }

        return $people;
    }
}
