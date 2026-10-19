<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Orm\Exception\CyclicDependencyException;
use Prism\Orm\Exception\InvalidArgumentException;

/**
 * Ordre des INSERT d'un flush (RG-04.3) : chaque entité après les entités qu'elle référence.
 *
 * - Tri stable : entre entités indépendantes, l'ordre d'ajout (celui de persist()) est gardé.
 * - Cycle (RG-04.4) : rompu sur une FK nullable, la moins souvent possible. Les FK obligatoires
 *   sont toutes gardées, puis les FK nullables dans leur ordre de déclaration ; celle qui
 *   fermerait un cycle est différée. Un cycle de FK obligatoires lève CyclicDependencyException.
 * - Une FK ne peut fermer un cycle qu'à l'intérieur d'une composante fortement connexe (Tarjan) :
 *   les autres sont gardées sans recherche de chemin, et ne sont jamais différées.
 *
 * Tout est itératif : un flush de milliers d'entités chaînées ne dépend pas de la pile.
 *
 * @internal
 */
final class CommitOrderCalculator
{
    /** @var array<int, string> libellé de chaque entité, dans l'ordre d'ajout */
    private array $labels = [];

    /** @var list<Dependency> */
    private array $dependencies = [];

    public function addNode(int $id, string $label): void
    {
        $this->labels[$id] = $label;
    }

    /**
     * $from porte une FK $field vers $to : $to est inséré d'abord.
     *
     * @throws InvalidArgumentException entité non ajoutée par addNode()
     */
    public function addDependency(int $from, int $to, string $field, bool $nullable): void
    {
        foreach ([$from, $to] as $id) {
            if (!isset($this->labels[$id])) {
                throw InvalidArgumentException::unknownCommitNode($id);
            }
        }
        $this->dependencies[] = new Dependency($from, $to, $field, $nullable);
    }

    /**
     * @throws CyclicDependencyException cycle de FK obligatoires
     */
    public function sort(): CommitOrder
    {
        $components = $this->components();
        $kept = [];
        $deferred = [];
        /** @var array<int, list<Dependency>> FK gardées à l'intérieur des composantes, par entité */
        $internal = [];

        // obligatoires d'abord, nullables ensuite, chacune dans son ordre de déclaration (tri stable)
        $keys = array_keys($this->dependencies);
        usort($keys, fn (int $a, int $b): int => $this->dependencies[$a]->nullable <=> $this->dependencies[$b]->nullable);
        foreach ($keys as $key) {
            $dependency = $this->dependencies[$key];
            if ($components[$dependency->from] !== $components[$dependency->to]) {
                $kept[] = $dependency;
                continue;
            }
            $cycle = $this->path($dependency->to, $dependency->from, $internal);
            if (null === $cycle) {
                $kept[] = $dependency;
                $internal[$dependency->from][] = $dependency;
            } elseif ($dependency->nullable) {
                $deferred[$key] = $dependency;
            } else {
                throw CyclicDependencyException::forCycle($this->describe([...$cycle, $dependency]));
            }
        }

        return new CommitOrder($this->order($kept), array_values($deferred));
    }

    /**
     * Kahn : la prochaine entité insérable est toujours la première ajoutée.
     *
     * @param list<Dependency> $kept
     *
     * @return list<int>
     */
    private function order(array $kept): array
    {
        $position = array_flip(array_keys($this->labels));
        $ids = array_keys($this->labels);
        $waiting = array_fill_keys($ids, 0);
        $dependents = [];
        foreach ($kept as $dependency) {
            ++$waiting[$dependency->from];
            $dependents[$dependency->to][] = $dependency->from;
        }

        /** @var \SplMinHeap<int> $ready positions d'ajout des entités insérables */
        $ready = new \SplMinHeap();
        foreach ($waiting as $id => $count) {
            if (0 === $count) {
                $ready->insert($position[$id]);
            }
        }

        $order = [];
        while (!$ready->isEmpty()) {
            /** @var int $next */
            $next = $ready->extract();
            $id = $ids[$next];
            $order[] = $id;
            foreach ($dependents[$id] ?? [] as $dependent) {
                if (0 === --$waiting[$dependent]) {
                    $ready->insert($position[$dependent]);
                }
            }
        }

        return $order;
    }

    /**
     * Composante fortement connexe de chaque entité (Tarjan, itératif).
     *
     * @return array<int, int>
     */
    private function components(): array
    {
        $edges = [];
        foreach ($this->dependencies as $dependency) {
            $edges[$dependency->from][] = $dependency->to;
        }

        $next = 0;
        $index = [];
        $low = [];
        /** @var list<int> $stack */
        $stack = [];
        $onStack = [];
        $components = [];
        $count = 0;

        foreach (array_keys($this->labels) as $root) {
            if (isset($index[$root])) {
                continue;
            }
            $index[$root] = $low[$root] = $next++;
            $stack[] = $root;
            $onStack[$root] = true;
            /** @var list<array{int, int}> entité et prochaine FK à suivre */
            $work = [[$root, 0]];

            while ([] !== $work) {
                $top = \count($work) - 1;
                [$node, $edge] = $work[$top];
                $targets = $edges[$node] ?? [];

                if ($edge < \count($targets)) {
                    $work[$top][1] = $edge + 1;
                    $target = $targets[$edge];
                    if (!isset($index[$target])) {
                        $index[$target] = $low[$target] = $next++;
                        $stack[] = $target;
                        $onStack[$target] = true;
                        $work[] = [$target, 0];
                    } elseif (isset($onStack[$target])) {
                        $low[$node] = min($low[$node], $index[$target]);
                    }
                    continue;
                }

                array_pop($work);
                if ([] !== $work) {
                    $parent = $work[\count($work) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }
                if ($low[$node] === $index[$node]) {
                    do {
                        $member = array_pop($stack) ?? $node;
                        unset($onStack[$member]);
                        $components[$member] = $count;
                    } while ($member !== $node);
                    ++$count;
                }
            }
        }

        return $components;
    }

    /**
     * Chemin de $start à $target par les FK gardées, null s'il n'y en a pas (vide si $start est
     * $target).
     *
     * @param array<int, list<Dependency>> $edges
     *
     * @return list<Dependency>|null
     */
    private function path(int $start, int $target, array $edges): ?array
    {
        if ($start === $target) {
            return [];
        }

        /** @var array<int, Dependency|null> FK par laquelle chaque entité a été atteinte */
        $reachedBy = [$start => null];
        $queue = [$start];
        while ([] !== $queue) {
            $node = array_shift($queue);
            foreach ($edges[$node] ?? [] as $dependency) {
                if (\array_key_exists($dependency->to, $reachedBy)) {
                    continue;
                }
                $reachedBy[$dependency->to] = $dependency;
                if ($dependency->to === $target) {
                    $path = [];
                    for ($step = $dependency; null !== $step; $step = $reachedBy[$step->from]) {
                        array_unshift($path, $step);
                    }

                    return $path;
                }
                $queue[] = $dependency->to;
            }
        }

        return null;
    }

    /**
     * « Order -[customer]-> Customer -[firstOrder]-> Order ».
     *
     * @param non-empty-list<Dependency> $cycle
     */
    private function describe(array $cycle): string
    {
        $text = $this->labels[$cycle[0]->from];
        foreach ($cycle as $dependency) {
            $text .= \sprintf(' -[%s]-> %s', $dependency->field, $this->labels[$dependency->to]);
        }

        return $text;
    }
}
