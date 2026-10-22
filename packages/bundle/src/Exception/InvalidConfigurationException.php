<?php

declare(strict_types=1);

namespace Prism\Bundle\Exception;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException as SymfonyInvalidConfigurationException;

/**
 * prism.yaml incohérent : formes courte et complète mêlées, connexion ou EntityManager inconnu.
 *
 * @api
 */
final class InvalidConfigurationException extends SymfonyInvalidConfigurationException implements BundleException
{
}
