# Executables (local)
DOCKER_COMP = docker compose

# Docker containers
PHP_CONT = $(DOCKER_COMP) exec php

# Executables
PHP      = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer
SYMFONY  = $(PHP) bin/console

# Misc
.DEFAULT_GOAL = help
.PHONY        : help build up start down logs sh bash test anvil-up anvil-down test-integration composer vendor sf cc migrate phpstan deptrac cs cs-fix qa lint ci-local

## —— 🎵 🐳 The Symfony Docker Makefile 🐳 🎵 ——————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
build: ## Builds the Docker images
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start the docker hub in detached mode (no logs)
	@$(DOCKER_COMP) up --detach

start: build up ## Build and start the containers

down: ## Stop the docker hub
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Show live logs
	@$(DOCKER_COMP) logs --tail=0 --follow

sh: ## Connect to the FrankenPHP container
	@$(PHP_CONT) sh

bash: ## Connect to the FrankenPHP container via bash so up and down arrows go to previous commands
	@$(PHP_CONT) bash

test: ## Start tests with phpunit, pass the parameter "c=" to add options to phpunit, example: make test c="--filter=WalletAddressTest"
	@$(eval c ?=)
	@$(PHP_CONT) bin/phpunit $(c)

## —— Anvil fork (Deposit integration tests) 🔱 —————————————————————————————————
anvil-up: ## Start the local Base fork (Anvil) used by the Deposit integration tests
	@$(DOCKER_COMP) --profile integration up -d anvil

anvil-down: ## Stop the Anvil fork
	@$(DOCKER_COMP) --profile integration stop anvil

test-integration: ## Run the Deposit flow integration tests against Anvil (run "make anvil-up" first)
	@$(PHP_CONT) sh -c 'BASE_RPC_URL=http://anvil:8545 bin/phpunit --group=integration'

## —— Composer 🧙 ——————————————————————————————————————————————————————————————
composer: ## Run composer, pass the parameter "c=" to run a given command, example: make composer c='req symfony/orm-pack'
	@$(eval c ?=)
	@$(COMPOSER) $(c)

vendor: ## Install vendors according to the current composer.lock file
vendor: c=install --prefer-dist --no-progress --no-interaction
vendor: composer

## —— Symfony 🎵 ———————————————————————————————————————————————————————————————
sf: ## List all Symfony commands or pass the parameter "c=" to run a given command, example: make sf c=about
	@$(eval c ?=)
	@$(SYMFONY) $(c)

cc: c=c:c ## Clear the cache
cc: sf

migrate: ## Run pending Doctrine migrations (dev database)
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction

## —— Quality 🔍 ———————————————————————————————————————————————————————————————
phpstan: ## Run PHPStan static analysis
	@$(PHP_CONT) vendor/bin/phpstan analyse --memory-limit=512M

deptrac: ## Run Deptrac architecture boundary analysis
	@$(PHP_CONT) vendor/bin/deptrac analyse --no-cache

cs: ## Check code style with php-cs-fixer (dry-run)
	@$(PHP_CONT) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style with php-cs-fixer
	@$(PHP_CONT) vendor/bin/php-cs-fixer fix

qa: test phpstan deptrac cs ## Run the full quality suite: tests, PHPStan, Deptrac, cs-fixer (dry-run)

lint: ## Run super-linter locally (same config/env as the CI "Lint" job): only files changed vs main, not the whole codebase
	@docker run --rm \
		-e RUN_LOCAL=true \
		-e VALIDATE_ALL_CODEBASE=false \
		-e DEFAULT_BRANCH=main \
		-e VALIDATE_CHECKOV=false \
		-e VALIDATE_TRIVY=false \
		-e VALIDATE_BIOME_FORMAT=false \
		-e VALIDATE_BIOME_LINT=false \
		-e VALIDATE_PHP_BUILTIN=false \
		-e VALIDATE_PHP_PHPCS=false \
		-e VALIDATE_PHP_PHPSTAN=false \
		-e VALIDATE_PHP_PSALM=false \
		-e FILTER_REGEX_EXCLUDE='(^|/)(vendor|var|node_modules)/|assets/(vendor|build)/|composer\.(json|lock)|symfony\.lock|importmap\.php|mate/' \
		-e VALIDATE_JAVASCRIPT_PRETTIER=false \
		-e VALIDATE_JSON_PRETTIER=false \
		-e VALIDATE_JSX_PRETTIER=false \
		-e VALIDATE_MARKDOWN_PRETTIER=false \
		-e VALIDATE_YAML_PRETTIER=false \
		-e VALIDATE_JAVASCRIPT_ES=false \
		-e VALIDATE_JSX=false \
		-e VALIDATE_MARKDOWN=false \
		-e VALIDATE_JSCPD=false \
		-e VALIDATE_NATURAL_LANGUAGE=false \
		-e VALIDATE_SPELL_CODESPELL=false \
		-e VALIDATE_BASH=false \
		-v "$(CURDIR)":/tmp/lint \
		ghcr.io/super-linter/super-linter:slim-v8

ci-local: qa lint ## Run everything the CI pipeline runs (Tests + Lint), locally, before pushing
