<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Exception\MetamodelException;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;

/**
 * Génère le metamodel (ADR 0009) : une classe Order_ par entité, une classe Address_ par
 * embeddable, à partir d'un gabarit déterministe. Deux générations sur le même mapping donnent
 * des fichiers identiques ; un fichier identique n'est pas réécrit, et seuls les fichiers
 * générés par Prism (en-tête) sont supprimés quand leur entité disparaît.
 *
 * @api
 */
final class MetamodelGenerator
{
    public const string HEADER = '// Généré par Prism (db:metamodel:generate) : ne pas modifier.';

    private const array FIELD_CLASSES = [
        'string' => 'StringField', 'text' => 'StringField', 'uuid' => 'StringField',
        'integer' => 'IntField', 'smallint' => 'IntField', 'bigint' => 'IntField',
        'decimal' => 'DecimalField',
        'float' => 'FloatField',
        'boolean' => 'BoolField',
        'datetime_immutable' => 'DateTimeField', 'date_immutable' => 'DateTimeField', 'time_immutable' => 'DateTimeField',
        'json' => 'JsonField',
        'binary' => 'BinaryField',
    ];

    /**
     * @param list<ClassMetadata<object>> $metadata
     * @param string                      $namespacePrefix préfixe PSR-4 du dossier (« App\\ » pour src/) ;
     *                                                     vide : un sous-dossier par segment de namespace
     *
     * @throws MetamodelException dossier non accessible en écriture
     */
    public function generate(array $metadata, string $dir, string $namespacePrefix = ''): GenerationResult
    {
        $dir = rtrim($dir, '/\\');
        $files = $this->render($metadata, $namespacePrefix);
        $written = [];
        $unchanged = [];

        foreach ($files as $relative => $content) {
            $path = $dir . '/' . $relative;
            if (is_file($path) && file_get_contents($path) === $content) {
                $unchanged[] = $relative;
                continue;
            }
            if ((!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0o777, true)) || false === @file_put_contents($path, $content, \LOCK_EX)) {
                throw MetamodelException::cannotWrite($path);
            }
            $written[] = $relative;
        }

        $deleted = [];
        foreach ($this->generatedFiles($dir) as $relative) {
            if (!isset($files[$relative]) && @unlink($dir . '/' . $relative)) {
                $deleted[] = $relative;
            }
        }

        return new GenerationResult($written, $unchanged, $deleted);
    }

    /**
     * Vrai si le dossier contient exactement le metamodel de ce mapping (RG-05.1).
     *
     * @param list<ClassMetadata<object>> $metadata
     */
    public function isFresh(array $metadata, string $dir, string $namespacePrefix = ''): bool
    {
        $dir = rtrim($dir, '/\\');
        $files = $this->render($metadata, $namespacePrefix);
        foreach ($files as $relative => $content) {
            $path = $dir . '/' . $relative;
            if (!is_file($path) || file_get_contents($path) !== $content) {
                return false;
            }
        }

        return [] === array_diff($this->generatedFiles($dir), array_keys($files));
    }

    /**
     * Contenu de chaque fichier, par chemin relatif, dans l'ordre des chemins.
     *
     * @param list<ClassMetadata<object>> $metadata
     *
     * @return array<string, string>
     */
    public function render(array $metadata, string $namespacePrefix = ''): array
    {
        $files = [];
        foreach ($metadata as $class) {
            $files[self::pathOf($class->className . '_', $namespacePrefix)] = $this->entity($class);
            foreach ($class->getEmbedded() as $embedded) {
                $files[self::pathOf($embedded->class . '_', $namespacePrefix)] = $this->embeddable($embedded->class, $embedded->getFields());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function entity(ClassMetadata $class): string
    {
        [$namespace, $short] = self::split($class->className);
        $properties = [];
        $assignments = [];

        foreach ($class->getFields() as $field) {
            [$type, $generic, $extra] = self::field($field);
            $properties[] = self::property($type, $field->fieldName, $generic);
            $assignments[] = \sprintf('$this->%s = new %s($this, \'%s\'%s);', $field->fieldName, $type, $field->fieldName, $extra);
        }
        foreach ($class->getEmbedded() as $embedded) {
            $properties[] = self::property('\\' . $embedded->class . '_', $embedded->fieldName, null);
            $assignments[] = \sprintf('$this->%s = new \\%s_($this, \'%s\');', $embedded->fieldName, $embedded->class, $embedded->fieldName);
        }
        foreach ($class->getAssociations() as $association) {
            $type = self::relation($association);
            $properties[] = self::property($type, $association->fieldName, \sprintf('%s<\\%s_>', $type, $association->targetEntity));
            $assignments[] = \sprintf('$this->%s = new %s($this, \'%s\', \\%s_::class);', $association->fieldName, $type, $association->fieldName, $association->targetEntity);
        }

        return self::file($namespace, \sprintf(<<<'PHP'
            /**
             * @extends M\Metamodel<\%1$s>
             */
            final class %2$s_ extends M\Metamodel
            {
            %3$s
                public function __construct(string $alias = '%4$s')
                {
                    parent::__construct($alias);
            %5$s    }

                public static function as(string $alias): self
                {
                    return new self($alias);
                }

                protected static function entityClass(): string
                {
                    return \%1$s::class;
                }
            }

            PHP, $class->className, $short, self::block($properties, 1), lcfirst($short), self::block($assignments, 2, separated: false)));
    }

    /**
     * @param class-string       $class
     * @param list<FieldMapping> $fields
     */
    private function embeddable(string $class, array $fields): string
    {
        [$namespace, $short] = self::split($class);
        $properties = [];
        $assignments = [];
        foreach ($fields as $field) {
            [$type, $generic, $extra] = self::field($field);
            $properties[] = self::property($type, $field->fieldName, $generic);
            $assignments[] = \sprintf('$this->%s = new %s($owner, $path . \'.%s\'%s);', $field->fieldName, $type, $field->fieldName, $extra);
        }

        return self::file($namespace, \sprintf(<<<'PHP'
            final class %1$s_ extends M\EmbeddableMetamodel
            {
            %2$s
                /**
                 * @param M\Metamodel<object> $owner
                 */
                public function __construct(M\Metamodel $owner, string $path)
                {
                    parent::__construct($owner, $path);
            %3$s    }
            }

            PHP, $short, self::block($properties, 1), self::block($assignments, 2, separated: false)));
    }

    /**
     * Classe de champ, type générique éventuel, argument supplémentaire du constructeur.
     *
     * @return array{string, string|null, string}
     */
    private static function field(FieldMapping $field): array
    {
        if (null !== $field->enumType) {
            return ['M\EnumField', \sprintf('M\EnumField<\%s>', $field->enumType), \sprintf(', \%s::class', $field->enumType)];
        }

        return ['M\\' . (self::FIELD_CLASSES[$field->type] ?? 'MixedField'), null, ''];
    }

    private static function relation(AssociationMapping $association): string
    {
        return $association->isCollection() ? 'M\CollectionRelation' : 'M\Relation';
    }

    private static function property(string $type, string $name, ?string $generic): string
    {
        return (null === $generic ? '' : \sprintf("/** @var %s */\n", $generic)) . \sprintf('public readonly %s $%s;', $type, $name);
    }

    /**
     * @param list<string> $lines
     */
    private static function block(array $lines, int $depth, bool $separated = true): string
    {
        $indent = str_repeat('    ', $depth);
        $indented = array_map(static fn (string $line): string => $indent . str_replace("\n", "\n" . $indent, $line) . "\n", $lines);

        // propriétés séparées par une ligne vide, instructions à la suite
        return implode($separated ? "\n" : '', $indented);
    }

    private static function file(?string $namespace, string $body): string
    {
        $head = "<?php\n\ndeclare(strict_types=1);\n\n" . self::HEADER . "\n\n";
        if (null !== $namespace) {
            $head .= 'namespace ' . $namespace . ";\n\n";
        }

        return $head . "use Prism\\Orm\\Query\\Metamodel as M;\n\n" . $body;
    }

    /**
     * @return array{string|null, string} namespace, nom court
     */
    private static function split(string $class): array
    {
        $position = strrpos($class, '\\');

        return false === $position ? [null, $class] : [substr($class, 0, $position), substr($class, $position + 1)];
    }

    private static function pathOf(string $class, string $namespacePrefix): string
    {
        if ('' !== $namespacePrefix && str_starts_with($class, $namespacePrefix)) {
            $class = substr($class, \strlen($namespacePrefix));
        }

        return str_replace('\\', '/', $class) . '.php';
    }

    /**
     * Fichiers générés par Prism dans le dossier, chemins relatifs.
     *
     * @return list<string>
     */
    private function generatedFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '_.php') && str_contains((string) file_get_contents($file->getPathname(), length: 200), self::HEADER)) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($dir) + 1));
            }
        }
        sort($files);

        return $files;
    }
}
