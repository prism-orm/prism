<?php

declare(strict_types=1);

namespace Prism\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use Prism\Orm\Query\EntityQueryBuilder;
use Prism\PHPStan\SelectionType;

/**
 * RG-05.7 : chaque sélection de selectInto(Dto::class, ...) est nommée comme un paramètre du
 * constructeur du DTO, qui accepte son type ; chaque paramètre obligatoire est sélectionné.
 * Les mêmes vérifications qu'à l'exécution, dès l'analyse.
 *
 * @implements Rule<MethodCall>
 *
 * @internal
 */
final class SelectIntoRule implements Rule
{
    public function __construct(
        private readonly ReflectionProvider $reflection,
        private readonly SelectionType $types,
    ) {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || 'selectinto' !== $node->name->toLowerString()
            || !new ObjectType(EntityQueryBuilder::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }
        [$dto, $selections] = self::split(array_values($node->getArgs()));
        $classes = null === $dto ? [] : $scope->getType($dto->value)->getConstantStrings();
        if (1 !== \count($classes) || !$this->reflection->hasClass($class = $classes[0]->getValue()) || null === $selections) {
            return [];
        }
        $reflection = $this->reflection->getClass($class);
        $class = $reflection->getName();
        /** @var array<string, ExtendedParameterReflection> $parameters */
        $parameters = [];
        if ($reflection->hasConstructor()) {
            foreach ($reflection->getConstructor()->getOnlyVariant()->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }
        }
        $variadic = [] !== array_filter($parameters, static fn (ExtendedParameterReflection $p): bool => $p->isVariadic());

        $errors = [];
        $selected = [];
        foreach ($selections as $arg) {
            if (null === $arg->name) {
                $errors[] = RuleErrorBuilder::message(\sprintf('Each selection of selectInto(%s::class, ...) is named after a parameter of its constructor, such as total: $o->total->sum().', $class))
                    ->identifier('prism.selectInto.unnamed')->line($arg->getStartLine())->build();
                continue;
            }
            $name = $arg->name->toString();
            $selected[$name] = true;
            $parameter = $parameters[$name] ?? null;
            if (null === $parameter || $parameter->isVariadic()) {
                if (!$variadic) {
                    $errors[] = RuleErrorBuilder::message(\sprintf('The selection "%s" matches no parameter of %s::__construct() (%s).', $name, $class, [] === $parameters ? 'none' : implode(', ', array_keys($parameters))))
                        ->identifier('prism.selectInto.unknownParameter')->line($arg->getStartLine())->build();
                }
                continue;
            }
            $type = $this->types->of($arg->value, $scope);
            if (null !== $type && !$parameter->getType()->accepts($type, true)->yes()) {
                $errors[] = RuleErrorBuilder::message(\sprintf('The selection "%s" is %s, but $%s of %s::__construct() accepts %s.', $name, $type->describe(VerbosityLevel::typeOnly()), $name, $class, $parameter->getType()->describe(VerbosityLevel::typeOnly())))
                    ->identifier('prism.selectInto.typeMismatch')->line($arg->getStartLine())->build();
            }
        }
        foreach ($parameters as $name => $parameter) {
            if (!$parameter->isOptional() && !$parameter->isVariadic() && !isset($selected[$name])) {
                $errors[] = RuleErrorBuilder::message(\sprintf('The parameter $%s of %s::__construct() is required but not selected: select it as %s: ...', $name, $class, $name))
                    ->identifier('prism.selectInto.missingParameter')->build();
            }
        }

        return $errors;
    }

    /**
     * La classe du DTO, puis les sélections ; null si un argument est déplié (...$selections).
     *
     * @param list<Arg> $args
     *
     * @return array{?Arg, list<Arg>|null}
     */
    private static function split(array $args): array
    {
        $dto = null;
        $selections = [];
        foreach ($args as $i => $arg) {
            if ($arg->unpack) {
                return [$dto, null];
            }
            if ('dto' === $arg->name?->toString() || (null === $arg->name && 0 === $i)) {
                $dto = $arg;
            } else {
                $selections[] = $arg;
            }
        }

        return [$dto, $selections];
    }
}
