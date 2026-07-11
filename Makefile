test:
	composer install --no-interaction
	composer test

# Run tests inside Docker when PHP is not installed locally.
test-docker:
	docker run --rm -v "$(CURDIR)":/app -w /app composer:2 sh -c "composer install --no-interaction && composer test"

integration-docker:
	cd ../lakesql/tests && mkdir -p data/databend && docker compose up --scale query=3 --quiet-pull -d --wait
	curl -u root: -XPOST "http://localhost:8000/v1/query" -H 'Content-Type: application/json' -d '{"sql": "select version()", "pagination": {"wait_time_secs": 10}}'
	docker run --rm \
		--add-host=host.docker.internal:host-gateway \
		-v "$(CURDIR)":/app \
		-w /app \
		-e LAKE_DSN="http://root:@host.docker.internal:8000/default?login=disable&presigned_url_disabled=true" \
		composer:2 sh -c "composer install --no-interaction && ./vendor/bin/phpunit --group integration"; \
	status=$$?; \
	cd ../lakesql/tests && docker compose down; \
	exit $$status

fmt-check:
	find src tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /dev/null && echo "lint ok"
