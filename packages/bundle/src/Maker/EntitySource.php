<?php

declare(strict_types=1);

namespace Prism\Bundle\Maker;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Token;
use Prism\Bundle\Exception\MakerException;

/**
 * Le source d'une entité, modifié sans être réécrit (ADR 0013) : lu par nikic/php-parser, puis
 * imprimé avec le « format-preserving printer ». Seuls les membres et imports ajoutés changent ;
 * la mise en forme, les commentaires et les méthodes de l'application sont gardés.
 *
 * @internal
 */
final class EntitySource
{
    /** @var array<Node> */
    private readonly array $original;

    /** @var array<Token> */
    private readonly array $tokens;

    /** @var array<Node> */
    private array $statements;

    private readonly string $source;

    private function __construct(string $code, private readonly string $file)
    {
        $this->source = $code;
        $parser = self::parser();
        try {
            $this->original = $parser->parse($code) ?? [];
        } catch (\PhpParser\Error $e) {
            throw new MakerException(\sprintf('%s cannot be parsed: %s', $file, $e->getMessage()), previous: $e);
        }
        $this->tokens = $parser->getTokens();
        $this->statements = new NodeTraverser(new CloningVisitor())->traverse($this->original);
        $this->class();
    }

    public static function load(string $code, string $file): self
    {
        return new self($code, $file);
    }

    /**
     * Une entité neuve : final, #[Prism\Entity], un identifiant auto-incrémenté.
     *
     * @param string|null $repository la classe du repository, s'il y en a un
     */
    public static function create(string $namespace, string $class, ?string $repository, string $file): self
    {
        $uses = null === $repository ? '' : 'use ' . $repository . ";\n";
        $entity = null === $repository ? '#[Prism\Entity]' : \sprintf('#[Prism\Entity(repositoryClass: %s::class)]', self::shortName($repository));

        return new self(<<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            {$uses}use Prism\\Orm\\Mapping as Prism;

            {$entity}
            final class {$class}
            {
                #[Prism\\Id, Prism\\GeneratedValue]
                public ?int \$id = null;
            }

            PHP, $file);
    }

    public function code(): string
    {
        $code = new Standard()->printFormatPreserving($this->statements, $this->original, $this->tokens);

        // les lignes vides ajoutées entre membres sont indentées par l'imprimeur ; seulement si le
        // fichier n'en avait aucune ainsi (une heredoc en garderait le sens)
        return 1 === preg_match('/^[ \t]+$/m', $this->source) ? $code : (string) preg_replace('/^[ \t]+$/m', '', $code);
    }

    public function className(): string
    {
        $namespace = $this->namespace();

        return (null === $namespace?->name ? '' : $namespace->name->toString() . '\\') . $this->class()->name?->toString();
    }

    public function hasProperty(string $name): bool
    {
        return null !== $this->class()->getProperty($name) || \in_array($name, $this->promoted(), true);
    }

    /**
     * Un membre (attributs, docblock et propriété) écrit en PHP, après les propriétés existantes.
     */
    public function addProperty(string $code): void
    {
        $nodes = self::members($code);
        $class = $this->class();
        $index = 0;
        foreach ($class->stmts as $i => $member) {
            if ($member instanceof Stmt\Property || $member instanceof Stmt\ClassConst || $member instanceof Stmt\TraitUse) {
                $index = $i + 1;
            }
        }
        // une ligne vide entre deux membres
        $this->insert($class, $index, 0 === $index ? $nodes : [new Stmt\Nop(), ...$nodes]);
    }

    /**
     * Une instruction ajoutée à la fin du constructeur, créé s'il n'existe pas.
     */
    public function addToConstructor(string $statement): void
    {
        $class = $this->class();
        $constructor = $class->getMethod('__construct');
        if (null === $constructor) {
            $members = self::members("public function __construct()\n{\n}");
            $constructor = $members[0];
            \assert($constructor instanceof Stmt\ClassMethod);
            $index = 0;
            foreach ($class->stmts as $i => $member) {
                if (!$member instanceof Stmt\ClassMethod) {
                    $index = $i + 1;
                }
            }
            $this->insert($class, $index, [new Stmt\Nop(), $constructor]);
        }
        $parsed = self::parser()->parse('<?php ' . $statement);
        $constructor->stmts = [...$constructor->stmts ?? [], ...$parsed ?? []];
    }

    /**
     * use $class (as $alias), si le fichier ne l'importe pas déjà.
     */
    public function addUse(string $class, ?string $alias = null): void
    {
        $namespace = $this->namespace();
        $statements = null === $namespace ? $this->statements : $namespace->stmts;
        $index = 0;
        foreach ($statements as $i => $statement) {
            if ($statement instanceof Stmt\Use_) {
                foreach ($statement->uses as $use) {
                    if (0 === strcasecmp($use->name->toString(), $class)) {
                        return;
                    }
                }
                $index = $i + 1;
            } elseif ($statement instanceof Stmt\Declare_ && 0 === $index) {
                $index = $i + 1;
            }
        }
        $use = new Stmt\Use_([new Node\UseItem(new Node\Name($class), null === $alias ? null : new Node\Identifier($alias))]);
        array_splice($statements, $index, 0, [$use]);
        if (null === $namespace) {
            $this->statements = $statements;
        } else {
            $namespace->stmts = array_values(array_filter($statements, static fn (Node $s): bool => $s instanceof Stmt));
        }
    }

    public static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }

    /**
     * php-parser attache un commentaire de fin de ligne (« $name = ''; // … ») au membre suivant :
     * insérer devant ce membre le déplacerait. Dans ce cas, les membres vont à la fin de la classe.
     *
     * @param list<Stmt> $nodes
     */
    private function insert(Stmt\Class_ $class, int $index, array $nodes): void
    {
        $previous = $class->stmts[$index - 1] ?? null;
        $next = $class->stmts[$index] ?? null;
        $comment = $next?->getComments()[0] ?? null;
        if (null !== $previous && null !== $comment && $comment->getStartLine() === $previous->getEndLine()) {
            $index = \count($class->stmts);
        }
        array_splice($class->stmts, $index, 0, $nodes);
    }

    private static function parser(): Parser
    {
        return new ParserFactory()->createForHostVersion();
    }

    /**
     * @return list<Stmt>
     */
    private static function members(string $code): array
    {
        $class = self::parser()->parse("<?php\nclass __PrismMember\n{\n" . $code . "\n}\n")[0] ?? null;
        \assert($class instanceof Stmt\Class_);

        return array_values($class->stmts);
    }

    private function namespace(): ?Stmt\Namespace_
    {
        $namespace = new NodeFinder()->findFirstInstanceOf($this->statements, Stmt\Namespace_::class);

        return $namespace instanceof Stmt\Namespace_ ? $namespace : null;
    }

    private function class(): Stmt\Class_
    {
        $class = new NodeFinder()->findFirstInstanceOf($this->statements, Stmt\Class_::class);
        if (!$class instanceof Stmt\Class_) {
            throw new MakerException(\sprintf('%s does not declare a class.', $this->file));
        }

        return $class;
    }

    /**
     * @return list<string> les propriétés promues du constructeur
     */
    private function promoted(): array
    {
        $names = [];
        $constructor = $this->class()->getMethod('__construct');
        foreach (null === $constructor ? [] : $constructor->params as $param) {
            if (0 !== $param->flags && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }
}
