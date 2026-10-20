<?php

declare(strict_types=1);

namespace Prism\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Prism\Dbal\Query\Order;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Repository\EntityRepository;
use Prism\PHPStan\MetadataProvider;

/**
 * RG-05.8 : les clés des critères et du tri de findBy(), findOneBy(), count() et iterate() sont
 * des champs de l'entité du repository, vérifiés dès l'analyse.
 *
 * @implements Rule<MethodCall>
 *
 * @internal
 */
final class FindByRule implements Rule
{
    /** @var array<string, bool> méthode → a un tri */
    private const array METHODS = ['findby' => true, 'findoneby' => true, 'count' => false, 'iterate' => false];

    public function __construct(private readonly MetadataProvider $metadata)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || !isset(self::METHODS[$node->name->toLowerString()])) {
            return [];
        }
        $repository = $scope->getType($node->var);
        if (!new ObjectType(EntityRepository::class)->isSuperTypeOf($repository)->yes()) {
            return [];
        }
        $entities = $repository->getTemplateType(EntityRepository::class, 'T')->getObjectClassNames();
        $metadata = 1 === \count($entities) ? $this->metadata->metadataFor($entities[0]) : null;
        if (null === $metadata) {
            return [];
        }
        $method = $node->name->toString();
        $args = array_values($node->getArgs());

        $errors = [];
        foreach ($this->keys(self::argument($args, 0, 'criteria'), $scope) as [$key]) {
            if (null !== ($error = $this->check($metadata, $method, 'criteria', $key))) {
                $errors[$error->getMessage()] = $error;
            }
        }
        if (self::METHODS[$node->name->toLowerString()]) {
            foreach ($this->keys(self::argument($args, 1, 'orderBy'), $scope) as [$key, $value]) {
                $error = $this->check($metadata, $method, 'order', $key)
                    ?? (new ObjectType(Order::class)->isSuperTypeOf($value)->yes() ? null : RuleErrorBuilder::message(\sprintf('The order of %s::$%s in %s() is %s, not a %s case.', $metadata->className, $key, $method, $value->describe(\PHPStan\Type\VerbosityLevel::typeOnly()), Order::class))->identifier('prism.findBy.invalidOrder')->build());
                if (null !== $error) {
                    $errors[$error->getMessage()] = $error;
                }
            }
        }

        return array_values($errors);
    }

    /**
     * Les clés connues d'un tableau littéral, avec le type de leur valeur.
     *
     * @return list<array{string, \PHPStan\Type\Type}>
     */
    private function keys(?Arg $arg, Scope $scope): array
    {
        if (null === $arg) {
            return [];
        }
        $keys = [];
        foreach ($scope->getType($arg->value)->getConstantArrays() as $array) {
            $values = $array->getValueTypes();
            foreach ($array->getKeyTypes() as $i => $key) {
                if (\is_string($key->getValue())) {
                    $keys[] = [$key->getValue(), $values[$i]];
                }
            }
        }

        return $keys;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param 'criteria'|'order'    $clause
     */
    private function check(ClassMetadata $metadata, string $method, string $clause, string $path): ?IdentifierRuleError
    {
        if ($metadata->hasField($path)) {
            return null;
        }
        if ($metadata->hasAssociation($path)) {
            $association = $metadata->getAssociation($path);
            if ($association->isToOne() && $association->isOwningSide()) {
                return null;
            }

            return RuleErrorBuilder::message(\sprintf(
                '%s::$%s is %s: %s() compares only fields and owning ToOne relations; join it with the typed query builder instead.',
                $metadata->className,
                $path,
                $association->isCollection() ? 'a collection' : 'the inverse side of a relation',
                $method,
            ))->identifier('prism.findBy.relation')->build();
        }
        $closest = MetadataProvider::closest($path, MetadataProvider::comparablePaths($metadata));

        return RuleErrorBuilder::message(\sprintf(
            'Unknown field %s::$%s in the %s of %s().%s',
            $metadata->className,
            $path,
            $clause,
            $method,
            null === $closest ? '' : \sprintf(' Did you mean "%s"?', $closest),
        ))->identifier('prism.findBy.unknownField')->build();
    }

    /**
     * Un argument par sa position ou son nom ; aucun si un argument est déplié (...$args).
     *
     * @param list<Arg> $args
     */
    private static function argument(array $args, int $position, string $name): ?Arg
    {
        foreach ($args as $i => $arg) {
            if ($arg->unpack) {
                return null;
            }
            if (null !== $arg->name ? $arg->name->toString() === $name : $i === $position) {
                return $arg;
            }
        }

        return null;
    }
}
