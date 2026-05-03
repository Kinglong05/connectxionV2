<?php
/**
 * ConnectXion Centralized Logging Utility
 */

if (defined('LOGGER_PHP')) return;
define('LOGGER_PHP', true);

class Logger {
    private static $logFile = __DIR__ . '/logs/app.log';

    /**
     * Log a message with a specific level
     */
    public static function log($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logEntry = "[$timestamp] [$level] $message $contextStr" . PHP_EOL;

        // Ensure logs directory exists
        if (!is_dir(__DIR__ . '/logs')) {
            mkdir(__DIR__ . '/logs', 0755, true);
        }

        error_log($logEntry, 3, self::$logFile);
    }

    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }

    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }

    public static function security($message, $context = []) {
        self::log('SECURITY', $message, $context);
    }

    public static function debug($message, $context = []) {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            self::log('DEBUG', $message, $context);
        }
    }
}
?>
