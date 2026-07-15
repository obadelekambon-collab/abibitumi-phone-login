# AGENTS.md

Guidance for AI coding agents (OpenAI Codex, GitHub Copilot, Gemini/Jules,
Claude Code, Cursor, Aider, Cline, ...) working in this repository.

## Repository layout

- `abibitumi-chat/` - **Abibitumi Chat**, a self-hosted Tidio replacement
  (live chat, chatbots, ticketing, PWA) as a WordPress/BuddyBoss plugin.
  This is where active work happens.
  - `abibitumi-chat.php` - plugin bootstrap.
  - `includes/` - PHP classes, one responsibility each (`class-abchat-*.php`).
  - `assets/{css,js,img}/` - front-end widget + operator dashboard + service worker.
  - `templates/` - admin screens (dashboard, settings, analytics, Tidio migration).
  - `presets/` - per-site JSON branding/bot configs (abibitumi,
    repatriatetoghana, decadeofourrepatriation).
  - `tests/test-logic.php` - standalone logic tests (no WordPress required).
  - `tests/integration/` - database-backed WordPress PHPUnit coverage.
  - `tests/browser/` - Playwright smoke tests for widget/dashboard JavaScript.
- Root `README.md`, `CHANGELOG.md` - repository-level project notes and release history.

## How to run checks

No build step is required for the front-end assets. PHP 7.4+ and Node are the
main local tools.

```bash
# Lint every PHP file (must print nothing / exit 0)
find abibitumi-chat -name '*.php' -print0 | xargs -0 -n1 php -l

# Syntax-check the JavaScript
for f in abibitumi-chat/assets/js/*.js; do node --check "$f"; done

# Run the standalone logic test suite (must end with "0 failed")
php abibitumi-chat/tests/test-logic.php

# Run browser smoke tests
npm ci --prefix abibitumi-chat/tests/browser
npm test --prefix abibitumi-chat/tests/browser
```

Database-backed integration tests load the plugin in WordPress and exercise
schema activation, REST routes, chat persistence, Tidio import, privacy hooks,
retention, and security boundaries. Run them locally with `composer test:integration`
after installing Composer dependencies and providing the MySQL and `WP_CORE_DIR`
environment variables documented in `tests/integration/wp-tests-config.php`.

The same checks run in GitHub Actions through
`.github/workflows/abibitumi-chat-ci.yml`: PHP lint/Composer validation/audit,
logic tests on PHP 7.4 and 8.3, JavaScript syntax checks, Playwright smoke
tests, WordPress integration tests against the minimum supported WordPress 5.8
line and the current 6.9 line, and an installable plugin ZIP artifact.

## Conventions

- **PHP:** WordPress coding standards - tabs for indentation, `snake_case`
  functions, `ABChat_` class prefix, `abchat_` hook/option prefix. Escape all
  output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input, and use
  `$wpdb->prepare()` for every query. Text domain: `abibitumi-chat`.
- **JavaScript:** vanilla ES5-compatible (no framework, no build). Keep the
  widget dependency-free.
- **Data:** custom tables via `ABChat_DB` (prefix `{wp_prefix}abchat_`). Avoid
  raw SQL outside that class.
- **Settings:** all configuration lives under one option key via
  `ABChat_Settings`. Add new settings to `ABChat_Settings::defaults()` so
  import/export and presets keep working.
- **Extensibility:** prefer new `do_action` / `apply_filters` hooks over
  editing call sites. The chatbot is swappable via the `abchat_bot_response`
  filter.

## Current maintenance focus

- Keep the Composer Web Push dependency line current while PHP 7.4 remains the
  minimum supported version; move to the maintained release line when the PHP
  minimum can be raised.
- Expand integration and browser coverage as staging or production feedback
  reveals real workflows that need stronger regression protection.
- Keep release notes and deployment documentation current when chat plugin
  behavior changes.
- During Tidio migrations, remember that CSV exports do not include external
  channel connections, operators, chatbot/Flow definitions, or attachment
  binaries; those remain operational migration tasks.

## Guardrails

- Keep every change lint-clean and the test suite green.
- Do not hard-code any single site's branding; it belongs in `presets/` or
  settings.
- Do not add heavy dependencies to the front-end widget.
