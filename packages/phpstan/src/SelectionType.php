<?php

declare(strict_types=1);

namespace Prism\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Query\Expression;
use Prism\Orm\Query\Metamodel;

/**
 * Le type PHP de la valeur d'une sélection de selectInto() (ADR 0011) : celui de son champ par
 * la metadata, ou celui de son expression, nullable pour un agrégat ou un chemin JSON. Null
 * pour ce qui n'est ni un champ ni une expression.
 *
 * Une sélection dont la forme n'est pas reconnue (une variable) garde son type de base, sans
 * null : sa nullabilité est inconnue, l'extension ne la suppose pas.
 *
 * @internal
 */
final class SelectionType
{
    /** @var list<string> méthodes dont le résultat peut être null : groupe vide, chemin absent */
    private const array NULLABLE = ['sum', 'avg', 'min', 'max', 'asstring', 'asint', 'asdecimal'];

    public function __construct(private readonly MetadataProvider $metadata)
    {
    }

    public function of(Expr $expr, Scope $scope): ?Type
    {
        $type = $scope->getType($expr);
        if (new ObjectType(Metamodel\Field::class)->isSuperTypeOf($type)->yes()) {
            return $this->field($expr, $scope) ?? self::base($type);
        }
        if (!new ObjectType(Expression\TypedExpression::class)->isSuperTypeOf($type)->yes()) {
            return null;
        }
        $base = self::base($type);
        if (null === $base || !$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return $base;
        }

        return \in_array($expr->name->toLowerString(), self::NULLABLE, true) ? TypeCombinator::addNull($base) : $base;
    }

    /**
     * Un champ lu sur un metamodel ($o->total, $o->billing->city) : son type par la metadata.
     */
    private function field(Expr $expr, Scope $scope): ?Type
    {
        $path = [];
        while ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            array_unshift($path, $expr->name->toString());
            $expr = $expr->var;
            if (new ObjectType(Metamodel\Metamodel::class)->isSuperTypeOf($scope->getType($expr))->yes()) {
                break;
            }
        }
        $entities = $scope->getType($expr)->getTemplateType(Metamodel\Metamodel::class, 'T')->getObjectClassNames();
        $metadata = 1 === \count($entities) ? $this->metadata->metadataFor($entities[0]) : null;
        $path = implode('.', $path);
        if (null === $metadata || !$metadata->hasField($path)) {
            return null;
        }
        $mapping = $metadata->getField($path);
        // les champs d'un embeddable nullable sont nuls avec lui (RG-02.5)
        $embedded = array_filter($metadata->getEmbedded(), static fn ($e): bool => str_starts_with($path, $e->fieldName . '.') && $e->nullable);
        $type = self::php($mapping);

        return $mapping->nullable || [] !== $embedded ? TypeCombinator::addNull($type) : $type;
    }

    private static function php(FieldMapping $mapping): Type
    {
        if (null !== $mapping->enumType) {
            return new ObjectType($mapping->enumType);
        }

        return match ($mapping->type) {
            'integer', 'smallint', 'bigint' => new IntegerType(),
            'string', 'text', 'uuid', 'binary' => new StringType(),
            'decimal' => new IntersectionType([new StringType(), new AccessoryNumericStringType()]),
            'float' => new FloatType(),
            'boolean' => new BooleanType(),
            'datetime_immutable', 'date_immutable', 'time_immutable' => new ObjectType(\DateTimeImmutable::class),
            'json' => new ArrayType(new MixedType(), new MixedType()),
            default => new MixedType(),
        };
    }

    // le type de valeur d'une classe de champ ou d'expression
    private static function base(Type $type): ?Type
    {
        $is = static fn (string $class): bool => new ObjectType($class)->isSuperTypeOf($type)->yes();

        return match (true) {
            $is(Metamodel\IntField::class), $is(Expression\IntExpression::class) => new IntegerType(),
            $is(Metamodel\DecimalField::class), $is(Expression\DecimalExpression::class) => new IntersectionType([new StringType(), new AccessoryNumericStringType()]),
            $is(Metamodel\FloatField::class), $is(Expression\FloatExpression::class) => new FloatType(),
            $is(Metamodel\DateTimeField::class), $is(Expression\DateTimeExpression::class) => new ObjectType(\DateTimeImmutable::class),
            $is(Metamodel\StringField::class), $is(Metamodel\BinaryField::class), $is(Expression\StringExpression::class) => new StringType(),
            $is(Metamodel\BoolField::class) => new BooleanType(),
            $is(Metamodel\EnumField::class) => $type->getTemplateType(Metamodel\EnumField::class, 'E'),
            $is(Metamodel\JsonField::class) => new ArrayType(new MixedType(), new MixedType()),
            default => null,
        };
    }
}
