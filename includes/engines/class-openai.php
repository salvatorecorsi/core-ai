<?php

class AI_Engine_OpenAI extends AI_Engine {

    private $api_url = 'https://api.openai.com/v1/chat/completions';

    public static function detect($model) {
        return (bool) preg_match('/^(gpt-|o1-|o3-|o4-)/', $model);
    }

    public function chat(array $messages, array $options = []) {
        $body = array_merge([
            'model'    => $this->model,
            'messages' => $messages,
        ], $options);

        $response = wp_remote_post($this->api_url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'OpenAI API error';
            return new WP_Error('openai_error', $msg, ['status' => $code]);
        }

        $usage = $data['usage'] ?? [];

        return [
            'content'       => $data['choices'][0]['message']['content'] ?? '',
            'engine'        => 'openai',
            'input_tokens'  => $usage['prompt_tokens'] ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens'  => $usage['total_tokens'] ?? 0,
        ];
    }

    /**
     * Fetch available chat models from the OpenAI API.
     * Results are cached for 1 hour via WordPress transients.
     *
     * @param string $api_key OpenAI API key.
     * @param bool   $force   Skip cache and fetch fresh data.
     * @return array|WP_Error Array of model objects or WP_Error on failure.
     */
    public static function list_models($api_key, $force = false) {
        if (empty($api_key)) {
            return new WP_Error('missing_key', 'OpenAI API key is not configured');
        }

        $cache_key = 'ai_openai_models';

        if (!$force) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $data['error']['message'] ?? 'OpenAI API error';
            return new WP_Error('openai_error', $msg, ['status' => $code]);
        }

        $models = [];
        foreach (($data['data'] ?? []) as $model) {
            $id = $model['id'] ?? '';
            if (self::detect($id)) {
                $models[] = [
                    'id'       => $id,
                    'owned_by' => $model['owned_by'] ?? '',
                    'created'  => $model['created'] ?? 0,
                ];
            }
        }

        usort($models, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        set_transient($cache_key, $models, HOUR_IN_SECONDS);

        return $models;
    }
}
