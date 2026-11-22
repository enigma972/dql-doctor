COMPOSE ?= docker compose
MODE ?= default
SERVICE ?=
ARGS ?=

ifeq ($(MODE),default)
COMPOSE_FILES := -f compose.yaml -f compose.override.yaml
else ifeq ($(MODE),dev)
COMPOSE_FILES := -f compose.dev.yaml
else
$(error MODE must be default or dev)
endif

DC = $(COMPOSE) $(COMPOSE_FILES)

.DEFAULT_GOAL := help
.PHONY: help up build rebuild stop start restart down ps logs shell console migrate fixtures chaos watch watch-loop test config

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "; print "Usage: make [MODE=default|dev] <target> [SERVICE=name] [ARGS=\"...\"]\n\nTargets:"} /^[a-zA-Z_-]+:.*## / {printf "  %-12s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

up: ## Start the stack and wait until services are ready
	$(DC) up -d --wait

build: ## Build the application and worker images
	$(DC) build

rebuild: ## Rebuild and start the entire stack
	$(DC) up -d --build --wait

stop: ## Stop services without removing containers or data
	$(DC) stop $(SERVICE)

start: ## Start previously created services
	$(DC) start $(SERVICE)

restart: ## Restart all services or SERVICE
	$(DC) restart $(SERVICE)

down: ## Remove containers and network, keeping volumes
	$(DC) down

ps: ## Show all service statuses
	$(DC) ps -a

logs: ## Follow logs for all services or SERVICE
	$(DC) logs -f $(SERVICE)

shell: ## Open a shell in the app container
	$(DC) exec app sh

console: ## Run a Symfony command: make console ARGS="debug:router"
	$(DC) exec -T app php bin/console $(ARGS)

migrate: ## Apply Doctrine migrations without interaction
	$(DC) exec -T app php bin/console doctrine:migrations:migrate --no-interaction

fixtures: ## Load the dev demo user fixture without purging data
	$(DC) exec -T app php bin/console doctrine:fixtures:load --group=dlq-demo --append --no-interaction

chaos: ## Queue the four demo scenarios
	$(DC) exec -T app php bin/console app:chaos:inject

watch: ## Process one bounded batch from the failure transport
	$(DC) exec -T app php bin/console app:dlq:watch --once $(ARGS)

watch-loop: ## Watch failures continuously until interrupted
	$(DC) exec -T app php bin/console app:dlq:watch $(ARGS)

test: ## Run PHPUnit in the app container, optionally with ARGS
	$(DC) exec -T -e APP_ENV=test app php bin/phpunit $(ARGS)

config: ## Validate the selected Compose configuration
	$(DC) config --quiet
