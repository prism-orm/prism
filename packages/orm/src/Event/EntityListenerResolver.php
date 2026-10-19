<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

/**
 * Fournit l'instance d'une classe déclarée dans #[EntityListeners] (décision D) ; le bundle
 * branche le conteneur de services.
 *
 * @api
 */
interface EntityListenerResolver
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function resolve(string $class): object;
}
