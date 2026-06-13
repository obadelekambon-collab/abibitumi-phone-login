# Abibitumi Phone Login

WordPress plugin providing OTP-based phone authentication for BuddyBoss/BuddyPress platforms.

## Current Version
v1.1.3

## Features
- OTP phone login for BuddyBoss/BuddyPress
- SMS providers: Twilio, Africa's Talking, Vonage
- Custom DB table for OTP storage
- bcrypt OTP hashing
- Layered rate limiting
- REST API endpoints for mobile

## Version History
- v1.0.0 — Initial release
- v1.1.0 — Added Africa's Talking provider
- v1.1.1 — bcrypt hashing audit
- v1.1.2 — Rate limiting layer
- v1.1.3 — REST API endpoints

## Installation
1. Upload plugin folder to `/wp-content/plugins/`
2. Activate via WordPress admin
3. Configure SMS provider credentials in Settings → Phone Login

## Branches
- `main` — stable/released
- `dev` — active development
- `release/*` — release candidates
