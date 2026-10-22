<?php

declare(strict_types=1);

namespace Prism\TestPack\Attribute;

/**
 * Sur un test ou une classe de test qui utilise ResetDatabase : pas de transaction englobante,
 * les commits sont réels (lecture depuis une autre connexion, DDL sous MySQL) ; le schéma est
 * recréé après le test (RG-07.6).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class WithRealTransactions
{
}
