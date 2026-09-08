# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [2.1.0]

### Added

- Session-only CSRF protection for anonymous (unauthenticated) users via the new `CSRFAnonymous` class - independent, form-scoped tokens with one-time use and their own expiration and per-session limit.
- `ANONYMOUS_TOKEN_EXPIRATION_TIME` and `ANONYMOUS_TOKENS_LIMIT` configuration options.

## [2.0.0]

### Added

- Automatic per-user token limits, deleting the oldest excess tokens when a new one is generated.
- Optional indexing on the `user_id` column.
- A configurable persistent database connection.

### Changed

- Replaced global configuration constants with an injectable `Config` class; `Database`, `Logger`, and other dependencies can now be injected for testing.
- Split the monolithic `CSRF` class into `TokenGenerator`, `TokenValidator`, `TokenRepository`, and `TokenCleaner`.
- Standardized error handling: methods now throw specific exceptions for unexpected failures and return `bool` only for expected outcomes.

### Security

- Fixed critical privilege-escalation and SQL injection vulnerabilities in admin checks and token deletion/status updates.
- Tokens are now marked `used` after successful validation to prevent replay.
- Token comparisons now use timing-safe `hash_equals()`.
- Restricted self-service token deletion to the caller's own token/user ID (breaking change for direct `deleteToken()` callers).

## [1.0.0]

- Initial stable release.
