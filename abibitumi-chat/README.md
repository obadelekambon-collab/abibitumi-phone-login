# Abibitumi Chat

A self-hosted **Tidio replacement** for WordPress / BuddyBoss — live chat,
chatbots, ticketing, visitor tracking, canned responses, analytics, and an
installable **PWA** for agents. No third-party SaaS, no monthly fee, no data
leaving your server.

Built **web-first**, then **PWA**. Designed to be white-labelled and dropped
onto multiple sites (abibitumi.com, repatriatetoghana.com,
decadeofourrepatriation.com) — all branding, colours and copy are per-site
settings.

## Feature parity with Tidio

| Area | Included |
| --- | --- |
| **Live chat widget** | Launcher bubble, chat window, proactive greeting, unread badge, sound, mobile-responsive |
| **Real-time messaging** | REST polling (works on any shared host — no socket server needed), typing indicators, read receipts |
| **Pre-chat form** | Ask name / email / phone, department routing |
| **Chatbot** | Keyword flows, quick-reply buttons, auto-greeting, lead capture, human hand-off, office-hours away messages |
| **Operator dashboard** | Three-pane inbox, open/pending/closed filters, search, assignment & transfer, internal notes, canned responses (`/shortcut`), visitor info panel, conversation CSV export |
| **File sharing** | Images + documents both directions, size/type limits |
| **Visitor tracking** | Online visitor list, current page, referrer, IP, device, registered-member detection |
| **Ratings** | Post-chat 1–5 star satisfaction rating + comment |
| **Analytics** | Conversations, resolved, messages, average rating, per-day chart |
| **Privacy** | WordPress personal-data export and erasure for visitor profiles and transcripts |
| **Data retention** | Optional daily deletion of expired closed chats, orphan visitors, and local attachments |
| **Abuse protection** | Configurable per-IP limits for new visitor sessions and chatbot requests |
| **Notifications** | Agent email on new chat / offline lead / hand-off, visitor transcript email on close, browser notifications, Web Push |
| **PWA** | Web app manifest + service worker, installable agent app, offline shell, push notifications |
| **Departments** | Multiple queues with routing |
| **Office hours** | Weekly schedule, online/away status, away auto-reply |

## Architecture

```
abibitumi-chat/
├── abibitumi-chat.php              Bootstrap, constants, activation hooks
├── includes/
│   ├── class-abchat-settings.php   Central per-site config (one option key)
│   ├── class-abchat-db.php         Data layer ($wpdb) — 5 custom tables
│   ├── class-abchat-activator.php  Schema (dbDelta), roles, capabilities, seeds
│   ├── class-abchat-rest.php       REST API (visitor + agent endpoints)
│   ├── class-abchat-chatbot.php    Rule-based bot engine (pluggable via filter)
│   ├── class-abchat-notifications.php  Email + Web Push dispatch
│   ├── class-abchat-widget.php     Front-end widget injection
│   ├── class-abchat-pwa.php        Manifest + service worker serving
│   ├── class-abchat-admin.php      Dashboard, settings, menu
│   └── class-abchat-plugin.php     Orchestrator
├── assets/
│   ├── css/{widget,admin}.css
│   ├── js/{widget,admin,sw}.js
│   └── img/{icon-192,icon-512}.png
└── templates/{dashboard,settings,analytics}.php
```

### Data model (tables, `{prefix}abchat_`)
- `visitors` — token, contact details, presence, page, referrer, device, member link
- `conversations` — visitor, operator, department, status, rating, timestamps
- `messages` — sender type (visitor/operator/bot/system), body, attachments, read receipt
- `canned` — global + per-operator quick replies
- `push` — Web Push subscriptions per operator

### Real-time
Visitor widget and agent dashboard **poll** the WP REST API (`abchat/v1`).
Intervals are configurable (default 4s visitor / 3s agent). Typing indicators
and presence ride on transients, so there is nothing extra to run — it works on
standard WordPress hosting. Web Push (via the service worker) covers alerts when
the dashboard is closed.

## Installation
1. Copy the `abibitumi-chat` folder into `wp-content/plugins/`.
2. Run `composer install --no-dev --optimize-autoloader` inside the plugin
   directory to enable closed-browser Web Push delivery.
3. Activate **Abibitumi Chat** in *Plugins*.
4. Go to **Chat → Settings** to set branding, colours, office hours, bot flows.
5. Assign the **Chat Operator** role (or the `abchat_agent` capability) to
   support staff. Administrators and editors get it automatically.
6. Agents work from **Chat → Inbox**. On mobile, "Add to Home Screen" installs
   the PWA.

The PHP 7.4-compatible Web Push v7 dependency line is locked for reproducible
installs. Composer currently reports legacy abandoned JWT subpackages on that
line but no known security advisories. Upgrade to the maintained Web Push
release line when the plugin's minimum PHP version can be raised.

GitHub Actions also produces an **abibitumi-chat** artifact containing an
installable plugin ZIP with production dependencies included.

The CI matrix loads the plugin against a real WordPress 6.9.4 installation and
MySQL database on both PHP 7.4 and 8.3. These integration tests verify schema
activation, REST registration, chat persistence, privacy hooks, retention cron,
and front-end secret isolation in addition to the standalone logic suite.

## Roles & capabilities
- `abchat_agent` — operate the inbox (auto-granted to admin + editor).
- `abchat_manage` — edit settings (auto-granted to admin).
- **Chat Operator** role — a light role for support staff.

## Live update transport

REST polling works on every supported host and remains the default. Sites whose
PHP/web-server stack supports long-lived responses can enable **Server-Sent
Events** under **Chat → Settings → Live update transport**. Visitor streams use
the existing visitor token; operator streams require the signed-in operator
cookie and REST nonce. Connections are deliberately short and reconnect before
common proxy timeouts. If a stream is rejected, buffered, or interrupted, the
visitor widget and operator dashboard automatically resume REST polling.

## Data retention

Automatic retention is disabled by default. Administrators can enable it under
**Chat → Settings → Data retention**, choose the age of closed conversations
and a bounded batch size, and run the policy manually. A daily WP-Cron event
removes only expired **closed** conversations, their messages, orphan visitor
profiles, and attachments that resolve inside the site's uploads directory.
Open and pending conversations are never removed. The settings screen records
the most recent cleanup counts for operational visibility.

## Chatbot AI backend (optional, free tiers work)

The bot is rule-based out of the box (no API key, no cost). An optional Google
Gemini backend is built in for free-form questions. In **Chat → Settings →
Chatbot**, enable Gemini, keep the default `gemini-2.5-flash` model (or enter
another model), and provide a Google AI Studio API key.

For stronger isolation, put the key in `wp-config.php` as
`define( 'ABCHAT_GEMINI_API_KEY', '…' );` or set the
`ABCHAT_GEMINI_API_KEY` environment variable. Either takes precedence over the
admin field. Saved keys are never rendered back into the settings page or
included in settings exports.

Gemini runs behind the existing `abchat_bot_response` filter. Network errors,
rate limits, invalid responses, and empty answers fall back gracefully to the
rule engine. A response containing only `HANDOFF` escalates to a human using
the configured fallback message. Other providers can still replace the result
through the same filter at a different priority.

The visitor-facing bot endpoint is rate-limited by both visitor and IP. The
visitor request count and window are configurable under **Chat → Settings →
Chatbot**; the IP ceiling is three times the visitor ceiling so ordinary shared
networks have headroom while repeated new-session abuse is still contained.
Exceeded requests receive HTTP `429` with retry timing.

Abuse-control IP buckets use the direct socket peer address and do not trust
client-supplied forwarding headers. Sites behind a known reverse proxy can
provide a validated address through the `abchat_client_ip` filter after first
confirming that `REMOTE_ADDR` belongs to that trusted proxy.

## Extending
- `abchat_bot_response` (filter) — return a string or
  `{ reply, quickReplies, handoff }` to swap the rule engine for an LLM.
- `abchat_should_load_widget` (filter) — control where the widget appears.
- `abchat_dispatch_push` (action) — receives push subscriptions and payloads.
  The Composer integration delivers them through `minishlink/web-push`;
  custom delivery can attach to the same action. Expired subscriptions are
  removed automatically.
- Actions: `abchat_conversation_started`, `abchat_visitor_message`,
  `abchat_operator_message`, `abchat_bot_handoff`, `abchat_conversation_closed`.

## Multi-site / white-label
Every visible string, colour, avatar, bot flow and PWA name is a setting stored
under a single option key, so the same code runs unchanged on each site with its
own branding. Nothing is hard-coded to abibitumi.com.

### Bundled site presets
Ready-made configurations ship in `presets/` for each property:

| Preset slug | Site |
| --- | --- |
| `abibitumi` | abibitumi.com |
| `repatriatetoghana` | repatriatetoghana.com |
| `decadeofourrepatriation` | decadeofourrepatriation.com |

Each preset sets that site's brand name, welcome copy, colours, PWA name, bot
name/greeting, departments and a tailored set of chatbot flows (e.g.
repatriatetoghana has visa / Right of Abode / housing / shipping flows; the
Decade site has movement / events / get-involved / media flows).

### One-click deploy to each site
Install the identical plugin on all three WordPress sites, then either:

- **Admin:** *Chat → Settings → Site presets & portability* → pick the site →
  **Apply preset**. Or **Export** settings from one site and **Import** on
  another.
- **WP-CLI:**
  ```bash
  wp abchat list-presets
  wp abchat apply-preset repatriatetoghana
  wp abchat export --file=chat-settings.json   # move config between sites
  wp abchat import chat-settings.json
  ```

Import is schema-whitelisted (only known settings keys are accepted) and
underscore-prefixed meta keys are stripped, so config files stay safe to pass
between installs.

## Verification
`php test-logic.php` (see the scratchpad harness) exercises the settings
defaults, office-hours window, chatbot keyword matching / hand-off, and VAPID
key generation with stubbed WordPress functions — 16 assertions, all passing.
Every PHP file passes `php -l`; every JS file passes `node --check`.
