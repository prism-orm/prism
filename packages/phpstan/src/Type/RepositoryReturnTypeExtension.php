<?php

declare(strict_types=1);

namespace Prism\PHPStan\Type;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Prism\Orm\EntityManagerInterface;
use Prism\PHPStan\MetadataProvider;

/**
 * getRepository(Invoice::class) rend la classe de #[Entity(repositoryClass: …)] : ses méthodes
 * sont connues sans @var. Sans repository déclaré, le type générique EntityRepository<T> reste.
 *
 * @internal
 */
final class RepositoryReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private readonly MetadataProvider $metadata)
    {
    }

    public function getClass(): string
    {
        return EntityManagerInterface::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return 'getRepository' === $methodReflection->getName();
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $arg = $methodCall->getArgs()[0] ?? null;
        $classes = null === $arg ? [] : $scope->getType($arg->value)->getConstantStrings();
        $repository = 1 === \count($classes) ? $this->metadata->metadataFor($classes[0]->getValue())?->repositoryClass : null;

        return null === $repository ? null : new ObjectType($repository);
    }
}
