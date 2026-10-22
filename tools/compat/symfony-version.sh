#!/bin/sh
# Les tests du bundle et du test-pack sous une autre version de Symfony (ADR 0013, section 7) :
#   tools/compat/symfony-version.sh 7.4
# Remplace les paquets Symfony du vendor et du lock courants : à lancer sur une copie
# (make test-symfony SF=7.4) ou sur un checkout jetable (CI). DATABASE_URL : la base de test.
set -eu

SF="${1:?Symfony version, e.g. 7.4}"
export COMPOSER_NO_INTERACTION=1
[ -d vendor ] || composer install --quiet
with=$(php -r '$l = json_decode(file_get_contents("composer.lock"), true); foreach ([...$l["packages"], ...$l["packages-dev"]] as $p) { $n = $p["name"]; if (str_starts_with($n, "symfony/") && !str_contains($n, "polyfill") && !str_contains($n, "contracts") && "symfony/maker-bundle" !== $n) { echo "--with=$n:'"$SF"'.* "; } }')
# shellcheck disable=SC2086
composer update 'symfony/*' -W $with --quiet
printf 'symfony/framework-bundle %s\n' "$(composer show symfony/framework-bundle | sed -n 's/^versions : \* //p')"
vendor/bin/phpunit packages/bundle/tests packages/test-pack/tests
