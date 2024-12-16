# CSRF Module

## Versions

This module follows [SemVer](https://semver.org/) versioning rules.  

- **1.0.0**: Initial stable release.

## Description

This module provides functionality to generate and validate CSRF tokens. It ensures protection against CSRF attacks by verifying that requests originate from trusted sources.

**Current Version**: 1.0.0

## Features

- Token generation and validation.
- Custom expiration time for tokens.
- Optional storage of token status (valid, used, expired).
- Index creation and removal for `status` and `timestamp` columns.

## Installation

1. You can install the module in two ways:

    - **Install via Composer (Recommended)**

    The easiest way to install the module is via Composer. Run the following command to add it to your project:

    ```bash
    composer require aleksandarz/csrfmodule
    ```

    This will automatically add the module to your project.

    - **Manual Installation (Download from GitHub)**

    Alternatively, you can download the module manually from GitHub and place it in your project’s modules directory.

2. Database Setup. Code for creating the table will be handled by the `createTable` method inside `DatabaseSchemaManager` class. Table configuration is set in `config/csrf_config.php`.

    If the `SAVE_CSRF_STATUS` constant is set to `true`, the `status` column will be included. If set to `false`, the column will be omitted.

    For setting index on `timestamp` or `status` or both columns set `true` value for each index:

    ```php
    const INDEX_TIMESTAMP = false; // set true to enable indexing on timestamp column
    const INDEX_STATUS    = false; // set true to enable indexing on status column
    const INDEX_BOTH      = false; // set true to enable indexing timestamp and status columns
    ```

    - To create the table in database call `createTable` method:

    ```php
    $manager = new DatabaseSchemaManager();
    $manager->createTable();
    ```

    - To delete the table, you can call the `deleteTable` method:

    ```php
    $manager = new DatabaseSchemaManager();
    $manager->deleteTable();
    ```

    The result of calling either method will be logged in the `csrf_module\logs\general.log` file.
    **Note**: Deleting the table is irreversible and should only be done if you are sure it is no longer needed.

3. Configuration: Rename the file `config/csrf_config.example.php` to `config/csrf_config.php`.

4. Database Setup: Open `config/csrf_config.php` and configure your database settings. Update the `DB_USER`, `DB_PASS`, `DB_HOST`, and `DB_NAME` constants with your database credentials.

## Error Logging

The system logs errors into different log files inside `logs` direcotry based on the type of action:

- **`db_errors.log`**: Logs database connection errors and database-related issues.
- **`token_cleanup.log`**: Logs actions related to cleaning expired or invalid CSRF tokens.
- **`general.log`**: Logs general application errors or significant events.

The result of actions like table creation, deletion, or cleanup will be logged in the appropriate log file. If the `logs` directory doesn't exist it will be created automatically if needed.

## Usage

- ### Session Requirements for User ID

The module requires a valid session key for the current user’s ID. The default session key is set to `'user_id'` but can be customized in `config/csrf_config.php`. If this session key is missing or invalid, the module will stop execution and display an error.

Make sure to:
    Set the `USER_ID_SESSION_KEY` constant in `csrf_config.php` to match your application's session key.
    Ensure the session key is properly set and contains a valid integer representing the user ID.

- ### Validating CSRF Token

To validate a token submitted via a form, compare the token in the session with the one sent with the request:

```php
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    // Handle CSRF attack
}
```

- ### Adding and Removing `status` column to/from `csrf_token` table

- **Adding** `status` column:
    1. Set `SAVE_CSRF_STATUS` constant in `csrf_config.php` to `true`
    2. Call `addStatusColumn` method

    ```php
    $manager = new DatabaseSchemaManager();
    $manager->addStatusColumn();
    ```

- **Removing** `status` column:
    1. Set `SAVE_CSRF_STATUS` constant in `csrf_config.php` to `false`
    2. Call `removeStatusColumn` method:

    ```php
    $manager = new DatabaseSchemaManager();
    $manager->removeStatusColumn();
    ```

- ### Creating and Removing Indexes for Columns

- To create an index on specific columns (`status`, `timestamp`, or both), use the `addIndex` method:

```php
$csrf = new CSRF(); // Assuming CSRF class is used
$csrf->addIndex('status'); // Creates index for the 'status' column
$csrf->addIndex(['status', 'timestamp']); // Creates index for both 'status' and 'timestamp' columns
```

The method will log an error if the index already exists and return `false`. If the index is successfully created, it will return `true`.

- To remove an index from specific columns (`status`, `timestamp`, or both), use the `removeIndex` method:

```php
$csrf = new CSRF(); // Assuming CSRF class is used
$csrf->removeIndex('status'); // Removes index for the 'status' column
$csrf->removeIndex(['status', 'timestamp']); // Removes index for both 'status' and 'timestamp' columns
```

- ### Cleaning Token During User Logout

When a user logs out, it is recommended to delete all user's tokens or change status of 'valid' CSRF token(s) to reduce the risk of misuse. You can utilize the existing method for:  

- deleting tokens:

```php
$csrf = new CSRF();
$csrf-logoutTokensCleanup('delete'); // when you want to delete
```

- changing token status to 'expired':

```php
$csrf = new CSRF();
$csrf->logoutTokensCleanup('update'); // when you want to change status
```

This will change status of all tokens to 'expired' or remove all tokens associated with the current user's ID from the database. Ensure the session contains a valid user_id for this method to work.

## Cleaning Expired CSRF Tokens

The module provides functionality for cleaning expired CSRF tokens, as well as for cleaning specific user tokens or expired tokens for a specific user from the database. Tokens are considered expired if their timestamp is older than the specified expiration time, calculated as ```time() - TOKEN_EXPIRATION_TIME```. The token lifetime is defined in the `csrf_config.php` file via the `TOKEN_EXPIRATION_TIME` constant. You can start the cleaning process if you have admin privileges by calling the `allTokensCleanUp` method in your application, as shown in the example below:

```php
$csrf = new CSRF();                                    // Assuming CSRF class is used
$csrf->allTokensCleanUp();                             // Cleaning all expired tokens
$csrf->allTokensCleanUp(timestamp: true, userId: 123); // Cleaning all expired tokens by a specific user
$csrf->allTokensCleanUp(userId: 123);                  // Cleaning tokens specific user
```

## Admin Access for Token Cleanup

The `allTokensCleanUp` method is restricted to users with administrator privileges. This restriction is enforced using session data, and the role configuration is defined in the `csrf_config.php` file through the following constants:

```php
const ROLE_NAME = 'role'; // Session key for user role
const ROLE_VALUE = 'admin'; // Role value required for access
```

When the method is called, it verifies the session data to ensure the user has the required role. If the validation fails, the method:

1. Logs an error indicating unauthorized access.
2. Returns a message to the user indicating insufficient permissions.

Ensure that:

- The ROLE_NAME constant matches the session key used in your application for user roles.
- The ROLE_VALUE constant matches the role value assigned to administrators.

Example of session configuration in your application:

```php
$_SESSION['role'] = 'admin'; // Assign 'admin' role to an authorized user
```

## Configuration

You can configure various aspects of the CSRF module by editing the configuration file located in `modules/csrf_module/config/csrf_config.php`.

- **Indexing Configuration**: Enable or disable indexing for the `status` and `timestamp` columns by setting the following constants in `csrf_config.php`:

```php
const INDEX_TIMESTAMP = false; // Set true to enable indexing on timestamp column
const INDEX_STATUS    = false; // Set true to enable indexing on status column
const INDEX_BOTH      = false; // Set true to enable indexing for both timestamp and status columns
```

- **Removing Indexes**: You can remove indexes for the `status` and `timestamp` columns by calling the `removeIndex` method with the appropriate column name(s). See the `Usage` section for more details.

## License

This project is licensed under the MIT License - see the [LICENSE](https://opensource.org/licenses/MIT) file for details.

---
