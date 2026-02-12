<?php

class AI_Engine_Anthropic extends AI_Engine {

    private $api_url = 'https://api.anthropic.com/v1/messages';

    public static function detect($model) {
        return (bool) preg_match('/^claude-/', $model);
    }

    public function chat(array $messages, array $options = []) {
        $system = '';
        $filtered = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system .= ($system ? "\n" : '') . $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        $body = [
            'model'      => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages'   => $filtered,
        ];

        if ($system) {
            $body['system'] = $system;
        }

        unset($options['max_tokens']);
        $body = array_merge($body, $options);

        $response = wp_remote_post($this->api_url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Anthropic API error';
            return new WP_Error('anthropic_error', $msg, ['status' => $code]);
        }

        $usage = $data['usage'] ?? [];

        return [
            'content'       => $data['content'][0]['text'] ?? '',
            'engine'        => 'anthropic',
            'input_tokens'  => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens'  => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
        ];
    }

    /**
     * Fetch available models from the Anthropic API.
     * Results are cached for 1 hour via WordPress transients.
     *
     * @param string $api_key Anthropic API key.
     * @param bool   $force   Skip cache and fetch fresh data.
     * @return array|WP_Error Array of model objects or WP_Error on failure.
     */
    public static function list_models($api_key, $force = false) {
        if (empty($api_key)) {
            return new WP_Error('missing_key', 'Anthropic API key is not configured');
        }

        $cache_key = 'ai_anthropic_models';

        if (!$force) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = wp_remote_get('https://api.anthropic.com/v1/models?limit=1000', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $data['error']['message'] ?? 'Anthropic API error';
            return new WP_Error('anthropic_error', $msg, ['status' => $code]);
        }

        $models = [];
        foreach (($data['data'] ?? []) as $model) {
            $models[] = [
                'id'           => $model['id'] ?? '',
                'display_name' => $model['display_name'] ?? $model['id'] ?? '',
                'created_at'   => $model['created_at'] ?? '',
            ];
        }

        usort($models, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        set_transient($cache_key, $models, HOUR_IN_SECONDS);

        return $models;
    }
}
