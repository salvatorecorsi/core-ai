<?php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

add_action('admin_menu', function () {
    add_submenu_page(
        null,
        'AI Settings',
        'AI Settings',
        'manage_options',
        'ai-settings',
        'ai_admin_page'
    );
});

add_action('admin_init', function () {
    register_setting('ai_settings_group', 'ai_openai_key');
    register_setting('ai_settings_group', 'ai_anthropic_key');
    register_setting('ai_settings_group', 'ai_default_model');

    add_settings_section('ai_keys_section', 'API Keys', '__return_false', 'ai-settings');
    add_settings_section('ai_general_section', 'General', '__return_false', 'ai-settings');

    add_settings_field('ai_openai_key', 'OpenAI API Key', function () {
        $val = get_option('ai_openai_key', '');
        echo '<input type="password" name="ai_openai_key" value="' . esc_attr($val) . '" class="regular-text">';
    }, 'ai-settings', 'ai_keys_section');

    add_settings_field('ai_anthropic_key', 'Anthropic API Key', function () {
        $val = get_option('ai_anthropic_key', '');
        echo '<input type="password" name="ai_anthropic_key" value="' . esc_attr($val) . '" class="regular-text">';
    }, 'ai-settings', 'ai_keys_section');

    add_settings_field('ai_default_model', 'Default Model', function () {
        $val = get_option('ai_default_model', 'gpt-4o');

        // Try to get models from API, fallback to hardcoded
        $openai_key    = get_option('ai_openai_key', '');
        $anthropic_key = get_option('ai_anthropic_key', '');

        $openai_api    = $openai_key ? AI_Engine_OpenAI::list_models($openai_key) : null;
        $anthropic_api = $anthropic_key ? AI_Engine_Anthropic::list_models($anthropic_key) : null;

        $models = [];

        // OpenAI models
        if (!is_wp_error($openai_api) && !empty($openai_api)) {
            $models['OpenAI'] = array_column($openai_api, 'id');
        } else {
            $models['OpenAI'] = ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'o3-mini'];
        }

        // Anthropic models
        if (!is_wp_error($anthropic_api) && !empty($anthropic_api)) {
            $models['Anthropic'] = array_column($anthropic_api, 'id');
        } else {
            $models['Anthropic'] = ['claude-sonnet-4-20250514', 'claude-haiku-4-20250514', 'claude-opus-4-20250514'];
        }

        echo '<select name="ai_default_model">';
        foreach ($models as $group => $list) {
            echo '<optgroup label="' . esc_attr($group) . '">';
            foreach ($list as $m) {
                echo '<option value="' . esc_attr($m) . '"' . selected($val, $m, false) . '>' . esc_html($m) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';

        $has_api = (!is_wp_error($openai_api) && !empty($openai_api)) || (!is_wp_error($anthropic_api) && !empty($anthropic_api));
        if ($has_api) {
            echo '<p class="description">Models loaded from API. <a href="' . esc_url(admin_url('admin.php?page=ai-settings&tab=settings&refresh_models=1')) . '">Refresh</a></p>';
        } else {
            echo '<p class="description">Using default models. Configure API keys to load available models from your account.</p>';
        }
    }, 'ai-settings', 'ai_general_section');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'admin_page_ai-settings') return;

    wp_enqueue_style(
        'ai-admin-css',
        plugin_dir_url(__FILE__) . 'admin.css',
        [],
        filemtime(__DIR__ . '/admin.css')
    );

    wp_enqueue_script(
        'ai-admin-js',
        plugin_dir_url(__FILE__) . 'admin.js',
        ['jquery'],
        filemtime(__DIR__ . '/admin.js'),
        true
    );
});

function ai_admin_page() {
    $tab = $_GET['tab'] ?? 'logs';
    $tabs = [
        'logs'     => 'Logs',
        'stats'    => 'Stats',
        'settings' => 'Settings',
        'docs'     => 'Docs',
    ];

    echo '<div class="wrap">';
    echo '<h1>AI Settings</h1>';

    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $url = admin_url('admin.php?page=ai-settings&tab=' . $slug);
        $active = ($tab === $slug) ? ' nav-tab-active' : '';
        echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';

    echo '<div style="margin-top:20px">';
    if ($tab === 'logs') ai_admin_tab_logs();
    elseif ($tab === 'stats') ai_admin_tab_stats();
    elseif ($tab === 'settings') ai_admin_tab_settings();
    elseif ($tab === 'docs') ai_admin_tab_docs();
    echo '</div>';

    echo '</div>';
}

function ai_admin_tab_logs() {
    $table = new AI_Logs_List_Table();
    $table->prepare_items();

    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="ai-settings">';
    echo '<input type="hidden" name="tab" value="logs">';
    $table->search_box('Search model', 'model_search');
    $table->display();
    echo '</form>';
}

function ai_admin_tab_stats() {
    $overview = AI_Log::stats();
    $by_model = AI_Log::stats_by_model();

    echo '<div class="ai-stats-cards">';
    ai_stat_card('Total Calls', number_format_i18n($overview['total_calls'] ?? 0));
    ai_stat_card('Total Tokens', number_format_i18n($overview['total_tokens'] ?? 0),
        'In: ' . number_format_i18n($overview['total_input_tokens'] ?? 0) .
        ' / Out: ' . number_format_i18n($overview['total_output_tokens'] ?? 0)
    );
    ai_stat_card('Avg Response', ($overview['avg_response_time'] ? number_format((float) $overview['avg_response_time'], 2) . 's' : '—'));
    ai_stat_card('Success', number_format_i18n($overview['total_success'] ?? 0),
        number_format_i18n($overview['total_errors'] ?? 0) . ' errors'
    );
    $total_cost = (float) ($overview['total_cost'] ?? 0);
    ai_stat_card('Total Cost', '$' . number_format($total_cost, 4));
    echo '</div>';

    echo '<h3>By Model</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Model</th><th>Engine</th><th>Calls</th><th>Tokens</th><th>Cost</th><th>Avg Time</th></tr></thead>';
    echo '<tbody>';
    if (empty($by_model)) {
        echo '<tr><td colspan="6"><em>No data yet</em></td></tr>';
    }
    foreach ($by_model as $m) {
        $model_cost = (float) ($m['total_cost'] ?? 0);
        echo '<tr>';
        echo '<td><strong>' . esc_html($m['model']) . '</strong></td>';
        echo '<td>' . esc_html($m['engine']) . '</td>';
        echo '<td>' . number_format_i18n($m['calls']) . '</td>';
        echo '<td>' . number_format_i18n($m['tokens'] ?? 0) . '</td>';
        echo '<td>$' . number_format($model_cost, 4) . '</td>';
        echo '<td>' . number_format((float) $m['avg_time'], 2) . 's</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function ai_stat_card($label, $value, $sub = '') {
    echo '<div class="ai-stat-card">';
    echo '<div class="ai-stat-label">' . esc_html($label) . '</div>';
    echo '<div class="ai-stat-value">' . esc_html($value) . '</div>';
    if ($sub) echo '<div class="ai-stat-sub">' . esc_html($sub) . '</div>';
    echo '</div>';
}

function ai_admin_tab_settings() {
    // Handle refresh request
    if (isset($_GET['refresh_models']) && $_GET['refresh_models'] === '1') {
        delete_transient('ai_openai_models');
        delete_transient('ai_anthropic_models');
        echo '<div class="notice notice-success is-dismissible"><p>Models cache cleared. Fresh data loaded.</p></div>';
    }

    echo '<form method="post" action="options.php">';
    settings_fields('ai_settings_group');
    do_settings_sections('ai-settings');
    submit_button('Save Settings');
    echo '</form>';

    // Available Models section
    echo '<div class="ai-models-section" style="display: none">';
    echo '<h2>Available Models</h2>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=ai-settings&tab=settings&refresh_models=1')) . '" class="button">Refresh Models</a></p>';

    $openai_key    = get_option('ai_openai_key', '');
    $anthropic_key = get_option('ai_anthropic_key', '');

    // OpenAI Models
    echo '<h3>OpenAI</h3>';
    if (empty($openai_key)) {
        echo '<div class="ai-models-notice"><p>Configure your OpenAI API key above to see available models.</p></div>';
    } else {
        $openai_models = AI_Engine_OpenAI::list_models($openai_key);
        if (is_wp_error($openai_models)) {
            echo '<div class="ai-models-notice ai-models-error"><p>Error: ' . esc_html($openai_models->get_error_message()) . '</p></div>';
        } elseif (empty($openai_models)) {
            echo '<div class="ai-models-notice"><p>No chat models found.</p></div>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Model ID</th><th>Owned By</th></tr></thead>';
            echo '<tbody>';
            foreach ($openai_models as $model) {
                echo '<tr>';
                echo '<td><code>' . esc_html($model['id']) . '</code></td>';
                echo '<td>' . esc_html($model['owned_by']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . count($openai_models) . ' chat models available</p>';
        }
    }

    // Anthropic Models
    echo '<h3>Anthropic</h3>';
    if (empty($anthropic_key)) {
        echo '<div class="ai-models-notice"><p>Configure your Anthropic API key above to see available models.</p></div>';
    } else {
        $anthropic_models = AI_Engine_Anthropic::list_models($anthropic_key);
        if (is_wp_error($anthropic_models)) {
            echo '<div class="ai-models-notice ai-models-error"><p>Error: ' . esc_html($anthropic_models->get_error_message()) . '</p></div>';
        } elseif (empty($anthropic_models)) {
            echo '<div class="ai-models-notice"><p>No models found.</p></div>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Model ID</th><th>Display Name</th></tr></thead>';
            echo '<tbody>';
            foreach ($anthropic_models as $model) {
                echo '<tr>';
                echo '<td><code>' . esc_html($model['id']) . '</code></td>';
                echo '<td>' . esc_html($model['display_name']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . count($anthropic_models) . ' models available</p>';
        }
    }

    echo '</div>';
}

function ai_admin_tab_docs() {
    echo '<div class="ai-docs">';

    echo '<h3>Quick Start</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>What</th><th>Code</th></tr></thead>';
    echo '<tbody>';

    ai_doc_row(
        'Simple message',
        "\$response = call_ai('Hello, how are you?');"
    );

    ai_doc_row(
        'Choose model',
        "\$response = call_ai('Hello!', ['model' => 'claude-sonnet-4-20250514']);"
    );

    ai_doc_row(
        'Using the class',
        "\$ai = new AI_Client_Wrapper(['model' => 'gpt-4o']);\n\$response = \$ai->send('Hello!');"
    );

    ai_doc_row(
        'With system message',
        "\$ai = new AI_Client_Wrapper([\n    'model'         => 'gpt-4o',\n    'systemMessage' => 'You are a poet',\n]);\n\$response = \$ai->send('Write me a haiku');"
    );

    ai_doc_row(
        'Persistent message',
        "\$ai = new AI_Client_Wrapper([\n    'model'             => 'gpt-4o',\n    'persistentMessage' => 'Always reply in Italian',\n]);\n\$response = \$ai->send('What is the weather?');"
    );

    echo '</tbody></table>';

    echo '<h3>Chat with Context</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>What</th><th>Code</th></tr></thead>';
    echo '<tbody>';

    ai_doc_row(
        'Pre-set a chat',
        "\$ai = new AI_Client_Wrapper(['model' => 'gpt-4o']);\n\$ai->setChat([\n    ['role' => 'user', 'content' => 'My name is John'],\n    ['role' => 'assistant', 'content' => 'Nice to meet you John!'],\n]);\n\$response = \$ai->send('What is my name?');"
    );

    ai_doc_row(
        'Multi-turn (auto)',
        "\$ai = new AI_Client_Wrapper(['model' => 'gpt-4o']);\n\$ai->send('My name is John');\n\$ai->send('What is my name?');\n\$chat = \$ai->getChat();"
    );

    ai_doc_row(
        'call_ai with chat array',
        "\$response = call_ai([\n    ['role' => 'user', 'content' => 'Hi!'],\n    ['role' => 'assistant', 'content' => 'Hello!'],\n    ['role' => 'user', 'content' => 'How are you?'],\n]);"
    );

    ai_doc_row(
        'Clear chat',
        "\$ai->clearChat();"
    );

    echo '</tbody></table>';

    echo '<h3>Threads (DB Persistence)</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>What</th><th>Code</th></tr></thead>';
    echo '<tbody>';

    ai_doc_row(
        'Create thread',
        "\$ai = new AI_Client_Wrapper(['model' => 'gpt-4o']);\n\$threadId = \$ai->newThread('My conversation');\n\$ai->send('Remember my name is John');"
    );

    ai_doc_row(
        'Load & continue',
        "\$ai = new AI_Client_Wrapper();\n\$ai->loadThread(\$threadId);\n\$response = \$ai->send('What is my name?');"
    );

    ai_doc_row(
        'List all threads',
        "\$threads = \$ai->getThreads();\nforeach (\$threads as \$t) {\n    echo \$t['id'] . ' - ' . \$t['title'];\n}"
    );

    ai_doc_row(
        'Delete thread',
        "\$ai->deleteThread(\$threadId);"
    );

    ai_doc_row(
        'Get current thread ID',
        "\$id = \$ai->getThreadId();"
    );

    echo '</tbody></table>';

    echo '<h3>REST API Endpoints</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>';
    echo '<tbody>';
    ai_doc_endpoint('POST', '/wp-json/ai/v1/send', 'Send a message. Body: { message, model?, chat?, systemMessage? }');
    ai_doc_endpoint('GET',  '/wp-json/ai/v1/threads', 'List all threads');
    ai_doc_endpoint('POST', '/wp-json/ai/v1/threads', 'Create a thread. Body: { title?, model?, systemMessage? }');
    ai_doc_endpoint('GET',  '/wp-json/ai/v1/threads/{id}', 'Get a thread with full message history');
    ai_doc_endpoint('DELETE', '/wp-json/ai/v1/threads/{id}', 'Delete a thread');
    ai_doc_endpoint('POST', '/wp-json/ai/v1/threads/{id}/send', 'Send a message inside a thread. Body: { message }');
    ai_doc_endpoint('GET',  '/wp-json/ai/v1/logs', 'List logs. Params: page, per_page, model, engine, status, date_from, date_to');
    ai_doc_endpoint('GET',  '/wp-json/ai/v1/logs/stats', 'Get aggregated stats');
    ai_doc_endpoint('GET',  '/wp-json/ai/v1/settings', 'Get current settings');
    ai_doc_endpoint('POST', '/wp-json/ai/v1/settings', 'Save settings. Body: { openai_key?, anthropic_key?, default_model? }');
    echo '</tbody></table>';

    echo '<h3>JavaScript (fetch)</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>What</th><th>Code</th></tr></thead>';
    echo '<tbody>';

    ai_doc_row(
        'Send message',
        "const res = await fetch('/wp-json/ai/v1/send', {\n    method: 'POST',\n    headers: {\n        'Content-Type': 'application/json',\n        'X-WP-Nonce': wpApiSettings.nonce,\n    },\n    body: JSON.stringify({ message: 'Hello!', model: 'gpt-4o' }),\n});\nconst data = await res.json();\nconsole.log(data.response);"
    );

    ai_doc_row(
        'Create & use thread',
        "const t = await fetch('/wp-json/ai/v1/threads', {\n    method: 'POST',\n    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },\n    body: JSON.stringify({ title: 'Test' }),\n}).then(r => r.json());\n\nconst msg = await fetch('/wp-json/ai/v1/threads/' + t.thread_id + '/send', {\n    method: 'POST',\n    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },\n    body: JSON.stringify({ message: 'Hello!' }),\n}).then(r => r.json());\nconsole.log(msg.response);"
    );

    echo '</tbody></table>';

    echo '<h3>WordPress 7.0 Compatibility</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>WordPress Version</th><th>Engine</th><th>Notes</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><strong>7.0+</strong></td><td>WP AI Client SDK</td><td>Uses official provider management. Logging, stats, and threads still handled by AI Core.</td></tr>';
    echo '<tr><td><strong>6.9 and earlier</strong></td><td>Native (built-in)</td><td>Uses built-in OpenAI/Anthropic engines. Fully functional.</td></tr>';
    echo '</tbody></table>';

    echo '<h3>Abilities API (MCP)</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Ability</th><th>Description</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><code>ai_core/send_message</code></td><td>Send messages to AI</td></tr>';
    echo '<tr><td><code>ai_core/create_thread</code></td><td>Create conversation threads</td></tr>';
    echo '<tr><td><code>ai_core/get_thread</code></td><td>Retrieve thread history</td></tr>';
    echo '<tr><td><code>ai_core/get_stats</code></td><td>Get usage statistics</td></tr>';
    echo '<tr><td><code>ai_core/get_logs</code></td><td>Access call logs</td></tr>';
    echo '</tbody></table>';

    echo '<h3>Constructor Options</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Option</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><code>model</code></td><td>string</td><td>gpt-4o</td><td>Model name (auto-detects engine from prefix)</td></tr>';
    echo '<tr><td><code>apiKey</code></td><td>string</td><td>from wp_options</td><td>Override API key for this instance</td></tr>';
    echo '<tr><td><code>systemMessage</code></td><td>string</td><td>""</td><td>System prompt sent with every request</td></tr>';
    echo '<tr><td><code>persistentMessage</code></td><td>string</td><td>""</td><td>Additional system message appended to every request</td></tr>';
    echo '</tbody></table>';

    echo '<h3>Model Detection</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Prefix</th><th>Engine</th><th>wp_option key</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><code>gpt-*</code>, <code>o1-*</code>, <code>o3-*</code>, <code>o4-*</code></td><td>OpenAI</td><td><code>ai_openai_key</code></td></tr>';
    echo '<tr><td><code>claude-*</code></td><td>Anthropic</td><td><code>ai_anthropic_key</code></td></tr>';
    echo '</tbody></table>';

    echo '</div>';
}

function ai_doc_row($what, $code) {
    echo '<tr>';
    echo '<td><strong>' . esc_html($what) . '</strong></td>';
    echo '<td><pre class="ai-doc-code">' . esc_html($code) . '</pre></td>';
    echo '</tr>';
}

function ai_doc_endpoint($method, $endpoint, $desc) {
    $class = $method === 'GET' ? 'ai-method-get' : ($method === 'DELETE' ? 'ai-method-delete' : 'ai-method-post');
    echo '<tr>';
    echo '<td><span class="' . $class . '">' . esc_html($method) . '</span></td>';
    echo '<td><code>' . esc_html($endpoint) . '</code></td>';
    echo '<td>' . esc_html($desc) . '</td>';
    echo '</tr>';
}

class AI_Logs_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'log',
            'plural'   => 'logs',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'created_at'    => 'Date',
            'model'         => 'Model',
            'engine'        => 'Engine',
            'input_tokens'  => 'Tokens In',
            'output_tokens' => 'Tokens Out',
            'total_tokens'  => 'Total',
            'cost'          => 'Cost',
            'response_time' => 'Time',
            'status'        => 'Status',
            'input_preview' => 'Input',
            'output_preview'=> 'Output',
        ];
    }

    public function get_sortable_columns() {
        return [
            'created_at'    => ['created_at', true],
            'model'         => ['model', false],
            'total_tokens'  => ['total_tokens', false],
            'cost'          => ['cost', false],
            'response_time' => ['response_time', false],
            'status'        => ['status', false],
        ];
    }

    protected function extra_tablenav($which) {
        if ($which !== 'top') return;

        $engine = $_GET['engine'] ?? '';
        $status = $_GET['filter_status'] ?? '';

        echo '<div class="alignleft actions">';

        echo '<select name="engine">';
        echo '<option value="">All engines</option>';
        echo '<option value="openai"' . selected($engine, 'openai', false) . '>OpenAI</option>';
        echo '<option value="anthropic"' . selected($engine, 'anthropic', false) . '>Anthropic</option>';
        echo '</select>';

        echo '<select name="filter_status">';
        echo '<option value="">All statuses</option>';
        echo '<option value="success"' . selected($status, 'success', false) . '>Success</option>';
        echo '<option value="error"' . selected($status, 'error', false) . '>Error</option>';
        echo '</select>';

        submit_button('Filter', '', 'filter_action', false);
        echo '</div>';
    }

    public function prepare_items() {
        $per_page = 30;
        $current_page = $this->get_pagenum();

        $filters = [];
        if (!empty($_GET['engine'])) $filters['engine'] = sanitize_text_field($_GET['engine']);
        if (!empty($_GET['filter_status'])) $filters['status'] = sanitize_text_field($_GET['filter_status']);
        if (!empty($_GET['s'])) $filters['model'] = sanitize_text_field($_GET['s']);

        $orderby = sanitize_sql_orderby($_GET['orderby'] ?? 'created_at') ?: 'created_at';
        $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

        $result = AI_Log::list($filters, $per_page, ($current_page - 1) * $per_page);

        $this->items = $result['items'];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($result['total'] / $per_page),
        ]);
    }

    public function column_default($item, $name) {
        switch ($name) {
            case 'created_at':
                return esc_html(wp_date('d/m/Y H:i', strtotime($item['created_at'])));
            case 'model':
                return '<strong>' . esc_html($item['model']) . '</strong>';
            case 'engine':
                return esc_html($item['engine']);
            case 'input_tokens':
                return number_format_i18n($item['input_tokens']);
            case 'output_tokens':
                return number_format_i18n($item['output_tokens']);
            case 'total_tokens':
                return number_format_i18n($item['total_tokens']);
            case 'cost':
                $cost = (float) ($item['cost'] ?? 0);
                if ($cost <= 0) return '<em>—</em>';
                return '$' . number_format($cost, 4);
            case 'response_time':
                return number_format((float) $item['response_time'], 2) . 's';
            case 'status':
                $class = $item['status'] === 'success' ? 'ai-badge-success' : 'ai-badge-error';
                return '<span class="' . $class . '">' . esc_html($item['status']) . '</span>';
            case 'input_preview':
                return $this->preview_cell($item['input_preview']);
            case 'output_preview':
                $text = $item['status'] === 'error' ? $item['error_message'] : $item['output_preview'];
                return $this->preview_cell($text);
            default:
                return esc_html($item[$name] ?? '');
        }
    }

    private function preview_cell($text) {
        if (!$text) return '<em>—</em>';
        $short = mb_substr($text, 0, 80);
        if (mb_strlen($text) > 80) $short .= '...';
        return '<span class="ai-preview" title="' . esc_attr($text) . '">' . esc_html($short) . '</span>';
    }

    public function single_row($item) {
        echo '<tr class="ai-log-row" data-id="' . esc_attr($item['id']) . '">';
        $this->single_row_columns($item);
        echo '</tr>';

        echo '<tr class="ai-detail-row" data-id="' . esc_attr($item['id']) . '" style="display:none">';
        echo '<td colspan="' . count($this->get_columns()) . '">';
        echo '<div class="ai-detail-grid">';

        echo '<div>';
        echo '<strong>Full Input</strong>';
        echo '<pre class="ai-detail-pre">' . esc_html($item['input_preview'] ?: '—') . '</pre>';
        echo '</div>';

        echo '<div>';
        echo '<strong>Full Output</strong>';
        $out = $item['status'] === 'error' ? $item['error_message'] : $item['output_preview'];
        echo '<pre class="ai-detail-pre">' . esc_html($out ?: '—') . '</pre>';
        echo '</div>';

        echo '</div>';
        echo '</td></tr>';
    }
}
