<?php

declare(strict_types=1);

/*
 * Applique une recette Flex locale à une application, comme le ferait Flex une fois la recette
 * publiée sur symfony/recipes-contrib : bundles, copy-from-recipe, env.
 *
 *     php apply-recipe.php <dossier de la recette> <dossier de l'application>
 */

[, $recipe, $app] = $argv + [null, null, null];
if (!is_string($recipe) || !is_string($app) || !is_file($recipe . '/manifest.json')) {
    fwrite(STDERR, "Usage: php apply-recipe.php <recipe dir> <app dir>\n");
    exit(1);
}
$manifest = json_decode((string) file_get_contents($recipe . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

// bundles
$bundlesFile = $app . '/config/bundles.php';
$bundles = require $bundlesFile;
foreach ($manifest['bundles'] ?? [] as $class => $envs) {
    $bundles[$class] = array_fill_keys($envs, true);
}
$lines = [];
foreach ($bundles as $class => $envs) {
    $lines[] = sprintf('    %s::class => [%s],', $class, implode(', ', array_map(static fn (string $env): string => sprintf("'%s' => true", $env), array_keys($envs))));
}
file_put_contents($bundlesFile, "<?php\n\nreturn [\n" . implode("\n", $lines) . "\n];\n");

// copy-from-recipe
$targets = ['%CONFIG_DIR%' => 'config', '%SRC_DIR%' => 'src'];
foreach ($manifest['copy-from-recipe'] ?? [] as $from => $to) {
    $to = strtr($to, $targets);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($recipe . '/' . rtrim($from, '/'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $target = $app . '/' . rtrim($to, '/') . '/' . substr($file->getPathname(), strlen($recipe . '/' . rtrim($from, '/')) + 1);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o777, true);
        }
        if (!file_exists($target)) {
            copy($file->getPathname(), $target);
        }
    }
}

// env
$env = "\n###> prism-orm/bundle ###\n";
foreach ($manifest['env'] ?? [] as $name => $value) {
    $env .= str_starts_with($name, '#') ? '# ' . $value . "\n" : $name . '="' . $value . "\"\n";
}
file_put_contents($app . '/.env', $env . "###< prism-orm/bundle ###\n", FILE_APPEND);

echo "Recipe applied.\n";
