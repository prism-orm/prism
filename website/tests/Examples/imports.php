<?php

declare(strict_types=1);

/*
 * Imports explicites des exemples de la documentation (ExampleScript) : les noms ambigus et
 * ceux qui ne viennent ni de Prism ni de la démo. Ils l'emportent sur les imports déduits.
 *
 * Convention des pages : le mapping s'écrit avec l'alias Prism\ (#[Prism\Entity]), comme dans la démo.
 */
return [
    'Prism' => 'Prism\\Orm\\Mapping',
    // dans une migration, Column est la colonne du schéma ; dans une entité, #[Prism\Column]
    'Column' => 'Prism\\Dbal\\Schema\\Column',
    'Table' => 'Prism\\Dbal\\Schema\\Table',
    'Index' => 'Prism\\Dbal\\Schema\\Index',

    'AbstractController' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
    'WebTestCase' => 'Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase',
    'Request' => 'Symfony\\Component\\HttpFoundation\\Request',
    'Response' => 'Symfony\\Component\\HttpFoundation\\Response',
    'Route' => 'Symfony\\Component\\Routing\\Attribute\\Route',
];
