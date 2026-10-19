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
.PHONY: help up down build install shell test test-unit test-db test-all-db stan cs cs-check infection bench coverage ci

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

bench: ## Benchmarks PHPBench
	$(RUN) vendor/bin/phpbench run --report=aggregate

ci: cs-check stan test ## Tout ce que la CI vérifie : à lancer avant de terminer une tâche
