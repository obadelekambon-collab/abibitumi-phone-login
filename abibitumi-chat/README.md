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
| **Operator dashboard** | Three-pane inbox, open/pending/closed filters, search, assignment & transfer, internal notes, canned responses (`/shortcut`), visitor info panel |
| **File sharing** | Images + documents both directions, size/type limits |
| **Visitor tracking** | Online visitor list, current page, referrer, IP, device, registered-member detection |
| **Ratings** | Post-chat 1–5 star satisfaction rating + comment |
| **Analytics** | Conversations, resolved, messages, average rating, per-day chart |
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
2. Activate **Abibitumi Chat** in *Plugins*.
3. Go to **Chat → Settings** to set branding, colours, office hours, bot flows.
4. Assign the **Chat Operator** role (or the `abchat_agent` capability) to
   support staff. Administrators and editors get it automatically.
5. Agents work from **Chat → Inbox**. On mobile, "Add to Home Screen" installs
   the PWA.

## Roles & capabilities
- `abchat_agent` — operate the inbox (auto-granted to admin + editor).
- `abchat_manage` — edit settings (auto-granted to admin).
- **Chat Operator** role — a light role for support staff.

## Extending
- `abchat_bot_response` (filter) — return a string or
  `{ reply, quickReplies, handoff }` to swap the rule engine for an LLM.
- `abchat_should_load_widget` (filter) — control where the widget appears.
- `abchat_dispatch_push` (action) — attach a VAPID signing library
  (e.g. `minishlink/web-push`) to deliver push to closed browsers. A P-256
  VAPID key pair is generated automatically when OpenSSL is available.
- Actions: `abchat_conversation_started`, `abchat_visitor_message`,
  `abchat_operator_message`, `abchat_bot_handoff`, `abchat_conversation_closed`.

## Multi-site / white-label
Every visible string, colour, avatar, bot flow and PWA name is a setting stored
under a single option key, so the same code runs unchanged on each site with its
own branding. Nothing is hard-coded to abibitumi.com.

## Verification
`php test-logic.php` (see the scratchpad harness) exercises the settings
defaults, office-hours window, chatbot keyword matching / hand-off, and VAPID
key generation with stubbed WordPress functions — 16 assertions, all passing.
Every PHP file passes `php -l`; every JS file passes `node --check`.
