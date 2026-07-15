# Abibitumi Chat Verification

Use this checklist before releasing or deploying changes to `abibitumi-chat/`.

## Local Checks

```bash
find abibitumi-chat -name '*.php' -print0 | xargs -0 -n1 php -l
for f in abibitumi-chat/assets/js/*.js; do node --check "$f"; done
php abibitumi-chat/tests/test-logic.php
npm ci --prefix abibitumi-chat/tests/browser
npm test --prefix abibitumi-chat/tests/browser
```

`tests/test-logic.php` covers standalone logic paths with minimal WordPress
stubs, including settings, presets, chatbot behavior, Gemini fallback handling,
rate limits, retention, page journey normalization, Tidio import helpers, and
legacy `utf8mb3` option-table compatibility.

## WordPress Integration Checks

Database-backed integration tests live in `tests/integration/` and load the
plugin in WordPress with a real MySQL database. They cover activation, custom
tables, REST routes, chat persistence, Tidio import, privacy hooks, retention,
rate limits, upload boundaries, and front-end secret isolation.

Run them locally after installing Composer dependencies and setting the database
and WordPress core environment variables documented in
`tests/integration/wp-tests-config.php`:

```bash
composer install --working-dir=abibitumi-chat
composer test:integration --working-dir=abibitumi-chat
```

## CI and Release Artifact

GitHub Actions runs PHP lint, Composer validation/audit, standalone logic tests,
JavaScript syntax checks, Playwright browser smoke tests, WordPress integration
tests against the minimum supported WordPress 5.8 line and the current 6.9 line,
and then builds an installable `abibitumi-chat` ZIP artifact with production
Composer dependencies included.

Use the CI artifact for deployments when you need closed-browser Web Push
support without running Composer on the destination server.
