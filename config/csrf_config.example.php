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

/**
 * Database index creation setup.
 * 
 * These constants control whether or not indexes are created on the columns:
 * - timestamp: Used for storing the token's creation time.
 * - status: Used for tracking the token's state (valid, used, expired).
 * - both: An index on both 'timestamp' and 'status' columns.
 * 
 * Enabling indexes can speed up queries involving these columns, especially 
 * if you're frequently querying based on `timestamp` or `status`. However, 
 * adding indexes also slightly affects the performance of insertions and updates.
 * 
 * If `INDEX_BOTH` is enabled, it will create a combined index on both the `timestamp`
 * and `status` columns, which can improve performance when filtering by both columns at once.
 * You can enable `INDEX_TIMESTAMP` and `INDEX_STATUS` alongside `INDEX_BOTH`, 
 * but they are separate indexes and might have performance trade-offs.
 */
const INDEX_TIMESTAMP = false; // set true to enable indexing on timestamp column
const INDEX_STATUS    = false; // set true to enable indexing on status column
const INDEX_BOTH      = false; // set true to enable indexing on timestamp and status columns