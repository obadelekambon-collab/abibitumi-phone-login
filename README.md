# Abibitumi Phone Login

WordPress plugin providing OTP-based phone authentication for BuddyBoss/BuddyPress platforms.

## Current Versions
- Phone Login: v1.1.3
- Abibitumi Chat companion plugin: v1.1.1

## Phone Login Features
- OTP phone login for BuddyBoss/BuddyPress
- SMS providers: Twilio, Africa's Talking, Vonage
- Custom DB table for OTP storage
- bcrypt OTP hashing
- Layered rate limiting
- REST API endpoints for mobile

## Phone Login Version History
- v1.0.0 - Initial release
- v1.1.0 - Added Africa's Talking provider
- v1.1.1 - bcrypt hashing audit
- v1.1.2 - Rate limiting layer
- v1.1.3 - REST API endpoints

## Installation
1. Upload plugin folder to `/wp-content/plugins/`.
2. Activate via WordPress admin.
3. Configure SMS provider credentials in Settings -> Phone Login.

## Branches
- `main` - stable/released
- `dev` - active development
- `release/*` - release candidates

---

## Companion Plugin: Abibitumi Chat (`/abibitumi-chat`)

A self-hosted **Tidio replacement** for the platform: live chat, chatbots,
ticketing, visitor tracking, canned responses, analytics, Tidio CSV migration,
and an installable **PWA** for agents. Web-first, then PWA. No third-party SaaS.

Built to be white-labelled across abibitumi.com, repatriatetoghana.com, and
decadeofourrepatriation.com. Branding, colours, copy, bot flows, departments,
and PWA names are per-site settings and can be applied from bundled presets.

The plugin includes:
- REST polling with optional Server-Sent Events fallback behavior
- optional Gemini chatbot backend with rule-engine fallback
- Web Push support through Composer production dependencies
- WordPress privacy export/erasure and retention cleanup
- Tidio contacts/transcript CSV importer
- standalone logic tests, browser smoke tests, WordPress integration tests, and CI packaging

See [`abibitumi-chat/README.md`](abibitumi-chat/README.md) for features,
architecture, installation, deployment, migration notes, and extension points.
