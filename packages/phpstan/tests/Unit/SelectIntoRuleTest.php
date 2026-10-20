<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Unit;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Prism\PHPStan\Rule\SelectIntoRule;
use Prism\PHPStan\Tests\Fixtures\Dto\Summary;
use Prism\PHPStan\Tests\Fixtures\Dto\Typed;
use Prism\PHPStan\Tests\Fixtures\Status;

/**
 * RG-05.7 : sélections de selectInto() et constructeur du DTO.
 *
 * @extends RuleTestCase<SelectIntoRule>
 */
final class SelectIntoRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(SelectIntoRule::class);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [\dirname(__DIR__, 2) . '/extension.neon'];
    }

    public function testSelections(): void
    {
        $summary = Summary::class;
        $typed = Typed::class;
        $mismatch = static fn (string $name, string $given, string $dto, string $accepted): string => \sprintf('The selection "%s" is %s, but $%s of %s::__construct() accepts %s.', $name, $given, $name, $dto, $accepted);
        $missing = static fn (string $name): string => \sprintf('The parameter $%s of %s::__construct() is required but not selected: select it as %s: ...', $name, $summary, $name);

        $this->analyse([\dirname(__DIR__) . '/data/select-into.php'], [
            // noms
            ["Each selection of selectInto({$summary}::class, ...) is named after a parameter of its constructor, such as total: \$o->total->sum().", 30],
            [$missing('reference'), 30],
            ["The selection \"avg\" matches no parameter of {$summary}::__construct() (reference, total, count, average).", 31],
            [$missing('total'), 32],
            // types : champ, agrégat nullable, enum, embeddable nullable, chemin JSON
            [$mismatch('reference', 'int', $summary, 'string'), 40],
            [$mismatch('total', 'string|null', $summary, 'string'), 41],
            [$mismatch('count', 'int|null', $summary, 'int'), 42],
            [$mismatch('total', 'float|null', $summary, 'string'), 43],
            [$mismatch('city', 'string|null', $typed, 'string'), 44],
            [$mismatch('issuedAt', 'DateTimeImmutable|null', $typed, 'DateTimeImmutable'), 44],
            [$mismatch('status', 'string', $typed, Status::class), 44],
            [$mismatch('count', 'int|null', $summary, 'int'), 45],
            [$mismatch('reference', 'string|null', $summary, 'string'), 45],
            [$mismatch('total', 'string|null', $summary, 'string'), 45],
        ]);
    }
}
