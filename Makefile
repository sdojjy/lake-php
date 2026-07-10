test:
	composer install --no-interaction
	composer test

# Run tests inside Docker when PHP is not installed locally.
test-docker:
	docker run --rm -v "$(CURDIR)":/app -w /app composer:2 sh -c "composer install --no-interaction && composer test"

fmt-check:
	find src tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /dev/null && echo "lint ok"
