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
.PHONY: help up down build install shell test test-unit test-db test-all-db stan cs cs-check infection bench bench-tenancy bench-vector bench-doctrine bench-baseline bench-compare coverage compat test-symfony split docs-install docs-dev docs-build docs-publish ci

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

bench-vector: ## Critère de la phase 9 : nearest() < 50 ms (p95) sur 100 000 vecteurs de 768 dimensions, sous MariaDB et PostgreSQL : make bench-vector [DB=mariadb|pgsql] [ROWS=100000] [KEEP=1]
	@for db in $(if $(filter mariadb pgsql,$(DB)),$(DB),mariadb pgsql); do \
		url=$$( [ $$db = pgsql ] && echo '$(URL_pgsql)' || echo '$(URL_mariadb)' ); \
		$(RUN) env DATABASE_URL="$$url" ROWS=$(or $(ROWS),100000) KEEP=$(KEEP) php -d memory_limit=1G -d opcache.enable_cli=1 tools/bench/vector-nearest.php || exit 1; \
	done

bench-doctrine: ## Prism face à Doctrine ORM 3 (ADR 0021) : hydratation, flush, N+1, mémoire en worker, sur MySQL et PostgreSQL : make bench-doctrine [DB=mysql|pgsql] [REQUESTS=10000]
	$(RUN) sh -c 'cd tools/bench/doctrine && composer install --quiet --no-interaction'
	@for db in $(if $(filter command line,$(origin DB)),$(DB),mysql pgsql); do \
		url=$$( [ $$db = pgsql ] && echo '$(URL_pgsql)' || echo '$(URL_mysql)' ); \
		echo "=== $$db"; \
		$(RUN) env DATABASE_URL="$$url" sh -c 'cd tools/bench/doctrine && php bin/database.php create \
			&& vendor/bin/phpbench run --report=aggregate --progress=none \
			&& php -d opcache.enable_cli=1 -d pcov.enabled=0 bin/queries.php \
			&& php -d opcache.enable_cli=1 -d pcov.enabled=0 -d memory_limit=1G bin/worker.php prism $(or $(REQUESTS),10000) \
			&& php -d opcache.enable_cli=1 -d pcov.enabled=0 -d memory_limit=1G bin/worker.php doctrine $(or $(REQUESTS),10000); \
			status=$$?; php bin/database.php drop; exit $$status' || exit 1; \
	done

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

split: ## Publie packages/* vers les dépôts prism-orm/* depuis ce poste, sans GitHub Actions (ADR 0004) ; à blanc sans PUSH=1 : make split [TAG="v0.8.0 v0.9.0"] [PUSH=1] [FORCE=1]
	TAG='$(TAG)' PUSH='$(PUSH)' FORCE='$(FORCE)' $(if $(REMOTE),REMOTE='$(REMOTE)') tools/split/split.sh

docs-install: ## Le site de documentation : dépendances npm (ADR 0016)
	$(DC) run --rm node npm ci

docs-dev: ## Le site en local avec rechargement : http://localhost:5173 (après make docs-install)
	$(DC) run --rm --service-ports node npm run dev

docs-build: ## Construit le site dans website/.vitepress/dist ; échoue sur un lien interne cassé
	$(DC) run --rm node npm run build

docs-publish: docs-build ## Publie le site sur GitHub Pages (branche gh-pages) depuis ce poste, sans GitHub Actions (ADR 0016) ; à blanc sans PUSH=1 : make docs-publish [PUSH=1]
	PUSH='$(PUSH)' $(if $(REMOTE),REMOTE='$(REMOTE)') $(if $(ANY_BRANCH),ANY_BRANCH='$(ANY_BRANCH)') tools/pages/publish.sh

ci: cs-check stan test ## Tout ce que la CI vérifie : à lancer avant de terminer une tâche
