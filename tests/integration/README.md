# Integration tests

## What this suite covers

End-to-end behavior of the queue lifecycle that single-component unit tests
cannot validate in isolation:

- `JobLifecycleTest` — happy path, polling re-queue, stuck-recovery,
  max-attempts kill, lock contention.
- `QueueWithoutTrafficTest` — regression for the bug where WP-Cron does not
  fire on sites without HTTP traffic; verifies the cron callback can be
  invoked directly to drain the queue.

## How to run

```bash
# Whole suite
composer test:integration

# Or with a specific PHP version
vendor/bin/phpunit --testsuite=integration
```

The tests in this directory are wired to the same global
`tests/bootstrap.php` as the unit suite, so they execute against the
WP_Mock fixtures and Mockery-mocked repositories. They are integration
tests in the "multi-class lifecycle" sense, not the "real WordPress+MySQL"
sense.

## Deferred: real WordPress + MySQL integration

A future expansion can swap the mock-backed assertions for a real
WordPress test install. The scaffolding is already in place:

- `bootstrap.php` here can load `wp-phpunit/wp-phpunit` (already in
  `require-dev`) and a real WP install when `WP_TESTS_DIR` points at a
  directory containing `wp-tests-config.php`.
- The `phpunit.xml.dist` declares a dedicated `integration` testsuite.

To wire that up, the following environment is required:

| Variable | Purpose |
|---|---|
| `WP_TESTS_DIR` | Path to a WordPress test install (downloaded by `install-wp-tests.sh` or extracted from `wp-phpunit`) |
| `WP_TESTS_DB_NAME` | Database name (test scratch DB — will be wiped each run) |
| `WP_TESTS_DB_USER` | MySQL user |
| `WP_TESTS_DB_PASSWORD` | MySQL password |
| `WP_TESTS_DB_HOST` | MySQL host (e.g. `127.0.0.1:3306`, or socket path) |

Local Mac (Local by Flywheel) socket example:

```bash
export WP_TESTS_DB_HOST="localhost:/Users/braianflorian/Library/Application Support/Local/run/<id>/mysql/mysqld.sock"
export WP_TESTS_DB_NAME=local_test
export WP_TESTS_DB_USER=root
export WP_TESTS_DB_PASSWORD=root
```

CI service container example (GitHub Actions):

```yaml
services:
  mysql:
    image: mysql:8.0
    env:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wp_test
    ports: ["3306:3306"]
    options: >-
      --health-cmd="mysqladmin ping --silent"
      --health-interval=10s --health-timeout=5s --health-retries=5
```

Until that environment is wired, the suite runs as pseudo-integration with
mocks. The recovery thresholds in
`includes/services/jobs/recovery/JobRecoveryService.php` are configurable
via the `contai_recovery_reset_threshold_minutes` and
`contai_recovery_fail_threshold_minutes` filters — useful for both modes.
