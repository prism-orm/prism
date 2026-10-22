<?php

declare(strict_types=1);

namespace Prism\Bundle\Exception;

/**
 * #[MapEntity] mal employé : EntityManager inconnu, paramètre de route absent, champ inexistant.
 * Une erreur de programmation, jamais une 404.
 *
 * @api
 */
final class MapEntityException extends \LogicException implements BundleException
{
}
