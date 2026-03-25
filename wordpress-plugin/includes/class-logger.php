<?php
defined('ABSPATH') || exit;

class ContentSpy_Logger {

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . CONTENTSPY_LOG_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(100) NOT NULL,
            method VARCHAR(10) NOT NULL,
            status_code SMALLINT NOT NULL,
            message TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function log(string $action, string $method, int $statusCode, string $message = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . CONTENTSPY_LOG_TABLE;

        $wpdb->insert($table, [
            'action'      => $action,
            'method'      => $method,
            'status_code' => $statusCode,
            'message'     => $message,
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'  => current_time('mysql', true),
        ]);
    }
}
