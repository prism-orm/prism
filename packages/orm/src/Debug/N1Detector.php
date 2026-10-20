<?php

declare(strict_types=1);

namespace Prism\Orm\Debug;

use Prism\Dbal\Connection;
use Prism\Dbal\QueryObserver;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\UnitOfWork\UnitOfWork;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Détecteur N+1 (pilier 1, RG-04.15), à brancher en dev seulement (RG-04.16) comme observateur
 * de la Connection (ADR 0008).
 *
 * Il compte les lectures (SELECT, WITH) par SQL normalisé — les écritures d'un flush se
 * répètent normalement, une par entité — (paramètres, littéraux et listes IN ramenés à « ? »)
 * dans le périmètre en cours ; au-delà du seuil, il publie une fois NPlusOneDetected avec
 * l'origine : la première frame qui n'est ni dans vendor/ ni dans le code de Prism, c'est-à-dire
 * la ligne de l'application qui a déclenché la boucle. reset() ouvre un nouveau périmètre (une
 * requête HTTP, un message).
 *
 * @api
 */
final class N1Detector implements QueryObserver
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, NPlusOneDetected> */
    private array $detections = [];

    /** @var list<string> */
    private readonly array $ignoredPaths;

    /**
     * @param int               $threshold    nombre de requêtes identiques toléré (5 par défaut)
     * @param list<string>|null $ignoredPaths dossiers ignorés pour l'origine ; par défaut le code de
     *                                        Prism (dbal et orm) ; vendor/ est toujours ignoré
     *
     * @throws InvalidArgumentException seuil inférieur à 1
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $events = null,
        private readonly int $threshold = 5,
        ?array $ignoredPaths = null,
    ) {
        if ($threshold < 1) {
            throw InvalidArgumentException::threshold($threshold);
        }
        $this->ignoredPaths = $ignoredPaths ?? [self::sourceOf(UnitOfWork::class), self::sourceOf(Connection::class)];
    }

    public function queryExecuted(string $sql, array $params, float $durationMs): void
    {
        if (1 !== preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            return;
        }
        $key = self::normalize($sql);
        $count = $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        if (isset($this->detections[$key])) {
            $this->detections[$key] = $this->detections[$key]->withCount($count);
        } elseif ($count > $this->threshold) {
            $detection = new NPlusOneDetected($key, $count, $this->origin());
            $this->detections[$key] = $detection;
            $this->events?->dispatch($detection);
        }
    }

    /**
     * Les détections du périmètre, avec le nombre de requêtes à jour.
     *
     * @return list<NPlusOneDetected>
     */
    public function getDetections(): array
    {
        return array_values($this->detections);
    }

    public function reset(): void
    {
        $this->counts = [];
        $this->detections = [];
    }

    /**
     * La forme d'une requête : deux requêtes qui ne diffèrent que par leurs valeurs ont la même.
     */
    public static function normalize(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/(?<![\w"`])\d+(?:\.\d+)?(?![\w"`])/', '?', $sql);
        $sql = (string) preg_replace('/:[A-Za-z_]\w*/', '?', $sql);
        $sql = (string) preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql);

        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function origin(): string
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if (null !== $file && !$this->isIgnored($file)) {
                return $file . ':' . ($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }

    private function isIgnored(string $file): bool
    {
        if (str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
            return true;
        }
        foreach ($this->ignoredPaths as $path) {
            if (str_starts_with($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dossier src/ du paquet qui contient la classe.
     *
     * @param class-string $class
     */
    private static function sourceOf(string $class): string
    {
        $file = (string) new \ReflectionClass($class)->getFileName();
        $src = \DIRECTORY_SEPARATOR . 'src' . \DIRECTORY_SEPARATOR;

        return substr($file, 0, (int) strrpos($file, $src) + \strlen($src));
    }
}
