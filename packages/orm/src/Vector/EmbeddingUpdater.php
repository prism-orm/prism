<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Dbal\Connection;
use Prism\Orm\Exception\EmbeddingException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\VectorDimensionMismatchException;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\EmbeddingMapping;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\BasicEntityPersister;
use Prism\Orm\Persister\DbalPersisterProvider;

/**
 * Recalcule les vecteurs #[EmbeddedVector] périmés, pour le scheduler synchrone, la commande
 * db:embeddings:compute et le handler de ComputeEmbedding (ADR 0018) :
 *
 * 1. lit les sources des lignes (filtres de l'EntityManager appliqués, dont le tenant) ;
 * 2. appelle le fournisseur par lots, hors transaction ;
 * 3. dans une transaction courte, relit les sources sous verrou d'écriture et n'écrit le vecteur
 *    (à jour, avec son modèle) que si elles n'ont pas changé entre-temps : une modification
 *    concurrente a déjà rendu la ligne périmée et planifié son propre recalcul.
 *
 * Une erreur du fournisseur laisse les lignes périmées et remonte. Les entités déjà chargées
 * par un EntityManager gardent leur ancien vecteur jusqu'à refresh().
 *
 * Passer à l'EntityManager et à l'updater la même FilterCollection : celle-ci reçoit alors le
 * filtre du tenant de l'EntityManager.
 *
 * @api
 */
final class EmbeddingUpdater
{
    private readonly DbalPersisterProvider $persisters;

    /** @var int<1, max> */
    private readonly int $batchSize;

    /**
     * @throws InvalidArgumentException taille de lot inférieure à 1
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataFactory $metadata,
        private readonly EmbeddingProviderRegistry $providers,
        FilterCollection $filters = new FilterCollection(),
        int $batchSize = 64,
    ) {
        if ($batchSize < 1) {
            throw InvalidArgumentException::batchSize($batchSize);
        }
        $this->batchSize = $batchSize;
        $this->persisters = new DbalPersisterProvider($connection, $metadata, $filters);
    }

    /**
     * Recalcule celles de ces lignes qui sont encore périmées.
     *
     * @param class-string     $class
     * @param list<int|string> $ids   identifiants, valeurs de la base
     *
     * @return int vecteurs écrits
     *
     * @throws MappingException   champ sans #[EmbeddedVector]
     * @throws EmbeddingException fournisseur inconnu, en échec, ou réponse inutilisable
     */
    public function compute(string $class, string $field, array $ids): int
    {
        [$metadata, $embedding] = $this->mapping($class, $field);
        $written = 0;
        foreach (array_chunk($ids, $this->batchSize) as $batch) {
            $written += $this->computeBatch($metadata, $embedding, $batch, all: false);
        }

        return $written;
    }

    /**
     * Recalcule toutes les lignes périmées du champ, ou toutes les lignes avec $all, par lots
     * paginés sur l'identifiant. $progress reçoit le nombre de lignes lues après chaque lot.
     *
     * @param class-string               $class
     * @param (\Closure(int): void)|null $progress
     *
     * @return int vecteurs écrits
     *
     * @throws MappingException   champ sans #[EmbeddedVector]
     * @throws EmbeddingException
     */
    public function computeAll(string $class, string $field, bool $all = false, ?\Closure $progress = null): int
    {
        [$metadata, $embedding] = $this->mapping($class, $field);
        $persister = $this->persisters->getPersister($metadata);
        $written = 0;
        $after = null;
        while ([] !== $ids = $persister->loadIdsAfter($all ? null : $embedding->staleColumn, $after, $this->batchSize)) {
            $written += $this->computeBatch($metadata, $embedding, $ids, $all);
            $after = $ids[array_key_last($ids)];
            if (null !== $progress) {
                $progress(\count($ids));
            }
        }

        return $written;
    }

    /**
     * @param class-string $class
     *
     * @throws MappingException champ sans #[EmbeddedVector]
     */
    public function status(string $class, string $field): EmbeddingStatus
    {
        [$metadata, $embedding] = $this->mapping($class, $field);
        $fresh = 0;
        $stale = 0;
        $models = [];
        foreach ($this->persisters->getPersister($metadata)->countGroupedBy([$embedding->staleColumn, $embedding->modelColumn]) as $row) {
            $count = $row[BasicEntityPersister::COUNT_ALIAS] ?? 0;
            $count = is_numeric($count) ? (int) $count : 0;
            if (true === $this->connection->convertToPhp($row[$embedding->staleColumn] ?? null, 'boolean')) {
                $stale += $count;
            } else {
                $fresh += $count;
            }
            $model = $row[$embedding->modelColumn] ?? null;
            $model = \is_string($model) ? $model : '';
            $models[$model] = ($models[$model] ?? 0) + $count;
        }
        ksort($models);

        return new EmbeddingStatus($metadata->className, $field, $fresh, $stale, $models);
    }

    /**
     * @param class-string $class
     *
     * @return array{ClassMetadata<object>, EmbeddingMapping}
     *
     * @throws MappingException
     */
    private function mapping(string $class, string $field): array
    {
        $metadata = $this->metadata->getMetadataFor($class);

        return [$metadata, $metadata->getEmbedding($field) ?? throw MappingException::invalidField($metadata->className, $field, 'the field has no #[EmbeddedVector].')];
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param list<mixed>           $ids
     *
     * @throws EmbeddingException
     */
    private function computeBatch(ClassMetadata $metadata, EmbeddingMapping $embedding, array $ids, bool $all): int
    {
        $persister = $this->persisters->getPersister($metadata);
        $idColumn = $metadata->getIdField()->columnName;
        $sources = array_map(static fn (string $source): string => $metadata->getField($source)->columnName, $embedding->source);

        /** @var array<string, array{mixed, array<string, mixed>, string}> identifiant, sources lues, texte */
        $read = [];
        foreach ($persister->loadColumns([...$sources, $embedding->staleColumn], $ids) as $row) {
            if (!$all && true !== $this->connection->convertToPhp($row[$embedding->staleColumn] ?? null, 'boolean')) {
                continue;
            }
            $values = array_intersect_key($row, array_flip($sources));
            $text = implode("\n\n", array_filter(array_map(static fn (mixed $v): string => \is_string($v) ? trim($v) : '', array_values($values)), static fn (string $v): bool => '' !== $v));
            $read[self::key($row[$idColumn])] = [$row[$idColumn], $values, $text];
        }
        if ([] === $read) {
            return 0;
        }

        $provider = $this->providers->get($embedding->provider);
        $vectors = $this->embed($metadata, $embedding, $provider, array_values(array_filter(array_column($read, 2), static fn (string $t): bool => '' !== $t)));

        return $this->connection->transactional(function () use ($persister, $metadata, $embedding, $sources, $idColumn, $read, $vectors, $provider): int {
            $written = 0;
            $vectorColumn = $metadata->getField($embedding->field)->columnName;
            $current = [];
            foreach ($persister->loadColumns($sources, array_column($read, 0), lock: true) as $row) {
                $current[self::key($row[$idColumn])] = array_intersect_key($row, array_flip($sources));
            }
            foreach ($read as $key => [$id, $values, $text]) {
                // modifiée ou supprimée pendant le calcul : son propre recalcul est planifié
                if (($current[$key] ?? null) !== $values) {
                    continue;
                }
                $vector = '' === $text ? null : ($vectors[$text] ?? null);
                $written += $persister->update($id, [
                    $vectorColumn => null === $vector ? null : $this->connection->convertToDatabase($vector, 'vector'),
                    $embedding->staleColumn => $this->connection->convertToDatabase(false, 'boolean'),
                    $embedding->modelColumn => null === $vector ? null : $provider->model(),
                ]) > 0 ? 1 : 0;
            }

            return $written;
        });
    }

    /**
     * Un appel au fournisseur par lot de textes distincts ; chaque réponse est vérifiée.
     *
     * @param ClassMetadata<object> $metadata
     * @param list<string>          $texts
     *
     * @return array<string, list<float>> texte → vecteur
     *
     * @throws EmbeddingException
     */
    private function embed(ClassMetadata $metadata, EmbeddingMapping $embedding, EmbeddingProvider $provider, array $texts): array
    {
        $texts = array_values(array_unique($texts));
        if ([] === $texts) {
            return [];
        }
        $dimensions = $metadata->getField($embedding->field)->length;
        try {
            $vectors = $provider->embed($texts);
        } catch (EmbeddingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw EmbeddingException::failed($provider->model(), $e);
        }
        if (\count($vectors) !== \count($texts)) {
            throw EmbeddingException::invalidResponse($provider->model(), \sprintf('%d vectors for %d texts', \count($vectors), \count($texts)));
        }
        $byText = [];
        foreach ($texts as $i => $text) {
            $vector = $vectors[$i] ?? [];
            if (null !== $dimensions && \count($vector) !== $dimensions) {
                throw EmbeddingException::invalidResponse($provider->model(), VectorDimensionMismatchException::forField($metadata->className, $embedding->field, $dimensions, \count($vector))->getMessage());
            }
            $byText[$text] = $vector;
        }

        return $byText;
    }

    private static function key(mixed $id): string
    {
        return \is_scalar($id) ? (string) $id : '';
    }
}
