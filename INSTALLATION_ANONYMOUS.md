# Installation without a Database

This guide covers the lightweight setup of the CSRF module, where tokens are kept in the session only. It protects forms that unauthenticated users can reach, for example login, registration or public contact forms. It works the same way for the forms of logged-in users, because it reads neither the user ID nor the role from the session. No database is used, and nothing is written to disk, so there are no log files either.

The difference between the two setups is where the tokens are kept. This one keeps them in the session, so they disappear with it and the application cannot inspect them. The full setup, described in [INSTALLATION_DATABASE.md](INSTALLATION_DATABASE.md), stores them in a database table, where they can be listed, expired, counted per user and cleaned up by a scheduled job.

## Requirements

- PHP 8.2 or newer
- An active PHP session

## Installation steps

### 1. Install the module

You can install the module in two ways.

**Install via Composer (recommended).** Run the following command in your project:

```bash
composer require aleksandarz/csrfmodule
```

Then include Composer's autoloader in your script:

```php
require 'vendor/autoload.php';
```

**Manual installation (download from GitHub).** Download the module, place it in your project's modules directory and include the module's own autoloader:

```php
require_once 'autoload.php';
```

**Note**: add the autoloader line near the top of your application's entry script (for example `index.php` or a bootstrap file), before any use of the module's classes.

**Mandatory for both installations**: import the class you use. All classes live under the `CSRFModule` namespace:

```php
use CSRFModule\CSRFAnonymous;
```

This setup needs these files only:

```text
autoload.php
config/csrf_config.php
src/Config.php
src/CSRFAnonymous.php
src/TokenGenerator.php
```

With a manual installation, everything outside this list can be deleted once [step 3](#3-rename-the-configuration-file) has renamed `config/csrf_config.example.php` to `config/csrf_config.php`. Keep `LICENSE`, because the MIT license asks for the license text and the copyright notice to stay with the copy of the module.

With a Composer installation, `vendor/autoload.php` takes the place of `autoload.php`, and nothing should be deleted, because `composer update` reinstalls the package in full and every removed file comes back.

### 2. Start a session

The module keeps its tokens in `$_SESSION`, so a session must be active before any module class is used:

```php
session_start();
```

### 3. Rename the configuration file

Rename `config/csrf_config.example.php` to `config/csrf_config.php`. The module reads its settings from that file.

**Note for Composer installations**: the configuration file lives inside the installed package, in `vendor/aleksandarz/csrfmodule/config/`, and the module reads it from there. `composer update` reinstalls the package and removes `csrf_config.php` along with your settings. Keep a copy of the file outside `vendor/` and restore it after every update.

The file also holds the database settings, which this setup never reads. Leave it as it is, or shorten it as described in the next step.

### 4. Shorten the configuration (optional)

The database constants are never read here, so this step only keeps the configuration short.

`config/csrf_config.php` and `src/Config.php` can be shortened, but only together and exactly as shown below. You can set the values in the configuration file as you wish.

`config/csrf_config.php`:

```php
<?php

/**
 * Set the expiration time for CSRF tokens for anonymous users.
 * Time is calculated in seconds.
 */
const ANONYMOUS_TOKEN_EXPIRATION_TIME = 600;

/**
 * Set the maximum number of concurrent anonymous tokens allowed per session.
 * Set to null to disable the limit (never delete old tokens automatically).
 */
const ANONYMOUS_TOKENS_LIMIT = 1;
```

`src/Config.php`:

```php
<?php

declare(strict_types=1);

namespace CSRFModule;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'csrf_config.php';

class Config
{
    public function __construct(
        public readonly int $anonymousTokenExpirationTime = ANONYMOUS_TOKEN_EXPIRATION_TIME,
        public readonly ?int $anonymousTokensLimit = ANONYMOUS_TOKENS_LIMIT
    ) {}
}
```

With a Composer installation both files are inside `vendor/`, so `composer update` brings back the originals and the shortened versions have to be copied over again, together.

## Usage

### Generating a token for a form

One session can hold several independent tokens, one per form, each identified by a `$csrfFormId` generated inside the module:

```php
$csrfAnonymous = new CSRFAnonymous();
$csrfAnonymous->generateCsrfFormId();
$csrfAnonymous->generateToken();
$csrfAnonymous->setSession();
```

**Note**: the three methods must be called in this exact order - `setSession()` reads the values that `generateCsrfFormId()` and `generateToken()` store on the object.

The tokens are kept under `$_SESSION['csrf_anonymous_tokens']`, keyed by the form identifier. No other part of the session is touched.

Include both values as hidden fields in your form:

```php
<input type="hidden" name="csrf_form_id" value="<?= htmlspecialchars($csrfAnonymous->csrfFormId) ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfAnonymous->csrfToken) ?>">
```

### Validating the submitted token

Both values arrive in `$_POST`, under the names of the hidden fields:

```php
$csrfAnonymous = new CSRFAnonymous();

$csrfFormId    = $_POST['csrf_form_id'] ?? '';
$tokenFromForm = $_POST['csrf_token'] ?? '';

if (!$csrfAnonymous->tokenValidation($csrfFormId, $tokenFromForm)) {
    // Handle invalid, expired, or already-used token
}
```

The method returns `false` when the form identifier is unknown to the session, when the token has expired, and when the two tokens do not match. The comparison is done with `hash_equals()`.

The token is single use: it is removed from the session immediately after a successful validation, so the same token cannot be submitted twice. An expired token is removed as well.

### Clearing the tokens after login

Call `destroyAllTokens()` inside your application's login method, right after a successful login, to clear all remaining anonymous tokens from the session. Forms left open in other tabs will need a fresh token after this:

```php
$csrfAnonymous = new CSRFAnonymous();
$csrfAnonymous->destroyAllTokens();
```

## Configuration reference

All settings live in `config/csrf_config.php`.

**Token lifetime**, in seconds. A token is rejected once this many seconds have passed since it was created:

```php
const ANONYMOUS_TOKEN_EXPIRATION_TIME = 600;
```

**Multiple tokens per session**. When a new token is generated and the session is already at the limit, the oldest tokens are deleted to make room. Set it to `null` to disable the limit, in which case old tokens are never deleted automatically:

```php
const ANONYMOUS_TOKENS_LIMIT = 1; // or null to disable the limit
```

With the limit at `1`, a visitor who opens a second protected form invalidates the token of the first one. Raise it if your pages show more than one protected form at a time, or if visitors work in several tabs.

**Dependency injection through the `Config` class**. `CSRFAnonymous` accepts an optional `Config` object in its constructor. When none is passed, it creates its own, reading the values from the constants above, so plain usage such as `new CSRFAnonymous()` needs no changes.

Pass your own `Config` when you need different settings for one instance, for example a longer lifetime for a single form:

```php
use CSRFModule\Config;
use CSRFModule\CSRFAnonymous;

$config = new Config(anonymousTokenExpirationTime: 1800);
$csrfAnonymous = new CSRFAnonymous($config);
```

## Handling uncaught exceptions

This setup validates without throwing: `tokenValidation()` reports a bad token with `false`, and your application decides what to do next. Token generation relies on `random_bytes()`, which throws a `\Random\RandomException` when the system has no source of randomness.

Your application should register a global exception handler with [`set_exception_handler()`](https://www.php.net/manual/en/function.set-exception-handler.php). The handler should show the user a generic error message and log the details, never display them, especially in production. Keep `display_errors` disabled in production, so PHP itself does not leak error details either.

This has to be set up by your application, not by the module. `set_exception_handler()` installs a single handler for the whole process, so the module must not register its own and override yours.
