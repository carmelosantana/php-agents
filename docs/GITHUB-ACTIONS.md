# GitHub Actions CI

php-agents uses GitHub Actions for continuous integration. The workflow runs on every pull request targeting `main` and on direct pushes to `main`, ensuring tests and static analysis pass before code is merged.

## Workflow Overview

The CI workflow (`.github/workflows/ci.yml`) runs two parallel jobs across a PHP version matrix:

| Job | Command | Purpose |
|-----|---------|---------|
| **Tests** | `composer test` | Runs the Pest 3.x test suite |
| **PHPStan** | `composer analyse` | Static analysis at level 8 |

### PHP Version Matrix

| Version | Status |
|---------|--------|
| 8.4 | Required (minimum) |
| 8.5 | Future-proofing |

Both jobs use `fail-fast: false` so all matrix combinations report independently — a failure on 8.5 won't cancel the 8.4 run.

### Extensions

The workflow installs: `mbstring`, `curl`, `xml`. These cover the requirements for `symfony/http-client` and the test suite.

### Caching

Composer dependencies are cached by PHP version using the `actions/cache` action. The cache key hashes `composer.json` (not a lockfile — php-agents is a library and does not commit `composer.lock`).

## Branch Protection

After the workflow is merged, configure branch protection on GitHub:

1. Go to **Settings → Branches → Add rule**
2. Branch name pattern: `main`
3. Enable **Require status checks to pass before merging**
4. Select these required checks:
   - `Tests (PHP 8.4)`
   - `Tests (PHP 8.5)`
   - `PHPStan (PHP 8.4)`
   - `PHPStan (PHP 8.5)`
5. Optionally enable **Require branches to be up to date before merging**

## Testing Locally

Before pushing, you can run the same checks the CI pipeline executes. This catches failures early and avoids waiting for GitHub runners.

### Prerequisites

Both macOS and Linux need:

- **PHP 8.4+** with extensions: `mbstring`, `curl`, `xml`
- **Composer 2.x**

### macOS

Install PHP and Composer via Homebrew:

```bash
brew install php@8.4 composer
```

Verify the installation:

```bash
php -v            # Should show 8.4.x
composer --version
```

Run the checks:

```bash
composer install
composer test
composer analyse
```

### Linux (Ubuntu)

Install PHP 8.4 from the `ondrej/php` PPA:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php8.4-cli php8.4-mbstring php8.4-curl php8.4-xml
```

Install Composer:

```bash
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Run the checks:

```bash
composer install
composer test
composer analyse
```

### Using the Test Script

A convenience script is provided that mirrors the CI pipeline locally:

```bash
./scripts/ci-test.sh
```

The script runs both `composer test` and `composer analyse` in sequence, exiting on the first failure. Pass `--install` to also run `composer install` before testing:

```bash
./scripts/ci-test.sh --install
```

See [scripts/ci-test.sh](../scripts/ci-test.sh) for details.

## Workflow File Reference

The full workflow lives at `.github/workflows/ci.yml`. Key design decisions:

- **Two parallel jobs** (tests + static analysis) rather than sequential steps — faster feedback and independent failure reporting
- **No `composer.lock`** — as a library, dependencies resolve fresh from `composer.json`, catching compatibility issues early
- **`shivammathur/setup-php@v2`** — reliable PHP provisioning with extension management
- **`actions/cache@v4`** — caches Composer's download directory across runs

## Troubleshooting

### Tests pass locally but fail in CI

- Check the PHP version. CI runs 8.4 and 8.5 — you may be running a different patch version locally.
- Run `php -m` to verify extensions match: `mbstring`, `curl`, `xml`.
- Clear Composer cache and reinstall: `composer clear-cache && rm -rf vendor && composer install`.

### PHPStan fails in CI but not locally

- Ensure you're running the same PHPStan version. Delete `vendor/` and reinstall.
- Check `phpstan.neon` — the CI uses the same config file without any overrides.
- PHPStan caches results in `.phpstan.cache` — delete it locally and rerun.

### Cache issues

If dependencies seem stale, the CI cache can be cleared by pushing a change to `composer.json` (which changes the cache key hash). Alternatively, delete caches manually from **Actions → Caches** in the GitHub UI.
