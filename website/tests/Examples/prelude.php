<?php

declare(strict_types=1);

/*
 * Préfixé à chaque exemple de la documentation (auto_prepend_file, ExamplesTest). Les variables
 * ci-dessous sont celles qu'un exemple peut employer sans les définir ; les pages s'y tiennent.
 */

use Prism\Website\Tests\Support\ExampleRuntime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// $em : l'EntityManager de la démo ERP, tenant « acme » ; $tenants : son StackTenantContext
[$em, $connection, $tenants] = ExampleRuntime::start();
$entityManager = $em;
$page = 1;
