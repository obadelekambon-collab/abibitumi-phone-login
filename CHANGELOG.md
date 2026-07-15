# Changelog

## Abibitumi Chat

### [1.1.1] - 2026-07-11
- Added compatibility handling for legacy MySQL `utf8mb3` option tables.
- Preserves normal Unicode on modern `utf8mb4` sites while omitting unsupported four-byte characters on legacy tables.
- Ensures activation and preset application can create the settings option.

### [1.1.0] - 2026-07-11
- Added Tidio contacts and transcript CSV migration tooling.
- Added live visitor page journey context for operators and chatbot recommendations.
- Added privacy export/erasure and retention integration for journey data.

### [1.0.0] - 2026-07-11
- Added self-hosted Tidio replacement plugin under `abibitumi-chat/`.
- Includes live chat widget, operator inbox, chatbot flows, file sharing, visitor tracking, analytics, PWA support, Web Push, site presets, and CI packaging.

## Abibitumi Phone Login

### [1.1.3]
- REST API endpoints for mobile.

### [1.1.2]
- Layered rate limiting.

### [1.1.1]
- bcrypt OTP hashing audit.

### [1.1.0]
- Africa's Talking provider added.

### [1.0.0]
- Initial release.
