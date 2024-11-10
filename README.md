# CSRF Module

## Description
This module provides functionality to generate and validate CSRF tokens. It ensures protection against CSRF attacks by verifying that requests originate from trusted sources.

## Features
- Token generation and validation.
- Custom expiration time for tokens.
- Optional storage of token status (valid, used, expired).

## Installation
1. Download the module and place it in your project’s modules directory
2. Database Setup 

    Open phpMyAdmin or any MySQL client, and copy-paste the following code to create the required table for CSRF tokens.

    Code for enabled saving token status (with `status` column):

    ```
    CREATE TABLE csrf_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        token VARCHAR(255) NOT NULL, 
        timestamp INT NOT NULL, 
        status ENUM('valid', 'used', 'expired') DEFAULT 'valid', 
        user_id INT
    );

    ```

    Code for disabled saving token status (without `status` column):

    ```
    CREATE TABLE csrf_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        token VARCHAR(255) NOT NULL, 
        timestamp INT NOT NULL, 
        user_id INT
    );

    ```
3. Configuration: Adjust settings in `csrf_config.php` as needed. The `SAVE_CSRF_STATUS` option determines if token status is stored in the database (`true` for saving, `false` for not saving).

## Usage
### Generating CSRF Token
You can generate a CSRF token with the following function:
```
function generateCsrfToken() {
    $token = bin2hex(random_bytes(32));  // Generate a random token
    $_SESSION['csrf_token'] = $token;   // Store token in session
    return $token;
}
```

### Validating CSRF Token
To validate a token submitted via a form, compare the token in the session with the one sent with the request:
```
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    // Handle CSRF attack
}
```

## Configuration
You can configure various aspects of the CSRF module by editing the configuration file located in `modules/csrf_module/config/csrf_config.php`.

## License

This project is licensed under the MIT License - see the [LICENSE](https://opensource.org/licenses/MIT) file for details.

---