COMPOSE := docker compose -f tests/docker/docker-compose.yml -p lake-php-it
IT_DSN ?= http://root:@query:8000/default?sslmode=disable
# Laravel version for the integration run, e.g. IT_LARAVEL='9.*' (default: latest).
IT_LARAVEL ?=
IT_COMPOSER := $(if $(IT_LARAVEL),composer update --no-interaction --quiet --with "illuminate/database:$(IT_LARAVEL)",composer install --no-interaction --quiet)

test:
	composer install --no-interaction
	composer test

# Run unit tests inside Docker when PHP is not installed locally.
test-docker:
	docker run --rm -v "$(CURDIR)":/app -w /app composer:2 sh -c "composer install --no-interaction && composer test"

# Start the local Lake server stack (minio + meta + query).
integration-up:
	mkdir -p tests/docker/data/lake
	$(COMPOSE) up --quiet-pull -d --wait

# Bring the stack up, run the integration test group against it from a PHP
# container attached to the same Docker network, then tear everything down.
integration: integration-up
	docker run --rm --network lake-php-it_default \
		-v "$(CURDIR)":/app -w /app \
		-e LAKE_DSN="$(IT_DSN)" \
		composer:2 sh -c '$(IT_COMPOSER) && ./vendor/bin/phpunit --group integration'; \
	status=$$?; \
	$(COMPOSE) down -v; \
	exit $$status

integration-down:
	$(COMPOSE) down -v

fmt-check:
	find src tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /dev/null && echo "lint ok"
