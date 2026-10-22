<?php

declare(strict_types=1);

namespace Prism\Bundle\Maker;

use Prism\Bundle\Exception\MakerException;
use Prism\Bundle\Metamodel\MetamodelRefresher;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * make:entity pour Prism (RG-07.7) : crée une entité ou complète une entité existante, champ par
 * champ ; une relation peut écrire ses deux côtés (mappedBy, inversedBy), une collection est
 * initialisée dans le constructeur. Le code existant est modifié sans être réécrit (EntitySource).
 * Le metamodel est marqué périmé : il est regénéré au démarrage suivant (RG-05.1).
 *
 * @internal
 */
abstract class AbstractMakeEntity extends AbstractMaker
{
    /** @var array<string, EntitySource> les fichiers modifiés, par chemin relatif */
    private array $sources = [];

    public function __construct(
        private readonly FileManager $files,
        private readonly MetamodelRefresher $metamodel,
    ) {
    }

    public static function getCommandDescription(): string
    {
        return 'Create or update a Prism entity class, and its repository';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('name', InputArgument::OPTIONAL, 'The class name of the entity to create or update (e.g. <fg=yellow>Order</>)')
            ->addOption('no-repository', null, InputOption::VALUE_NONE, 'Do not create a repository class for a new entity')
            ->setHelp(<<<'HELP'
                Creates an entity in <info>src/Entity</info>, or adds fields and relations to an existing one:

                  <info>php %command.full_name% Order</info>

                A relation can write both of its sides (mappedBy, inversedBy). Then run <info>make:migration</info>.
                HELP);
        $inputConfig->setArgumentAsNonInteractive('name');
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        if (null === $input->getArgument('name')) {
            $input->setArgument('name', $io->ask('Class name of the entity to create or update (e.g. <fg=yellow>Order</>)', validator: self::className(...)));
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $this->sources = [];
        $name = self::className($input->getArgument('name'));
        $details = $generator->createClassNameDetails($name, 'Entity\\');
        $class = $details->getFullName();
        $path = $this->pathOf($class);

        if ($this->files->fileExists($path)) {
            $io->text(\sprintf('Your entity already exists! Let\'s add some new fields to <info>%s</>.', $class));
            $source = $this->source($class);
        } else {
            $repository = null;
            if (true !== $input->getOption('no-repository')) {
                $repository = $generator->createClassNameDetails($name, 'Repository\\', 'Repository')->getFullName();
                $this->files->dumpFile($this->pathOf($repository), self::repositoryCode($repository, $class));
            }
            $source = EntitySource::create(substr($class, 0, (int) strrpos($class, '\\')), EntitySource::shortName($class), $repository, $path);
            $this->sources[$path] = $source;
            $io->text(\sprintf('Entity <info>%s</> created.', $class));
        }

        while (true) {
            $field = $this->askFieldName($io, $source);
            if (null === $field) {
                break;
            }
            $type = $this->askType($io);
            if (\in_array($type, FieldWriter::RELATIONS, true)) {
                $this->addRelation($io, $generator, $source, $class, $field, $type);
            } else {
                $this->addField($io, $source, $field, $type);
            }
        }

        foreach ($this->sources as $file => $edited) {
            $this->files->dumpFile($file, $edited->code());
        }
        // RG-07.7 : le metamodel suit les entités ; regénéré au prochain démarrage du kernel
        $this->metamodel->invalidate();

        $this->writeSuccessMessage($io);
        $io->text([
            'Next: run <info>make:migration</info> to create the migration of these changes.',
            'The metamodel (Order_ classes of the typed query builder) is regenerated on the next request or command; run <info>db:metamodel:generate</info> to refresh the IDE helpers now.',
        ]);
    }

    private static function className(mixed $name): string
    {
        if (!\is_string($name) || 1 !== preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid class name.', \is_scalar($name) ? (string) $name : get_debug_type($name)));
        }

        return $name;
    }

    private function pathOf(string $class): string
    {
        return $this->files->getRelativePathForFutureClass($class) ?? throw new MakerException(\sprintf('No autoload path for %s: check the PSR-4 autoload of composer.json.', $class));
    }

    private function source(string $class): EntitySource
    {
        $path = $this->pathOf($class);

        return $this->sources[$path] ??= EntitySource::load($this->files->getFileContents($path), $path);
    }

    private function askFieldName(ConsoleStyle $io, EntitySource $source): ?string
    {
        $io->newLine();
        $name = $io->ask('New property name (press <return> to stop adding fields)', validator: static function (mixed $name) use ($source): ?string {
            if (null === $name || '' === $name) {
                return null;
            }
            if (!\is_string($name) || 1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException('A property name is letters, digits and underscores, not starting with a digit.');
            }
            if ($source->hasProperty($name)) {
                throw new \InvalidArgumentException(\sprintf('The property "%s" already exists.', $name));
            }

            return $name;
        });

        return \is_string($name) ? $name : null;
    }

    private function askType(ConsoleStyle $io): string
    {
        $types = [...array_keys(FieldWriter::TYPES), ...FieldWriter::RELATIONS];
        $type = $io->askQuestion(new ChoiceQuestion('Field type', array_combine($types, $types), 'string'));
        \assert(\is_string($type));

        return $type;
    }

    private function addField(ConsoleStyle $io, EntitySource $source, string $field, string $type): void
    {
        $length = $precision = $scale = null;
        if ('string' === $type) {
            $length = self::askInt($io, 'Field length', '255');
        }
        if ('decimal' === $type) {
            $precision = self::askInt($io, 'Precision (total number of digits)', '10');
            $scale = self::askInt($io, 'Scale (digits after the decimal point)', '2', zero: true);
        }
        $nullable = $io->confirm('Can this field be null in the database (nullable)', false);
        $source->addProperty(FieldWriter::field($field, $type, $nullable, $length, $precision, $scale));
    }

    private function addRelation(ConsoleStyle $io, Generator $generator, EntitySource $source, string $class, string $field, string $type): void
    {
        $target = $this->askTarget($io, $generator);
        $targetSource = $target === $class ? $source : $this->source($target);
        $short = EntitySource::shortName($class);
        $targetShort = EntitySource::shortName($target);
        $this->importIfElsewhere($source, $class, $target);
        $this->importIfElsewhere($targetSource, $target, $class);

        switch ($type) {
            case 'ManyToOne':
                $nullable = $io->confirm(\sprintf('Is the <comment>%s</>.<comment>%s</> property allowed to be null (nullable)?', $short, $field), true);
                $inverse = $this->askInverse($io, $targetSource, $targetShort, Str::singularCamelCaseToPluralCamelCase(lcfirst($short)), \sprintf('so that you can access the %s objects from it, e.g. $%s->%s', $short, lcfirst($targetShort), Str::singularCamelCaseToPluralCamelCase(lcfirst($short))));
                $source->addProperty(FieldWriter::toOne('ManyToOne', $field, $target, $nullable, inversedBy: $inverse));
                if (null !== $inverse) {
                    FieldWriter::addCollection($targetSource, FieldWriter::collection('OneToMany', $inverse, $class, mappedBy: $field), $inverse);
                }
                break;
            case 'OneToMany':
                $owner = $this->askName($io, $targetSource, \sprintf('A new property is also added to the <comment>%s</> class so that the relation has an owner (the foreign key). New field name inside %s', $targetShort, $targetShort), lcfirst($short));
                $nullable = $io->confirm(\sprintf('Is the <comment>%s</>.<comment>%s</> property allowed to be null (nullable)?', $targetShort, $owner), true);
                FieldWriter::addCollection($source, FieldWriter::collection('OneToMany', $field, $target, mappedBy: $owner), $field);
                $targetSource->addProperty(FieldWriter::toOne('ManyToOne', $owner, $class, $nullable, inversedBy: $field));
                break;
            case 'ManyToMany':
                $inverse = $this->askInverse($io, $targetSource, $targetShort, Str::singularCamelCaseToPluralCamelCase(lcfirst($short)), \sprintf('so that you can access the %s objects from it', $short));
                FieldWriter::addCollection($source, FieldWriter::collection('ManyToMany', $field, $target, inversedBy: $inverse), $field);
                if (null !== $inverse) {
                    FieldWriter::addCollection($targetSource, FieldWriter::collection('ManyToMany', $inverse, $class, mappedBy: $field), $inverse);
                }
                break;
            default:
                $nullable = $io->confirm(\sprintf('Is the <comment>%s</>.<comment>%s</> property allowed to be null (nullable)?', $short, $field), true);
                $inverse = $this->askInverse($io, $targetSource, $targetShort, lcfirst($short), \sprintf('so that you can access the %s object from it', $short));
                $source->addProperty(FieldWriter::toOne('OneToOne', $field, $target, $nullable, inversedBy: $inverse));
                if (null !== $inverse) {
                    $targetSource->addProperty(FieldWriter::toOne('OneToOne', $inverse, $class, true, mappedBy: $field));
                }
        }
    }

    private function askTarget(ConsoleStyle $io, Generator $generator): string
    {
        $target = $io->ask('What class should this entity be related to?', validator: function (mixed $name) use ($generator): string {
            $class = $generator->createClassNameDetails(self::className($name), 'Entity\\')->getFullName();
            if (!$this->files->fileExists($this->pathOf($class))) {
                throw new \InvalidArgumentException(\sprintf('The entity %s does not exist: create it first with this command.', $class));
            }

            return $class;
        });
        \assert(\is_string($target));

        return $target;
    }

    // RG-07.7 : le côté inverse, proposé ; null si refusé
    private function askInverse(ConsoleStyle $io, EntitySource $target, string $targetShort, string $default, string $purpose): ?string
    {
        if (!$io->confirm(\sprintf('Do you want to add a new property to <comment>%s</> %s?', $targetShort, $purpose), true)) {
            return null;
        }

        return $this->askName($io, $target, \sprintf('New field name inside %s', $targetShort), $default);
    }

    private function askName(ConsoleStyle $io, EntitySource $target, string $question, string $default): string
    {
        $name = $io->ask($question, $target->hasProperty($default) ? null : $default, static function (mixed $name) use ($target): string {
            if (!\is_string($name) || 1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException('A property name is letters, digits and underscores, not starting with a digit.');
            }
            if ($target->hasProperty($name)) {
                throw new \InvalidArgumentException(\sprintf('The property "%s" already exists.', $name));
            }

            return $name;
        });
        \assert(\is_string($name));

        return $name;
    }

    private function importIfElsewhere(EntitySource $source, string $class, string $other): void
    {
        if (substr($class, 0, (int) strrpos($class, '\\')) !== substr($other, 0, (int) strrpos($other, '\\'))) {
            $source->addUse($other);
        }
    }

    private static function askInt(ConsoleStyle $io, string $question, string $default, bool $zero = false): int
    {
        $value = $io->ask($question, $default, static fn (mixed $v): int => self::positive($v, $zero));
        \assert(\is_int($value));

        return $value;
    }

    private static function positive(mixed $value, bool $zero = false): int
    {
        if (!is_numeric($value) || (int) $value < ($zero ? 0 : 1)) {
            throw new \InvalidArgumentException(\sprintf('A %s integer is expected.', $zero ? 'positive or zero' : 'positive'));
        }

        return (int) $value;
    }

    private static function repositoryCode(string $repository, string $entity): string
    {
        $namespace = substr($repository, 0, (int) strrpos($repository, '\\'));
        $short = EntitySource::shortName($repository);
        $entityShort = EntitySource::shortName($entity);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$entity};
            use Prism\\Orm\\Repository\\EntityRepository;

            /**
             * @extends EntityRepository<{$entityShort}>
             */
            final class {$short} extends EntityRepository
            {
            }

            PHP;
    }
}
