# AGENTS.md

Guidance for AI coding agents (OpenAI Codex, GitHub Copilot, Gemini/Jules,
Claude Code, Cursor, Aider, Cline, …) working in this repository.

## Repository layout

- `abibitumi-chat/` — **Abibitumi Chat**, a self-hosted Tidio replacement
  (live chat, chatbots, ticketing, PWA) as a WordPress/BuddyBoss plugin.
  This is where active work happens.
  - `abibitumi-chat.php` — plugin bootstrap.
  - `includes/` — PHP classes, one responsibility each (`class-abchat-*.php`).
  - `assets/{css,js,img}/` — front-end widget + operator dashboard + service worker.
  - `templates/` — admin screens (dashboard, settings, analytics).
  - `presets/` — per-site JSON branding/bot configs (abibitumi,
    repatriatetoghana, decadeofourrepatriation).
  - `tests/test-logic.php` — standalone logic tests (no WordPress required).
- Root `README.md`, `CHANGELOG.md` — the original phone-login plugin.

## How to run checks

No build step. PHP 7.4+ and Node are the only tools needed.

```bash
# Lint every PHP file (must print nothing / exit 0)
find abibitumi-chat -name '*.php' -print0 | xargs -0 -n1 php -l

# Syntax-check the JavaScript
for f in abibitumi-chat/assets/js/*.js; do node --check "$f"; done

# Run the logic test suite (must end with "0 failed")
php abibitumi-chat/tests/test-logic.php
```

All three must pass before committing. The test harness stubs the minimal
WordPress functions, so it runs anywhere PHP is installed — no database, no
web server.

The same checks run in GitHub Actions on PHP 7.4 and 8.3 plus Node 20 via
`.github/workflows/abibitumi-chat-ci.yml`.

## Conventions

- **PHP:** WordPress coding standards — tabs for indentation, `snake_case`
  functions, `ABChat_` class prefix, `abchat_` hook/option prefix. Escape all
  output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input, and use
  `$wpdb->prepare()` for every query. Text domain: `abibitumi-chat`.
- **JavaScript:** vanilla ES5-compatible (no framework, no build). Keep the
  widget dependency-free.
- **Data:** custom tables via `ABChat_DB` (prefix `{wp_prefix}abchat_`). Never
  write raw SQL outside that class.
- **Settings:** all configuration lives under one option key via
  `ABChat_Settings`. Add new settings to `ABChat_Settings::defaults()` so
  import/export and presets keep working.
- **Extensibility:** prefer new `do_action` / `apply_filters` hooks over
  editing call sites. The chatbot is already swappable via the
  `abchat_bot_response` filter.

## Good first tasks / roadmap

- Optional AI backend for the chatbot behind `abchat_bot_response` (see
  "Chatbot AI backend" in `abibitumi-chat/README.md`).
- WebSocket/SSE transport as an alternative to REST polling.
- Keep the `minishlink/web-push` adapter and its PHP 7.4-compatible lockfile
  current; move to the maintained release line when the minimum PHP version
  can be raised.
- Automated PHPUnit + WP test-suite harness alongside `tests/test-logic.php`.
- Knowledge-base article suggestions for recurring questions.
- Expand automated coverage with the WordPress integration test suite.

## Guardrails

- Keep every change lint-clean and the test suite green.
- Don't hard-code any single site's branding — it belongs in `presets/` or
  settings.
- Don't add heavy dependencies to the front-end widget.
