<?php

class AI_Log {

    private static $table_suffix = 'ai_logs';

    /**
     * Pricing per 1M tokens (USD).
     * Format: model_prefix => [input_price, output_price]
     * Prices updated as of early 2025. Can be overridden via 'ai_model_pricing' filter.
     */
    private static $default_pricing = [
        // OpenAI
        'gpt-4o'            => [2.50, 10.00],
        'gpt-4o-mini'       => [0.15, 0.60],
        'gpt-4-turbo'       => [10.00, 30.00],
        'gpt-4'             => [30.00, 60.00],
        'o1'                => [15.00, 60.00],
        'o1-mini'           => [3.00, 12.00],
        'o1-pro'            => [150.00, 600.00],
        'o3'                => [10.00, 40.00],
        'o3-mini'           => [1.10, 4.40],
        'o4-mini'           => [1.10, 4.40],
        // Anthropic
        'claude-opus-4'       => [15.00, 75.00],
        'claude-sonnet-4'     => [3.00, 15.00],
        'claude-3-5-sonnet' => [3.00, 15.00],
        'claude-3-5-haiku'  => [0.80, 4.00],
        'claude-haiku-4'      => [0.80, 4.00],
        'claude-3-opus'     => [15.00, 75.00],
    ];

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_suffix;
    }

    /**
     * Calculate the cost of a call based on model and token counts.
     *
     * @param string $model        Model identifier.
     * @param int    $input_tokens  Number of input tokens.
     * @param int    $output_tokens Number of output tokens.
     * @return float Cost in USD.
     */
    public static function calculate_cost($model, $input_tokens, $output_tokens) {
        $pricing = apply_filters('ai_model_pricing', self::$default_pricing);

        // Try exact match first, then progressively shorter prefixes
        $prices = null;
        if (isset($pricing[$model])) {
            $prices = $pricing[$model];
        } else {
            // Match by longest prefix: "claude-sonnet-4-20250514" → "claude-sonnet-4"
            $best_len = 0;
            foreach ($pricing as $prefix => $p) {
                if (strpos($model, $prefix) === 0 && strlen($prefix) > $best_len) {
                    $prices = $p;
                    $best_len = strlen($prefix);
                }
            }
        }

        if (!$prices) {
            return 0.0;
        }

        // Prices are per 1M tokens
        $cost = ($input_tokens * $prices[0] / 1000000) + ($output_tokens * $prices[1] / 1000000);

        return round($cost, 6);
    }

    public static function save(array $data) {
        global $wpdb;

        $model         = $data['model'] ?? '';
        $input_tokens  = $data['input_tokens'] ?? 0;
        $output_tokens = $data['output_tokens'] ?? 0;

        // Auto-calculate cost if not explicitly provided
        $cost = $data['cost'] ?? self::calculate_cost($model, $input_tokens, $output_tokens);

        $wpdb->insert(self::table(), [
            'thread_id'      => $data['thread_id'] ?? null,
            'model'          => $model,
            'engine'         => $data['engine'] ?? '',
            'input_tokens'   => $input_tokens,
            'output_tokens'  => $output_tokens,
            'total_tokens'   => $data['total_tokens'] ?? 0,
            'response_time'  => $data['response_time'] ?? 0,
            'status'         => $data['status'] ?? 'success',
            'error_message'  => $data['error_message'] ?? '',
            'input_preview'  => $data['input_preview'] ?? '',
            'output_preview' => $data['output_preview'] ?? '',
            'cost'           => $cost,
            'created_at'     => current_time('mysql'),
        ]);

        return $wpdb->insert_id;
    }

    public static function list($filters = [], $limit = 50, $offset = 0) {
        global $wpdb;
        $table = self::table();
        $where = ['1=1'];
        $values = [];

        if (!empty($filters['model'])) {
            $where[] = 'model = %s';
            $values[] = $filters['model'];
        }

        if (!empty($filters['engine'])) {
            $where[] = 'engine = %s';
            $values[] = $filters['engine'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where);
        $values[] = $limit;
        $values[] = $offset;

        $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        if (!empty($values)) {
            $count_values = array_slice($values, 0, -2);
            $total = $count_values
                ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_values))
                : (int) $wpdb->get_var($count_sql);
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        return [
            'items' => $rows ?: [],
            'total' => $total,
        ];
    }

    public static function stats() {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row("SELECT
            COUNT(*) as total_calls,
            SUM(input_tokens) as total_input_tokens,
            SUM(output_tokens) as total_output_tokens,
            SUM(total_tokens) as total_tokens,
            AVG(response_time) as avg_response_time,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as total_errors,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as total_success,
            SUM(cost) as total_cost
        FROM $table", ARRAY_A);
    }

    public static function stats_by_model() {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results("SELECT
            model,
            engine,
            COUNT(*) as calls,
            SUM(total_tokens) as tokens,
            AVG(response_time) as avg_time,
            SUM(cost) as total_cost
        FROM $table
        GROUP BY model, engine
        ORDER BY calls DESC", ARRAY_A) ?: [];
    }

    public static function install() {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) unsigned DEFAULT NULL,
            model varchar(100) DEFAULT '',
            engine varchar(50) DEFAULT '',
            input_tokens int DEFAULT 0,
            output_tokens int DEFAULT 0,
            total_tokens int DEFAULT 0,
            response_time float DEFAULT 0,
            status varchar(20) DEFAULT 'success',
            error_message text DEFAULT '',
            input_preview text DEFAULT '',
            output_preview text DEFAULT '',
            cost decimal(10,6) DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY model (model),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add the cost column to existing tables (migration for existing installs).
     * Safe to call multiple times — checks if column exists first.
     */
    public static function maybe_add_cost_column() {
        global $wpdb;
        $table = self::table();

        $column = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'cost'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN cost decimal(10,6) DEFAULT 0 AFTER output_preview");
        }
    }
}
