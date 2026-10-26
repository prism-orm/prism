# Prism — commandes de développement (exécutées dans le conteneur php)
DB ?= mysql
DC := docker compose
RUN := $(DC) run --rm php

# root sous MySQL/MariaDB : la suite crée et détruit sa propre base (RG-00.1)
URL_mysql   := mysql://root:root@mysql:3306/prism_test?serverVersion=8.4
URL_mariadb := mysql://root:root@mariadb:3306/prism_test?serverVersion=11.8.0-MariaDB
URL_pgsql   := postgresql://prism:prism@postgres:5432/prism_test?serverVersion=16
URL_sqlite  := sqlite:///:memory:

DATABASES := mysql mariadb pgsql sqlite

.DEFAULT_GOAL := help
.PHONY: help up down build install shell test test-unit test-db test-all-db stan cs cs-check infection bench bench-tenancy bench-baseline bench-compare coverage compat test-symfony demo-install demo-test worker-test ci

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

build: ## Construit l'image PHP
	$(DC) build php

up: ## Démarre les bases de données
	$(DC) up -d --wait mysql mariadb postgres

down: ## Arrête et supprime les conteneurs
	$(DC) down --remove-orphans

install: ## Installe les dépendances Composer
	$(RUN) composer install

shell: ## Ouvre un shell dans le conteneur PHP
	$(RUN) sh

test: test-unit test-all-db ## Tous les tests, toutes les bases

test-unit: ## Tests unitaires (sans base)
	$(RUN) vendor/bin/phpunit --testsuite unit

test-db: ## Tests fonctionnels sur une base : make test-db DB=mysql|mariadb|pgsql|sqlite
	$(RUN) env DATABASE_URL='$(URL_$(DB))' vendor/bin/phpunit --testsuite functional

test-all-db: ## Tests fonctionnels sur les 4 bases
	@for db in $(DATABASES); do printf "\n>>> %s\n" $$db; $(MAKE) --no-print-directory test-db DB=$$db || exit 1; done

coverage: ## Couverture HTML dans var/coverage
	$(RUN) env DATABASE_URL='$(URL_mysql)' vendor/bin/phpunit --coverage-html var/coverage

stan: ## PHPStan niveau max
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Corrige le style (PHP-CS-Fixer)
	$(RUN) vendor/bin/php-cs-fixer fix

cs-check: ## Vérifie le style sans corriger
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

infection: ## Mutation testing (une classe : make infection FILTER=packages/dbal/src/Schema/Comparator.php)
	$(RUN) vendor/bin/infection --threads=max --show-mutations $(if $(FILTER),--filter=$(FILTER))

bench: ## Benchmarks et critères de la phase 3, sur MySQL par défaut : make bench [DB=mariadb|pgsql]
	$(RUN) env DATABASE_URL='$(URL_$(DB))' vendor/bin/phpbench run --report=aggregate $(if $(FILTER),--filter=$(FILTER))

bench-tenancy: ## Critère de la phase 8 : surcoût du filtre tenant < 5 % sur la lecture, sur MySQL par défaut : make bench-tenancy [DB=mariadb|pgsql]
	$(RUN) env DATABASE_URL='$(URL_$(DB))' php -d opcache.enable_cli=1 tools/bench/tenancy-overhead.php

bench-baseline: ## Enregistre la référence de bench-compare (avant une modification de l'UoW ou de l'hydratation)
	$(RUN) env DATABASE_URL='$(URL_$(DB))' vendor/bin/phpbench run --report=aggregate --tag=baseline $(if $(FILTER),--filter=$(FILTER))

bench-compare: ## Compare à la référence : échoue au-delà de 10 % de régression (RT-15)
	$(RUN) env DATABASE_URL='$(URL_$(DB))' vendor/bin/phpbench run --report=aggregate --ref=baseline --assert='mode(variant.time.avg) <= mode(baseline.time.avg) +/- 10%' $(if $(FILTER),--filter=$(FILTER))

compat: ## Une vraie application Symfony avec Prism et sa recette : make compat SF=7.4|8 APP=skeleton|webapp (réseau requis)
	$(RUN) tools/compat/compat.sh $(SF) $(APP)

test-symfony: ## Tests du bundle et du test-pack sous une autre version de Symfony, dans une copie : make test-symfony SF=7.4 (réseau requis)
	rm -rf tmp/symfony-$(SF) && mkdir -p tmp/symfony-$(SF)/var
	rsync -a --exclude vendor --exclude var --exclude tmp --exclude .git ./ tmp/symfony-$(SF)/
	$(DC) run --rm -w /app/tmp/symfony-$(SF) php env DATABASE_URL='$(URL_mysql)' tools/compat/symfony-version.sh $(SF)

demo-install: ## La démo ERP : dépendances, base prism_demo, migrations, jeu de données
	$(RUN) sh -c 'cd demo && composer install --quiet && bin/console db:database:create --if-not-exists && bin/console db:migrations:migrate -n && bin/console app:seed'

demo-test: ## Les tests de la démo ERP (base prism_demo_test)
	$(DC) exec -T mysql mysql -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS prism_demo_test' 2>/dev/null
	$(RUN) sh -c 'cd demo && vendor/bin/phpunit'

worker-test: ## La démo en worker FrankenPHP, 10 000 requêtes, mémoire mesurée : make worker-test [N=10000] (après make demo-install)
	$(DC) --profile demo up -d --build --wait frankenphp
	$(RUN) php tools/worker/load.php http://frankenphp $(or $(N),10000)
	$(DC) --profile demo stop frankenphp

ci: cs-check stan test ## Tout ce que la CI vérifie : à lancer avant de terminer une tâche
