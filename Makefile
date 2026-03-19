SHELL := /bin/bash

DOCKER_COMPOSE := docker compose
COMPOSE_DEV := $(DOCKER_COMPOSE)
COMPOSE_PROD := $(DOCKER_COMPOSE) -f compose.yaml -f compose.prod.yaml
PHP_SERVICE := php

.PHONY: help init setup build build-prod up up-prod down down-prod restart restart-prod \
	ps ps-prod logs logs-prod logs-php logs-mailer pull prune \
	sh root-sh composer composer-install composer-update \
	messenger-clear \
	console cc cache-warmup rate-limit-reset doctrine-migrate doctrine-diff doctrine-status \
	messenger-consume scheduler-consume test cs-fix phpstan quality check config config-prod stop stop-prod \
	tailwind-setup tailwind-init tailwind-watch tailwind-build assets-compile

help: ## Show all available commands
	@awk 'BEGIN {FS = ":.*## "; printf "\nUsage:\n  make <target>\n\nTargets:\n"} /^[a-zA-Z0-9_.-]+:.*## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

init: ## Ensure local data directory and SQLite file exist
	@mkdir -p data
	@touch data/research

setup: init build up composer-install ## One-shot local setup

build: ## Build local development images
	@$(COMPOSE_DEV) build

build-prod: ## Build production images
	@$(COMPOSE_PROD) build

up: init ## Start local development stack
	@$(COMPOSE_DEV) up -d --build

up-prod: init ## Start production stack locally
	@$(COMPOSE_PROD) up -d --build

down: ## Stop local development stack
	@$(COMPOSE_DEV) down

down-prod: ## Stop production stack
	@$(COMPOSE_PROD) down

stop: ## Stop local services without removing containers
	@$(COMPOSE_DEV) stop

stop-prod: ## Stop production services without removing containers
	@$(COMPOSE_PROD) stop

restart: ## Restart local development stack
	@$(COMPOSE_DEV) restart

restart-prod: ## Restart production stack
	@$(COMPOSE_PROD) restart

ps: ## Show local service status
	@$(COMPOSE_DEV) ps

ps-prod: ## Show production service status
	@$(COMPOSE_PROD) ps

logs: ## Stream all local logs
	@$(COMPOSE_DEV) logs -f

logs-prod: ## Stream all production logs
	@$(COMPOSE_PROD) logs -f

logs-php: ## Stream PHP service logs (local)
	@$(COMPOSE_DEV) logs -f $(PHP_SERVICE)

logs-mailer: ## Stream Mailpit logs (local)
	@$(COMPOSE_DEV) logs -f mailer

pull: ## Pull latest base images
	@$(COMPOSE_DEV) pull

prune: ## Remove local stack and orphan containers
	@$(COMPOSE_DEV) down --remove-orphans

sh: ## Open shell in PHP container as current user
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) bash

root-sh: ## Open root shell in PHP container
	@$(COMPOSE_DEV) exec $(PHP_SERVICE) bash

composer: ## Run composer command: make composer cmd='require symfony/mercure-bundle'
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) composer $(cmd)

composer-install: ## Install PHP dependencies in running local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) composer install

composer-update: ## Update PHP dependencies in running local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) composer update

console: ## Run Symfony console command: make console cmd='about'
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console $(cmd)

cc: ## Clear Symfony cache in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console cache:clear

rate-limit-reset: ## Reset research rate limiter (clears cache.rate_limiter pool)
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console cache:pool:clear cache.rate_limiter

cache-warmup: ## Warm Symfony cache in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console cache:warmup

doctrine-migrate: ## Run Doctrine migrations in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console doctrine:migrations:migrate --no-interaction

doctrine-diff: ## Generate Doctrine migration diff in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console doctrine:migrations:diff

doctrine-status: ## Show Doctrine migration status in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console doctrine:migrations:status

messenger-consume: ## Run Messenger consumer for async transport
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console messenger:consume async -vv

scheduler-consume: ## Run Messenger consumer for research maintenance scheduler
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console messenger:consume scheduler_research_maintenance -vv

messenger-clear: ## Clear async and failed Messenger queues
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console dbal:run-sql "DELETE FROM messenger_messages"
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console messenger:failed:remove --all --no-interaction

test: ## Run PHPUnit tests in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/phpunit

cs-fix: ## Run PHP CS Fixer in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php vendor/bin/php-cs-fixer fix

phpstan: ## Run PHPStan in local container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php vendor/bin/phpstan analyse -c phpstan.dist.neon

quality: ## Run cs-fix, phpstan, and tests
	@$(MAKE) cs-fix
	@$(MAKE) phpstan
	@$(MAKE) test

check: ## Run lightweight local sanity checks
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php -v
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console about

config: ## Validate local compose configuration
	@$(COMPOSE_DEV) config

config-prod: ## Validate production compose configuration
	@$(COMPOSE_PROD) config

tailwind-setup: tailwind-init tailwind-build ## Bootstrap Tailwind and run initial build

tailwind-init: ## Initialize Tailwind config/assets in container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console tailwind:init

tailwind-watch: ## Run Tailwind build in watch mode in container
	@$(COMPOSE_DEV) exec $(PHP_SERVICE) php bin/console tailwind:build --watch

tailwind-build: ## Build Tailwind CSS once in container
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console tailwind:build

assets-compile: tailwind-build ## Compile AssetMapper assets after Tailwind build
	@$(COMPOSE_DEV) exec -u $$(id -u):$$(id -g) $(PHP_SERVICE) php bin/console asset-map:compile
