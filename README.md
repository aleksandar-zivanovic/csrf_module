# CSRF Module

## Description
This module provides functionality to generate and validate CSRF tokens. It ensures protection against CSRF attacks by verifying that requests originate from trusted sources.
<br> 
<br> 

## Features
- Token generation and validation.
- Custom expiration time for tokens.
- Optional storage of token status (valid, used, expired).
<br> 
<br> 

## Installation

1. Download the module and place it in your project’s modules directory
2. Database Setup. Code for creating the table will be handled by the `createTable` method inside `DatabaseSchemaManager` class. Table configuration is set in `config/csrf_config.php`.

If the `SAVE_CSRF_STATUS` constant is set to `true`, the `status` column will be included. If set to `false`, the column will be omitted.

For setting index on `timestamp` or `status` or both columns set `true` value for each index:

```php
const INDEX_TIMESTAMP = false; // set true to enable indexing on timestamp column
const INDEX_STATUS    = false; // set true to enable indexing on status column
const INDEX_BOTH      = false; // set true to enable indexing timestamp and status columns
```

To create the table in database call `createTable` method:

```php
$manager = new DatabaseSchemaManager();
$manager->createTable();
```

Result of calling `createTable` will be logged in `csrf_module\logs\errors.log` file.

3. Configuration: Rename the file `config/csrf_config.example.php` to `config/csrf_config.php`.

4. Database Setup: Open `config/csrf_config.php` and configure your database settings. Update the `DB_USER`, `DB_PASS`, `DB_HOST`, and `DB_NAME` constants with your database credentials.

5. Error Logging: The `Database` class includes an error logging function that will capture connection errors and log them into the `logs/errors.log` file. Make sure the `logs` folder exists, or the class will create it automatically
<br> 
<br> 

## Usage

- ### Session Requirements for User ID

The module requires a valid session key for the current user’s ID. The default session key is set to `'user_id'` but can be customized in `config/csrf_config.php`. If this session key is missing or invalid, the module will stop execution and display an error.

Make sure to:
    Set the `USER_ID_SESSION_KEY` constant in `csrf_config.php` to match your application's session key.
    Ensure the session key is properly set and contains a valid integer representing the user ID.

- ### Validating CSRF Token
To validate a token submitted via a form, compare the token in the session with the one sent with the request:
```
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    // Handle CSRF attack
}
```
<br> 
<br> 

## Cleaning Expired CSRF Tokens

The module includes functionality for cleaning expired CSRF tokens from the database. Tokens are considered expired if their timestamp exceeds a specified expiration time. This cleanup process can be automated by calling the `allTokensCleanUp` method, which will remove expired tokens based on the configured expiration time.

The expiration time is defined in the `csrf_config.php` file via the `TOKEN_EXPIRATION_TIME` constant, and expired tokens are removed by running the cleanup method periodically, either manually or using a cron job.

You can call this cleanup method in your application as follows:
```
$csrf = new CSRF(); // Assuming CSRF class is used
$csrf->allTokensCleanUp();
```
<br> 
<br> 

## Admin Access for Token Cleanup
The `allTokensCleanUp` method is restricted to users with administrator privileges. This restriction is enforced using session data, and the role configuration is defined in the `csrf_config.php` file through the following constants:
```
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
```
$_SESSION['role'] = 'admin'; // Assign 'admin' role to an authorized user
```
<br> 
<br> 

## Configuration
You can configure various aspects of the CSRF module by editing the configuration file located in `modules/csrf_module/config/csrf_config.php`.
<br> 
<br> 

## License

This project is licensed under the MIT License - see the [LICENSE](https://opensource.org/licenses/MIT) file for details.

---