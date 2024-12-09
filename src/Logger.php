<?php

class Logger
{
    /**
     * Writes down log messsages.
     * @param string $writeLog Name of the log file.
     * @param string $message Message that you want to log.
     * @param array|string|null $errorInfo Additional information about the event. Default is null.
     */
    private function writeLog(
        string $logFileName, 
        string $message, 
        array|string|null $errorInfo = null
    ): void
    {
        // Checks if the directory exists and creates if not
        $logDirectory = __DIR__ . '../../logs/';
        if (!file_exists($logDirectory)) {
            mkdir($logDirectory, 0755, true);
        }

        $placeholder = match($logFileName) {
            "db_errors.log" => " | PDO error: ", 
            "token_cleanup.log" => " | Clean up info: ",
            "general.log" => " | General info/warrning/error: "
        };

        // Prepares final message
        if (!empty($errorInfo)) {
            $message .= is_array($errorInfo) ? $placeholder . implode(", ", $errorInfo) : $errorInfo;
        }

        // Writes down an error to the log file
        $logFile = $logDirectory . $logFileName;
        $timestamp = date("Y-m-d H:i:s");
        try {
            error_log("[$timestamp]: $message" .  PHP_EOL, 3, $logFile);
        } catch (Exception $e) {
            throw new Exception("Writting log failed!" . $e->getMessage());
        }
    }

    /**
     * Logs a database-related error message to 'db_errors.log' file.
     * This method is specifically for logging database connection/query errors.
     */
    public function logDatabaseError(string $message, array|string|null $errorInfo = null): void 
    {
        $this->writeLog('db_errors.log', $message, $errorInfo);
    }

    /**
     * Logs a cleanup related message to 'token_cleanup.log' file. 
     * This method is specifically for logging token cleanup events
     */
    public function logCleanup(string $message, array|string|null $errorInfo = null): void 
    {
        $this->writeLog('token_cleanup.log', $message, $errorInfo);
    }

    /**
     * Logs general information, warnings or errors to 'general.log' file.
     * This method is for logging general application events or issues.
     */
    public function logInfo(string $message, array|string|null $errorInfo = null): void 
    {
        $this->writeLog('general.log', $message, $errorInfo);
    }
}