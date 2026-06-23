ROOT := $(shell git rev-parse --show-toplevel)

.PHONY: help install-hooks test style stan

help:
	@echo "make install-hooks  Install the pre-push hook (run once after clone)"
	@echo "make test           Run PHPUnit"
	@echo "make style          Run Pint style check"
	@echo "make stan           Run Larastan static analysis"

# Symlinks the committed pre-push hook into .git/hooks/. Idempotent.
install-hooks:
	@ln -sf '$(ROOT)/scripts/git-hooks/pre-push' '$(ROOT)/.git/hooks/pre-push'
	@chmod +x '$(ROOT)/scripts/git-hooks/pre-push'
	@echo "Pre-push hook installed -> .git/hooks/pre-push"

test:
	@./vendor/bin/phpunit

style:
	@./vendor/bin/pint --test

stan:
	@./vendor/bin/phpstan analyse --no-progress --memory-limit=512M
