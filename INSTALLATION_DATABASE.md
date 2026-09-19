# Installation with a Database

This guide covers the full setup of the CSRF module, where tokens are stored in a MySQL database. It is self-contained: installation, configuration, usage and database maintenance.

## Requirements

- PHP 8.2 or newer
- MySQL, with an existing database and a user allowed to create tables
- The `pdo_mysql` extension, enabled in `php.ini`
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

**Manual installation (download from GitHub).** Download the module and place it in your project's modules directory, then include the module's own autoloader:

```php
require_once 'autoload.php';
```

**Note**: add the autoloader line near the top of your application's entry script (for example `index.php` or a bootstrap file), before any use of the module's classes.

**Mandatory for both installations**: import the classes you use. All classes live under the `CSRFModule` namespace:

```php
use CSRFModule\CSRF;                  // tokens for logged-in users
use CSRFModule\CSRFAnonymous;         // tokens for forms open to unauthenticated users
use CSRFModule\DatabaseSchemaManager; // database schema operations
```

### 2. Start a session

The module keeps data in `$_SESSION`, so a session must be active before any module class is used:

```php
session_start();
```

### 3. Rename the configuration file

Rename `config/csrf_config.example.php` to `config/csrf_config.php`. The module reads all its settings from that file.

**Note for Composer installations**: the configuration file lives inside the installed package, in `vendor/aleksandarz/csrfmodule/config/`, and the module reads it from there. `composer update` reinstalls the package and removes `csrf_config.php` along with your settings. Keep a copy of the file outside `vendor/` and restore it after every update.

### 4. Configure the database credentials

Open `config/csrf_config.php` and set the constants to match your existing database:

```php
const DB_USER = 'value';     // database user
const DB_PASS = 'value';     // database password
const DB_HOST = 'value';     // database host
const DB_NAME = 'value';     // database name
```

### 5. Set the user ID session key

Tokens are stored per user, so the module reads the current user's ID from the session. The session key name is set by the `USER_ID_SESSION_KEY` constant. By default it is set to `'user_id'`:

```php
const USER_ID_SESSION_KEY = 'user_id';
```

Make sure that:

- the constant matches the session key your application uses for the user ID,
- the value stored under that key is an integer of 1 or greater.

If the key is missing or the value is not a valid integer, the parts of the module that depend on the user ID will not work.

### 6. Set up the admin session

Some operations are restricted to administrators. Access is decided by comparing a session key with a required value, both defined in `config/csrf_config.php`:

```php
const ROLE_NAME  = 'role';  // Session key for user role, e.g. $_SESSION['role']
const ROLE_VALUE = 'admin'; // Required role value for admin access, e.g. $_SESSION['role'] = 'admin';
```

The module never writes the role into the session. It only reads the value your application already stores there, so these two constants are how the module is adapted to that value.

Make sure that:

- `ROLE_NAME` matches the session key your application uses for the user role,
- `ROLE_VALUE` matches the exact value your application stores for an administrator.

The comparison is strict, so the value must match by type and by case. If the key is missing or the value differs, the admin-only operations deny access.

**Example:**

With these settings:

```php
const ROLE_NAME  = 'user_role';
const ROLE_VALUE = 'administrative_user';
```

the session of an administrator would look like this:

```php
$_SESSION = [
    'user_id'   => 42,
    'user_role' => 'administrative_user',
];
```

### 7. Create the database table

`DatabaseSchemaManager` requires an admin session (see [step 6](#6-set-up-the-admin-session)), and its constructor throws a `LogicException` without one. The same applies to every method of this class, including the maintenance methods described later.

The table is created by the `createTable()` method of `DatabaseSchemaManager`, and its shape follows the configuration file.

The table is named `csrf_tokens` and holds one row per token:

- `id` - primary key, auto increment,
- `token` - the token value, unique,
- `timestamp` - the moment the token was created, as a Unix timestamp,
- `user_id` - the owner of the token,
- `status` - `valid`, `used` or `expired`, present only when `SAVE_CSRF_STATUS` is `true`.

If `SAVE_CSRF_STATUS` is `true`, the `status` column is included. If it is `false`, the column is omitted:

```php
const SAVE_CSRF_STATUS = true;
```

Indexes are created together with the table for every constant set to `true`:

```php
const INDEX_TIMESTAMP = false; // set true to enable indexing on timestamp column
const INDEX_STATUS    = false; // set true to enable indexing on status column
const INDEX_BOTH      = false; // set true to enable a combined index on status and timestamp columns
const INDEX_USER_ID   = false; // set true to enable indexing on user_id column
```

**Note**: `INDEX_BOTH` creates a single combined index on `status` and `timestamp`, in that order. Do not enable `INDEX_STATUS` together with `INDEX_BOTH`, because the combined index already covers lookups by `status` alone. `INDEX_TIMESTAMP` is not redundant next to `INDEX_BOTH`, since the combined index cannot serve queries that filter only by `timestamp`.

Create the table:

```php
$manager = new DatabaseSchemaManager();
$manager->createTable();
```

A CLI or one-off installation script has no session, so set the role yourself before instantiating:

```php
session_start();
$_SESSION[ROLE_NAME] = ROLE_VALUE;
```

Delete the table:

```php
$manager = new DatabaseSchemaManager();
$manager->deleteTable();
```

`createTable()` throws a `RuntimeException` if the table already exists, and `deleteTable()` throws one if it does not exist. The result of both methods is written to `logs/general.log`.

**Note**: deleting the table is irreversible and should only be done if you are sure it is no longer needed.

## Usage

### Generating and validating a token

**Generating a token for a form:** call `generateAndSaveCsrfToken()` when rendering the form. It creates a new token, saves it to the database and to the session, and stores it in the `csrfToken` property:

```php
$csrf = new CSRF();
$csrf->generateAndSaveCsrfToken();
```

Include the token as a hidden field in your form:

```php
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf->csrfToken) ?>">
```

**Validating the submitted token:** when the form is submitted, the token arrives in `$_POST['csrf_token']`, the name of the hidden field. Pass it to the `tokenValidation()` method. It checks that the submitted token exists in the database, belongs to the current user, has not expired, and has not already been used:

```php
$csrf = new CSRF();

if (!$csrf->tokenValidation($_POST['csrf_token'] ?? '')) {
    // Handle invalid or missing CSRF token
}
```

Each token can be used only once: after a successful validation, its status is changed to `used` when saving status is enabled (`SAVE_CSRF_STATUS === true`), or the token is deleted from the database when saving status is disabled.

If the token has expired: when saving status is enabled, its status is changed to `expired`; when it is disabled, the expired token is deleted from the database instead.

A user can have several forms open at once. The number of tokens kept per user is set by `TOKENS_PER_USER` - see [Configuration reference](#configuration-reference).

### Protecting forms for unauthenticated users

The `CSRF` class needs a user ID in the session, so it cannot protect forms that are reached before a user logs in, for example login or registration forms. Those forms use the `CSRFAnonymous` class, which works entirely through the session. It needs no database and no `user_id` session key, and it is part of this installation, so there is nothing extra to install.

Unlike `CSRF`, `CSRFAnonymous` supports several independent tokens per session, one per form, each identified by a `$csrfFormId` generated inside the module.

**Generating a token for a form:**

```php
$csrfAnonymous = new CSRFAnonymous();
$csrfAnonymous->generateCsrfFormId();
$csrfAnonymous->generateToken();
$csrfAnonymous->setSession();
```

**Note**: the three methods above must be called in this exact order - `setSession()` reads the values that `generateCsrfFormId()` and `generateToken()` store on the object.

Include both values as hidden fields in your form:

```php
<input type="hidden" name="csrf_form_id" value="<?= htmlspecialchars($csrfAnonymous->csrfFormId) ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfAnonymous->csrfToken) ?>">
```

**Validating the submitted token:**

```php
$csrfAnonymous = new CSRFAnonymous();

$csrfFormId    = $_POST['csrf_form_id'] ?? '';
$tokenFromForm = $_POST['csrf_token'] ?? '';

if (!$csrfAnonymous->tokenValidation($csrfFormId, $tokenFromForm)) {
    // Handle invalid, expired, or already-used token
}
```

The token is single use: it is removed from the session immediately after a successful validation, so the same token cannot be submitted twice.

Call `destroyAllTokens()` inside your application's login method, right after a successful login, to clear all remaining anonymous tokens from the session. Forms left open in other tabs will need a fresh token after this:

```php
$csrfAnonymous = new CSRFAnonymous();
$csrfAnonymous->destroyAllTokens();
```

The lifetime of these tokens and how many of them a session may hold are set by `ANONYMOUS_TOKEN_EXPIRATION_TIME` and `ANONYMOUS_TOKENS_LIMIT` - see [Configuration reference](#configuration-reference).

### Cleaning tokens during logout

When a user logs out, delete their tokens or mark them as expired, to reduce the risk of misuse.

Deleting tokens:

```php
$csrf = new CSRF();
$csrf->logoutTokensCleanup('delete');
```

Changing the status of valid tokens to `expired`:

```php
$csrf = new CSRF();
$csrf->logoutTokensCleanup('update');
```

Both actions work on the tokens of the user whose ID is in the session, so the session must still hold a valid user ID when the method is called. The `'update'` action requires `SAVE_CSRF_STATUS` to be `true`, otherwise it throws a `LogicException`.

### Cleaning expired tokens

Expired tokens stay in the database until they are cleaned up. A token counts as expired when its timestamp is older than `time() - TOKEN_EXPIRATION_TIME`. Expired tokens are always deleted from the database.

```php
$csrf = new CSRF();                    // Assuming CSRF class is used
$csrf->allTokensCleanUp();             // Cleaning all expired tokens
$csrf->allTokensCleanUp(userId: 123);  // Cleaning expired tokens for a specific user
```

The cleanup writes to `logs/token_cleanup.log` who started it, taken from the user ID in the session. When it runs outside a logged-in session, from a cron job or a CLI script, pass the `initiator` parameter instead - it identifies the caller in the log:

```php
$csrf->allTokensCleanUp(initiator: 'cron');               // All expired tokens, started by a script
$csrf->allTokensCleanUp(userId: 123, initiator: 'cron');  // Expired tokens of one user, started by a script
```

If neither a user ID in the session nor `initiator` is available, the method throws an `InvalidArgumentException`.

The method is restricted to administrators - see [Set up the admin session](#6-set-up-the-admin-session). If the caller is not an administrator, the attempt is written to the log and a `LogicException` is thrown.

### Working with stored tokens directly

Besides generating and validating, `CSRF` exposes three methods for working with tokens already stored in the database. They are meant for maintenance screens and scripts, not for the normal form flow.

**Fetching tokens, with optional filtering - `getTokensWithData(?array $conditions = null): array|null`**

```php
$csrf = new CSRF();

$allTokens = $csrf->getTokensWithData();

$usersValidTokens = $csrf->getTokensWithData([
    ['column' => 'user_id', 'operator' => '=', 'value' => 123],
    ['column' => 'status', 'operator' => '=', 'value' => 'valid'],
]);
```

The method returns an array of rows, or `null` when nothing matches. Each condition is an array with the `column`, `operator` and `value` keys.

**Deleting tokens - `deleteToken(string $column, string|int|array $value): bool`**

```php
$csrf->deleteToken('user_id', 123);
$csrf->deleteToken('id', [4, 7, 9]);
```

An administrator can delete by any column and any number of values. Any other caller can only delete a single one of their own tokens, by `token` or by their own `user_id`, and gets a `LogicException` otherwise.

**Changing the status of tokens - `changeTokenStatus(int|array $id, string $status): bool`**

```php
$csrf->changeTokenStatus(4, 'used');
$csrf->changeTokenStatus([4, 7, 9], 'expired');
```

The allowed values are `valid`, `used` and `expired`. The method writes to the `status` column, so it works only when `SAVE_CSRF_STATUS` is `true`. It checks neither ownership of the tokens nor whether the IDs exist, so pass only IDs read from the database, for example through `getTokensWithData()`.

## Database maintenance

Every method in this section requires an admin session - see [Set up the admin session](#6-set-up-the-admin-session).

### Adding and removing the status column

The `status` column tracks the state of a token (`valid`, `used`, `expired`). The configuration constant and the column must agree, so change the constant first and then the column.

**Adding** the column:

```php
const SAVE_CSRF_STATUS = true;
```

```php
$manager = new DatabaseSchemaManager();
$manager->addStatusColumn();
```

**Removing** the column:

```php
const SAVE_CSRF_STATUS = false;
```

```php
$manager = new DatabaseSchemaManager();
$manager->removeStatusColumn();
```

`addStatusColumn()` throws a `LogicException` when `SAVE_CSRF_STATUS` is `false`, and `removeStatusColumn()` throws one when it is `true`. Both throw a `RuntimeException` when the column is already in the state you are asking for.

### Creating and removing indexes

Indexes can be created on `status`, `timestamp`, `user_id`, or on the combination of `status` and `timestamp`:

```php
$manager = new DatabaseSchemaManager();
$manager->addIndex('status');                // Index on the 'status' column
$manager->addIndex('user_id');               // Index on the 'user_id' column
$manager->addIndex(['status', 'timestamp']); // Single combined index (idx_status_timestamp)
```

**Note**: `addIndex(['status', 'timestamp'])` creates one combined (composite) index over both columns, not two separate indexes. This speeds up `allTokensCleanUp()`, which filters by `status` (equality) and `timestamp` (range) at once. It does not help queries that filter only by `timestamp`, so a separate index on `timestamp` alone is still needed for that.

Removing an index:

```php
$manager = new DatabaseSchemaManager();
$manager->removeIndex('status');
$manager->removeIndex('user_id');
$manager->removeIndex(['status', 'timestamp']);
```

Both methods return `true` on success and write the result to `logs/general.log`. `addIndex()` throws a `RuntimeException` when the index already exists, `removeIndex()` throws one when it does not exist, and both throw an `InvalidArgumentException` for a column that cannot be indexed.

### Checking the current state

These methods only read the schema, so they are useful before changing it:

| Method | Returns | Description |
| --- | --- | --- |
| `checkIfTableExists()` | `bool` | `true` when the `csrf_tokens` table exists |
| `doesColumnStatusExist()` | `bool` | `true` when the `status` column exists |
| `isIndexOnColumn(string\|array $column)` | `bool` | `true` when the given column, or the combination of `status` and `timestamp`, is indexed |
| `filterAllIndexes()` | `array` | the names of all indexes on the table |
| `findAllIndexes()` | `array` | the full index data, as `SHOW INDEXES` returns it |

Example of use:

```php
$manager = new DatabaseSchemaManager();
$manager->checkIfTableExists();
$manager->isIndexOnColumn(['status', 'timestamp']);
```

## Configuration reference

All settings live in `config/csrf_config.php`.

**Token status**. When enabled, every token carries a status (`valid`, `used`, `expired`) in the database. When disabled, used and expired tokens are deleted instead:

```php
const SAVE_CSRF_STATUS = true;
```

**Token lifetime**, in seconds:

```php
const TOKEN_EXPIRATION_TIME = 3600;
```

**Active token limit per user**. When a new token is generated and the user is already at the limit, the oldest excess tokens are deleted automatically to make room. Set it to `null` to disable the limit, in which case old tokens are never deleted automatically:

```php
const TOKENS_PER_USER = 5; // or null to disable the limit
```

The limit only caps how many tokens a user can accumulate, it does not limit how fast new tokens can be requested. Throttling those requests is left to the application, for example through APCu or web server configuration.

**Anonymous token settings**, used by `CSRFAnonymous` for forms open to unauthenticated users. The first constant is the lifetime in seconds, the second is how many tokens one session may hold at the same time. When the limit is reached, the oldest anonymous token is deleted to make room for the new one:

```php
const ANONYMOUS_TOKEN_EXPIRATION_TIME = 600;
const ANONYMOUS_TOKENS_LIMIT = 1;            // a number to set the limit or null to disable the limit
```

**Indexes**. These decide which indexes `createTable()` adds. Existing tables are changed with `addIndex()` and `removeIndex()` instead - see [Creating and removing indexes](#creating-and-removing-indexes):

```php
const INDEX_TIMESTAMP = false;
const INDEX_STATUS    = false;
const INDEX_BOTH      = false;
const INDEX_USER_ID   = false;
```

**Persistent database connection**. Enable it only in production, keep it off in development:

```php
const DB_PERSISTENT = false;
```

**Dependency injection through the `Config` class**. Every module class accepts an optional `Config` object in its constructor. When none is passed, the class creates its own, reading the values from the constants above, so plain usage such as `new CSRF()` or `new DatabaseSchemaManager()` needs no changes.

Pass your own `Config` when you need different settings for one instance, for example in tests or when one process works with more than one configuration:

```php
use CSRFModule\Config;
use CSRFModule\Database;
use CSRFModule\CSRF;

$config = new Config(
    saveCsrfStatus: true,
    dbUser: 'custom_user',
    dbPass: 'custom_pass',
    dbHost: 'custom_host',
    dbName: 'custom_db',
);

$db = new Database($config);
$csrf = new CSRF(db: $db, config: $config);
```

Every `Config` property is an optional constructor parameter, and anything you leave out falls back to the matching constant from `config/csrf_config.php`: `saveCsrfStatus`, `dbUser`, `dbPass`, `dbHost`, `dbName`, `dbPersistent`, `userIdSessionKey`, `tokenExpirationTime`, `anonymousTokenExpirationTime`, `roleName`, `roleValue`, `indexTimestamp`, `indexStatus`, `indexBoth`, `indexUserId`, `tokensPerUser`, `anonymousTokensLimit`.

## Error logging

The module writes to three files in the `logs` directory, depending on the type of action:

- **`db_errors.log`** - database connection errors and other database problems.
- **`token_cleanup.log`** - cleanup of expired or invalid tokens.
- **`general.log`** - schema changes and other events.

The directory is created automatically if it does not exist.

## Handling uncaught exceptions

The module throws exceptions for unexpected failures, so your application should register a global exception handler with [`set_exception_handler()`](https://www.php.net/manual/en/function.set-exception-handler.php). The handler should show the user a generic error message and log the details, never display them, especially in production. Keep `display_errors` disabled in production, so PHP itself does not leak error details either.

This has to be set up by your application, not by the module. `set_exception_handler()` installs a single handler for the whole process, so the module must not register its own and override yours.
