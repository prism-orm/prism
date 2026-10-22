#!/bin/sh
# Compatibilité de Prism avec une vraie application Symfony (phase 7, étape 8) :
#   tools/compat/compat.sh <7.4|8> <skeleton|webapp>
# « 8 » : la dernière mineure de Symfony 8 (les 8.0.* sont visées par des avis de sécurité).
# Crée l'application, installe le bundle depuis le monorepo, applique la recette, puis vérifie :
# cache:clear (dev et prod), lint:container, intégrations absentes ou présentes selon les
# composants, make:entity → make:migration → db:migrations:migrate sur SQLite, db:schema:validate.
# Réseau requis (composer create-project). Lancé dans le conteneur PHP : make compat SF=8.0 APP=skeleton
set -euo pipefail

SF="${1:?Symfony version: 7.4 or 8}"
TEMPLATE="${2:?Template: skeleton or webapp}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
APP="$WORK/app"
trap 'rm -rf "$WORK"' EXIT
export COMPOSER_NO_INTERACTION=1 SYMFONY_DEPRECATIONS_HELPER=disabled

step() { printf '\n>>> %s\n' "$*"; }
check() { printf '    ✔ %s\n' "$*"; }
fail() { printf '    ✘ %s\n' "$*" >&2; exit 1; }

step "symfony/skeleton $SF, $TEMPLATE"
composer create-project "symfony/skeleton:$SF.*" "$APP"
cd "$APP"
composer config extra.symfony.require "$SF.*"
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.prism path "$ROOT/packages/*"

if [ webapp = "$TEMPLATE" ]; then
    step "webapp-pack, sans Doctrine"
    composer require webapp
    # Messenger n'arrive qu'à travers symfony/doctrine-messenger : on le garde en quittant Doctrine
    composer require symfony/messenger
    doctrine=$(php -r '$c = json_decode(file_get_contents("composer.json"), true); echo implode(" ", array_filter(array_keys([...$c["require"] ?? [], ...$c["require-dev"] ?? []]), static fn ($p) => str_starts_with($p, "doctrine/") || str_starts_with($p, "symfony/doctrine")));')
    # shellcheck disable=SC2086
    [ -n "$doctrine" ] && composer remove $doctrine
    rm -f config/packages/doctrine*.yaml
fi

step "prism-orm/bundle depuis le monorepo, et la recette"
composer require "prism-orm/bundle:*@dev"
php "$ROOT/tools/compat/apply-recipe.php" "$ROOT/recipes/prism-orm/bundle/0.7" "$APP"
grep -q 'Prism\\Bundle\\PrismBundle::class' config/bundles.php && check "bundles.php"
[ -f config/packages/prism.yaml ] && [ -d src/Entity ] && [ -d migrations ] && check "prism.yaml, src/Entity, migrations"
grep -q '^DATABASE_URL=' .env && check ".env"
echo "DATABASE_URL=\"sqlite:///%kernel.project_dir%/var/app.db?serverVersion=3.46.0\"" > .env.local

step "Conteneur"
bin/console cache:clear && check "cache:clear dev"
APP_ENV=prod APP_DEBUG=0 bin/console cache:clear && check "cache:clear prod"
bin/console lint:container && check "lint:container"
# un service privé inutilisé est retiré du conteneur compilé, mais son id reste dans le cache
# (removed-ids) : absent de tout le cache = jamais déclaré
for id in messenger.middleware.prism_reset 'Prism\Bundle\Validator\UniqueEntityValidator'; do
    if grep -rqF "$id" var/cache/dev; then present=yes; else present=no; fi
    case "$TEMPLATE" in
        skeleton) [ no = "$present" ] && check "$id never declared: its component is not installed" || fail "$id is declared without its component" ;;
        webapp) [ yes = "$present" ] && check "$id declared" || fail "$id is missing" ;;
    esac
done
if [ webapp = "$TEMPLATE" ]; then
    grep -rqF prism.data_collector var/cache/dev && check "profiler panel"
    # le middleware en vrai, comme le propose la recette
    printf 'framework:\n    messenger:\n        buses:\n            messenger.bus.default:\n                middleware: [prism_reset]\n' > config/packages/prism_messenger.yaml
    bin/console cache:clear && bin/console lint:container && check "Messenger bus with prism_reset"
fi

step "make:entity → make:migration → db:migrations:migrate"
composer require --dev symfony/maker-bundle
bin/console list make | grep -q 'Create or update a Prism entity' && check "make:entity is Prism's"
printf 'name\nstring\n80\nno\nprice\ndecimal\n10\n2\nno\n\n' | bin/console make:entity Product > /dev/null
[ -f src/Entity/Product.php ] && [ -f src/Repository/ProductRepository.php ] && check "Product and ProductRepository"
bin/console make:migration --no-interaction > /dev/null
ls migrations/Version*.php > /dev/null && check "migration"
bin/console db:migrations:migrate --no-interaction && check "migrated"
bin/console db:schema:validate && check "schema in sync with the mapping"
bin/console db:mapping:info | grep -q 'App\\Entity\\Product' && check "db:mapping:info"

printf '\nCompatible: Symfony %s, %s.\n' "$SF" "$TEMPLATE"
