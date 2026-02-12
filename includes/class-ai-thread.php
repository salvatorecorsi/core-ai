<?php

class AI_Thread {

    private static $table_suffix = 'ai_threads';

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_suffix;
    }

    public static function create($title = '', $model = '', $system_msg = '') {
        global $wpdb;

        $wpdb->insert(self::table(), [
            'title'      => $title,
            'model'      => $model,
            'messages'   => wp_json_encode([]),
            'system_msg' => $system_msg,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return $wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A);

        if ($row) {
            $row['messages'] = json_decode($row['messages'], true) ?: [];
        }

        return $row;
    }

    public static function list($limit = 50, $offset = 0) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, model, created_at, updated_at FROM " . self::table() .
            " ORDER BY updated_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A);

        return $rows ?: [];
    }

    public static function update_messages($id, array $messages) {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'messages'   => wp_json_encode($messages),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete(self::table(), ['id' => $id]);
    }

    public static function install() {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) DEFAULT '',
            model varchar(100) DEFAULT '',
            messages longtext NOT NULL,
            system_msg text DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        AI_Log::install();
    }
}
