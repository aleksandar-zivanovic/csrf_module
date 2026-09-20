# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [3.0.0]

### Security

- **Breaking:** `CSRF::tokenValidation()` now compares the token submitted with the form against the user's tokens in the database. Until now the submitted token was ignored, so any request with any token value passed validation. Every version before 3.0.0 is affected and must not be used.

### Added

- A PHPUnit test suite covering every class, with database tests in both `SAVE_CSRF_STATUS` modes.
- The `$initiator` parameter of `CSRF::allTokensCleanUp()`, which names the caller in the log when the cleanup runs outside a logged-in session, for example from a cron or CLI script.
- A `LICENSE` file with the MIT license text.

### Changed

- Every token is now single-use, also when `SAVE_CSRF_STATUS` is `false`.
- **Breaking:** `CSRF::allTokensCleanUp()` requires a user ID in the session or an `$initiator` value, and throws an `InvalidArgumentException` when it has neither.
- The documentation is split into three files: `README.md` describes the module and the choice of installation, while `INSTALLATION_DATABASE.md` and `INSTALLATION_ANONYMOUS.md` each cover one setup in full.

### Fixed

- Placeholders in `TokenRepository::fetchTokenWithData()` are now unique per condition, so two conditions on the same column no longer collide.
- The admin check in `TokenCleaner::cleanUpAll()` now compares strictly, so a non-string role value is no longer accepted as admin.
- `TokenCleaner::logoutCleanUp('delete')` returns `true` when the user has no tokens, as its docblock states.
- `TokenRepository::save()` omits the `status` column when `SAVE_CSRF_STATUS` is `false`.

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
