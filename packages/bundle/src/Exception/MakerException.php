<?php

declare(strict_types=1);

namespace Prism\Bundle\Exception;

/**
 * make:entity ou make:migration ne peut pas aller au bout : fichier illisible, classe absente.
 *
 * @api
 */
final class MakerException extends \RuntimeException implements BundleException
{
}
