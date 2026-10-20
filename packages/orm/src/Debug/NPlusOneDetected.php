<?php

declare(strict_types=1);

namespace Prism\Orm\Debug;

/**
 * La même requête (SQL normalisé) a été exécutée plus de fois que le seuil du N1Detector dans le
 * périmètre en cours (RG-04.15) : le signe d'un chargement par entité dans une boucle.
 *
 * @api
 */
final class NPlusOneDetected
{
    /**
     * @param string $origin « fichier:ligne » de la première frame hors vendor/ et hors Prism
     */
    public function __construct(
        public readonly string $sql,
        public readonly int $count,
        public readonly string $origin,
    ) {
    }

    public function withCount(int $count): self
    {
        return new self($this->sql, $count, $this->origin);
    }
}
