<?php

/**
 * Set to true to enable saving the CSRF token status (valid, used, expired) in the database.
 * Set to false to disable saving the status.
 */
const SAVE_CSRF_STATUS = false;

// Set database
const DB_USER = 'value';                 // database user
const DB_PASS = 'value';                 // database password
const DB_HOST = 'value';                 // database host
const DB_NAME = 'value';                 // database name

/**
 * Set the session key used to store the user ID.
 * Default is $_SESSION['user_id'], but can be customized as needed.
 */
const USER_ID_SESSION_KEY = 'user_id';

/**
 * Set time expiration period.
 * Time is calculated in seconds.
 */
const TOKEN_EXPIRATION_TIME = 3600;

/**
 * Session key and value used to determine admin access.
 * Example: $_SESSION['role'] should have value 'admin' to grant access.
 */
const ROLE_NAME  = 'role';  // Session key for user role
const ROLE_VALUE = 'admin'; // Required role value for admin access
