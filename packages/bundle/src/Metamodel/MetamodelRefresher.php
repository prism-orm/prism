<?php

declare(strict_types=1);

namespace Prism\Bundle\Metamodel;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\MetamodelAutoloader;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;

/**
 * Le metamodel de chaque EntityManager (ADR 0013, RG-05.1) : chargé depuis son dossier,
 * généré au cache:warmup, regénéré en dev quand une entité est plus récente que la dernière
 * génération (fichier témoin). Seuls les fichiers qui changent sont réécrits.
 *
 * @internal
 */
final class MetamodelRefresher
{
    public const string STAMP = '.prism-generated';

    /**
     * @param array<string, array{MetadataFactory, string, list<string>}> $entityManagers
     *                                                                                    metadata, dossier du metamodel, dossiers des entités
     */
    public function __construct(private readonly array $entityManagers)
    {
    }

    public function registerAutoloaders(): void
    {
        foreach ($this->entityManagers as [, $dir]) {
            MetamodelAutoloader::register($dir);
        }
    }

    /**
     * @throws MappingException mapping invalide
     */
    public function generate(): void
    {
        foreach ($this->entityManagers as [$metadata, $dir]) {
            $this->generateOne($metadata, $dir);
        }
    }

    /**
     * En dev : regénère le metamodel dont une entité est plus récente que la dernière génération.
     * Un mapping invalide est laissé à db:schema:validate, qui doit pouvoir démarrer.
     */
    public function refreshIfStale(): void
    {
        foreach ($this->entityManagers as [$metadata, $dir, $entityDirs]) {
            $stamp = @filemtime($dir . '/' . self::STAMP);
            if (false !== $stamp && self::newest($entityDirs) <= $stamp) {
                continue;
            }
            try {
                $this->generateOne($metadata, $dir);
            } catch (MappingException) {
                continue;
            }
        }
    }

    private function generateOne(MetadataFactory $metadata, string $dir): void
    {
        new MetamodelGenerator()->generate($metadata->getAllMetadata(), $dir);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        touch($dir . '/' . self::STAMP);
    }

    /**
     * @param list<string> $dirs
     */
    private static function newest(array $dirs): int
    {
        $newest = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    $newest = max($newest, $file->getMTime());
                }
            }
        }

        return $newest;
    }
}
