# CSRF Module

## Description

This module provides functionality to generate and validate CSRF tokens. It ensures protection against CSRF attacks by verifying that requests originate from trusted sources.

**Current Version**: 3.0.0 - see [CHANGELOG.md](CHANGELOG.md) for release history.

## Features

- Token generation and validation.
- Custom expiration time for tokens.
- Single-use tokens: a token is accepted once and never again.
- Optional storage of token status (valid, used, expired).
- Index creation and removal for the `status`, `timestamp` and `user_id` columns, and for the combination of `status` and `timestamp`.
- Cleanup of expired tokens, and of a user's tokens during logout.
- CSRF protection for anonymous (unauthenticated) users, stored entirely in the session - no database required.

## Choose your installation

The module comes in two setups. Both protect any form, whether the visitor is logged in or not, and they differ only in where the tokens are kept (database or session).

**Installation with a database** - tokens are stored in a MySQL table, one row per token, and the module can list them, mark them as used or expired, limit how many a user may hold at once, and clean them up from a scheduled job. This setup includes the session-only protection as well.

*Installation guide:* [INSTALLATION_DATABASE.md](INSTALLATION_DATABASE.md)

**Installation without a database** - tokens are kept in the session only. No database, no `user_id`, nothing written to disk, and only five files of the module are used. Suitable for applications that need CSRF protection without a database behind it.

*Installation guide:* [INSTALLATION_ANONYMOUS.md](INSTALLATION_ANONYMOUS.md)

Both guides are self-contained: each one covers installation, configuration and usage for its setup.

## Requirements

- PHP 8.2 or newer
- An active PHP session
- MySQL and the `pdo_mysql` extension, for the installation with a database

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
