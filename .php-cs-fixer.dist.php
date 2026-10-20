<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in([__DIR__ . '/packages', __DIR__ . '/benchmarks'])
    // metamodel généré (ADR 0009) : gardé tel que le générateur l'écrit
    ->notName('*_.php')
    ->append([__FILE__]);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        // final_class, sauf une classe marquée @extensible (ADR 0006)
        'final_internal_class' => [
            'include' => [],
            'exclude' => ['final', 'Entity', 'ORM\\Entity', 'ORM\\Mapping\\Entity', 'Mapping\\Entity', 'Document', 'ODM\\Document', 'extensible'],
            'consider_absent_docblock_as_internal_class' => true,
        ],
        'concat_space' => ['spacing' => 'one'],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'phpdoc_to_comment' => false,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
