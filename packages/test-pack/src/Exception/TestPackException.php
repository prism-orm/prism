<?php

declare(strict_types=1);

namespace Prism\TestPack\Exception;

use Prism\Exception\PrismException;

/**
 * Mauvais usage du test-pack : factory sans kernel démarré, attribut inconnu, requêtes non
 * enregistrées. Une erreur de test, jamais d'application.
 *
 * @api
 */
final class TestPackException extends \LogicException implements PrismException
{
}
