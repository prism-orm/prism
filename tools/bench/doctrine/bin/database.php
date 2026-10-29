<?php

declare(strict_types=1);

// php bin/database.php create|drop : la base commune aux deux ORM (ADR 0021)
require \dirname(__DIR__) . '/vendor/autoload.php';

use PrismVsDoctrine\Setup;

match ($argv[1] ?? '') {
    'create' => Setup::create(),
    'drop' => Setup::drop(),
    default => throw new \InvalidArgumentException('Usage: php bin/database.php create|drop'),
};
