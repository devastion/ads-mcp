.PHONY: help setup dev lint lint-check types-check test ci-check build build-ssr check check-fix npm-types-check

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-18s %s\n", $$1, $$2}'

setup: ## Install deps, create .env, generate key, migrate, build frontend
	composer run setup

dev: ## Run the dev server (php artisan dev)
	composer run dev

lint: ## Fix code style with Pint
	composer run lint

lint-check: ## Check code style with Pint (no changes)
	composer run lint:check

types-check: ## Run PHPStan static analysis
	composer run types:check

test: ## Clear config, run lint check, PHPStan and the test suite
	composer run test

ci-check: ## Run the full CI pipeline (npm check, tsc, then test)
	composer run ci:check

build: ## Build frontend assets
	npm run build

build-ssr: ## Build frontend assets with SSR bundle
	npm run build:ssr

check: ## Run frontend checks (vp check)
	npm run check

check-fix: ## Run frontend checks and fix issues
	npm run check:fix

npm-types-check: ## Run TypeScript type check (tsc --noEmit)
	npm run types:check
