<?php

/**
 * AI Core Abilities Registration
 *
 * Registers all AI Core capabilities with the WordPress Abilities API
 * (available in WP 6.9+ via wp_register_ability()).
 * These abilities can be discovered and executed by MCP-compatible tools.
 */
class AI_Core_Abilities {

    public static function init() {
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);
    }

    public static function register_abilities() {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        self::register_send_message();
        self::register_create_thread();
        self::register_get_thread();
        self::register_get_stats();
        self::register_get_logs();
    }

    // ------------------------------------------------------------------
    // Ability 1: ai_core/send_message
    // ------------------------------------------------------------------

    private static function register_send_message() {
        wp_register_ability(
            'ai_core/send_message',
            [
                'label'         => __('Send AI Message', 'core-ai'),
                'description'   => __('Send a message to AI and receive a response', 'core-ai'),
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'message' => [
                            'type'        => 'string',
                            'description' => 'The message to send to the AI',
                        ],
                        'model' => [
                            'type'        => 'string',
                            'description' => 'AI model to use (optional)',
                            'enum'        => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'o3-mini', 'claude-sonnet-4-20250514', 'claude-haiku-4-20250514', 'claude-opus-4-20250514'],
                        ],
                        'thread_id' => [
                            'type'        => 'integer',
                            'description' => 'Thread ID to continue conversation (optional)',
                        ],
                        'system_message' => [
                            'type'        => 'string',
                            'description' => 'System instruction for the AI (optional)',
                        ],
                    ],
                    'required' => ['message'],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'response'    => ['type' => 'string'],
                        'model_used'  => ['type' => 'string'],
                        'tokens_used' => ['type' => 'integer'],
                        'thread_id'   => ['type' => 'integer'],
                        'engine'      => ['type' => 'string'],
                    ],
                ],
                'callback' => [self::class, 'send_message_callback'],
            ]
        );
    }

    public static function send_message_callback($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'Insufficient permissions');
        }

        $message = $args['message'] ?? '';
        if (empty($message)) {
            return new WP_Error('missing_message', 'The message parameter is required');
        }

        $options = [
            'model' => $args['model'] ?? get_option('ai_default_model', 'gpt-4o'),
        ];

        if (!empty($args['system_message'])) {
            $options['systemMessage'] = $args['system_message'];
        }

        $ai = new AI_Client_Wrapper($options);

        if (!empty($args['thread_id'])) {
            $loaded = $ai->loadThread((int) $args['thread_id']);
            if (is_wp_error($loaded)) {
                return $loaded;
            }
        }

        $response = $ai->send($message);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'response'    => $response,
            'model_used'  => $options['model'],
            'tokens_used' => (int) (mb_strlen($response) / 4),
            'thread_id'   => $ai->getThreadId(),
            'engine'      => $ai->get_engine_label(),
        ];
    }

    // ------------------------------------------------------------------
    // Ability 2: ai_core/create_thread
    // ------------------------------------------------------------------

    private static function register_create_thread() {
        wp_register_ability(
            'ai_core/create_thread',
            [
                'label'         => __('Create Conversation Thread', 'core-ai'),
                'description'   => __('Start a new AI conversation thread', 'core-ai'),
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => [
                            'type'        => 'string',
                            'description' => 'Title for the conversation thread',
                        ],
                    ],
                    'required' => ['title'],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'thread_id'  => ['type' => 'integer'],
                        'title'      => ['type' => 'string'],
                        'created_at' => ['type' => 'string'],
                    ],
                ],
                'callback' => [self::class, 'create_thread_callback'],
            ]
        );
    }

    public static function create_thread_callback($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'Insufficient permissions');
        }

        $title = $args['title'] ?? '';
        if (empty($title)) {
            return new WP_Error('missing_title', 'The title parameter is required');
        }

        $ai = new AI_Client_Wrapper();
        $thread_id = $ai->newThread(sanitize_text_field($title));

        if (!$thread_id) {
            return new WP_Error('thread_creation_failed', 'Failed to create thread');
        }

        return [
            'thread_id'  => $thread_id,
            'title'      => $title,
            'created_at' => current_time('mysql'),
        ];
    }

    // ------------------------------------------------------------------
    // Ability 3: ai_core/get_thread
    // ------------------------------------------------------------------

    private static function register_get_thread() {
        wp_register_ability(
            'ai_core/get_thread',
            [
                'label'         => __('Get Conversation Thread', 'core-ai'),
                'description'   => __('Retrieve a conversation thread with full message history', 'core-ai'),
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'thread_id' => [
                            'type'        => 'integer',
                            'description' => 'Thread ID to retrieve',
                        ],
                    ],
                    'required' => ['thread_id'],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'thread_id' => ['type' => 'integer'],
                        'title'     => ['type' => 'string'],
                        'messages'  => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'role'      => ['type' => 'string'],
                                    'content'   => ['type' => 'string'],
                                    'timestamp' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'message_count' => ['type' => 'integer'],
                        'created_at'    => ['type' => 'string'],
                    ],
                ],
                'callback' => [self::class, 'get_thread_callback'],
            ]
        );
    }

    public static function get_thread_callback($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'Insufficient permissions');
        }

        $thread_id = (int) ($args['thread_id'] ?? 0);
        if (!$thread_id) {
            return new WP_Error('missing_thread_id', 'The thread_id parameter is required');
        }

        $thread = AI_Thread::get($thread_id);

        if (!$thread) {
            return new WP_Error('thread_not_found', 'Thread not found');
        }

        $messages = $thread['messages'] ?? [];

        return [
            'thread_id'     => (int) $thread['id'],
            'title'         => $thread['title'] ?? '',
            'messages'      => $messages,
            'message_count' => count($messages),
            'created_at'    => $thread['created_at'] ?? '',
        ];
    }

    // ------------------------------------------------------------------
    // Ability 4: ai_core/get_stats
    // ------------------------------------------------------------------

    private static function register_get_stats() {
        wp_register_ability(
            'ai_core/get_stats',
            [
                'label'         => __('Get AI Usage Statistics', 'core-ai'),
                'description'   => __('Retrieve detailed statistics about AI usage', 'core-ai'),
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'model' => [
                            'type'        => 'string',
                            'description' => 'Filter by specific model (optional)',
                        ],
                        'date_from' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'Start date YYYY-MM-DD (optional)',
                        ],
                        'date_to' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'End date YYYY-MM-DD (optional)',
                        ],
                    ],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'total_calls'         => ['type' => 'integer'],
                        'total_tokens'        => ['type' => 'integer'],
                        'average_response_time' => ['type' => 'number'],
                        'success_rate'        => ['type' => 'number'],
                        'by_model' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'model'  => ['type' => 'string'],
                                    'calls'  => ['type' => 'integer'],
                                    'tokens' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
                'callback' => [self::class, 'get_stats_callback'],
            ]
        );
    }

    public static function get_stats_callback($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'Insufficient permissions');
        }

        $overview = AI_Log::stats();
        $by_model = AI_Log::stats_by_model();

        $total_calls = (int) ($overview['total_calls'] ?? 0);
        $total_success = (int) ($overview['total_success'] ?? 0);

        return [
            'total_calls'           => $total_calls,
            'total_tokens'          => (int) ($overview['total_tokens'] ?? 0),
            'average_response_time' => (float) ($overview['avg_response_time'] ?? 0),
            'success_rate'          => $total_calls > 0 ? round($total_success / $total_calls * 100, 2) : 0,
            'by_model'              => array_map(function ($m) {
                return [
                    'model'  => $m['model'],
                    'calls'  => (int) $m['calls'],
                    'tokens' => (int) ($m['tokens'] ?? 0),
                ];
            }, $by_model),
        ];
    }

    // ------------------------------------------------------------------
    // Ability 5: ai_core/get_logs
    // ------------------------------------------------------------------

    private static function register_get_logs() {
        wp_register_ability(
            'ai_core/get_logs',
            [
                'label'         => __('Get AI Call Logs', 'core-ai'),
                'description'   => __('Retrieve detailed logs of AI API calls', 'core-ai'),
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of logs to return',
                            'default'     => 50,
                            'minimum'     => 1,
                            'maximum'     => 500,
                        ],
                        'model' => [
                            'type'        => 'string',
                            'description' => 'Filter by model (optional)',
                        ],
                        'status' => [
                            'type'        => 'string',
                            'enum'        => ['success', 'error'],
                            'description' => 'Filter by status (optional)',
                        ],
                    ],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'logs' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'        => ['type' => 'integer'],
                                    'message'   => ['type' => 'string'],
                                    'response'  => ['type' => 'string'],
                                    'model'     => ['type' => 'string'],
                                    'engine'    => ['type' => 'string'],
                                    'status'    => ['type' => 'string'],
                                    'tokens'    => ['type' => 'integer'],
                                    'duration'  => ['type' => 'number'],
                                    'timestamp' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'total' => ['type' => 'integer'],
                    ],
                ],
                'callback' => [self::class, 'get_logs_callback'],
            ]
        );
    }

    public static function get_logs_callback($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'Insufficient permissions');
        }

        $limit  = max(1, min(500, (int) ($args['limit'] ?? 50)));
        $filters = [];

        if (!empty($args['model'])) {
            $filters['model'] = sanitize_text_field($args['model']);
        }
        if (!empty($args['status'])) {
            $filters['status'] = sanitize_text_field($args['status']);
        }

        $result = AI_Log::list($filters, $limit, 0);

        $logs = array_map(function ($row) {
            return [
                'id'        => (int) $row['id'],
                'message'   => $row['input_preview'] ?? '',
                'response'  => $row['output_preview'] ?? '',
                'model'     => $row['model'] ?? '',
                'engine'    => $row['engine'] ?? '',
                'status'    => $row['status'] ?? '',
                'tokens'    => (int) ($row['total_tokens'] ?? 0),
                'duration'  => (float) ($row['response_time'] ?? 0),
                'timestamp' => $row['created_at'] ?? '',
            ];
        }, $result['items']);

        return [
            'logs'  => $logs,
            'total' => (int) $result['total'],
        ];
    }
}

AI_Core_Abilities::init();
