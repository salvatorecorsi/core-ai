<?php

/**
 * AI Client Wrapper
 *
 * Detects WordPress 7.0+ WP AI Client and uses it when available,
 * falling back to the native AI class for WordPress 6.9 and earlier.
 * Always logs all calls regardless of which engine is used.
 */
class AI_Client_Wrapper {

    /** @var bool Whether WP 7.0 AI Client is available */
    private $use_official_client = false;

    /** @var AI|null Native AI class instance (fallback + thread management) */
    private $native_client = null;

    /** @var array Constructor options */
    private $options = [];

    /** @var bool Whether the official client has been initialized */
    private $official_initialized = false;

    public function __construct(array $options = []) {
        $this->options = $options;

        if (class_exists('WordPress\\AI_Client\\AI_Client')) {
            $this->use_official_client = true;
            $this->init_official_client();
        } else {
            $this->native_client = new AI($options);
        }
    }

    /**
     * Initialize the WP 7.0 AI Client SDK.
     */
    private function init_official_client() {
        if ($this->official_initialized) {
            return;
        }

        if (did_action('init')) {
            \WordPress\AI_Client\AI_Client::init();
        } else {
            add_action('init', function () {
                \WordPress\AI_Client\AI_Client::init();
            });
        }

        $this->official_initialized = true;
    }

    /**
     * Send a message to the AI. Logs the call regardless of engine used.
     *
     * @param string|array $message The message to send.
     * @return string|WP_Error The AI response text, or WP_Error on failure.
     */
    public function send($message) {
        $start = microtime(true);

        try {
            if ($this->use_official_client && !$this->has_active_thread()) {
                $response = $this->send_with_official($message);
            } else {
                $response = $this->get_native_client()->send($message);
            }

            // Native client already logs via AI::send(), so only log
            // when the official client was actually used.
            if ($this->use_official_client && !$this->has_active_thread()) {
                $elapsed = round(microtime(true) - $start, 3);
                $this->log_call($message, $response, 'success', $elapsed);
            }

            return $response;

        } catch (\Exception $e) {
            $elapsed = round(microtime(true) - $start, 3);
            $this->log_call($message, null, 'error', $elapsed, $e->getMessage());
            return new \WP_Error('ai_error', $e->getMessage());
        }
    }

    /**
     * Send a message using the WP 7.0 AI Client SDK.
     */
    private function send_with_official($message) {
        $builder = \WordPress\AI_Client\AI_Client::prompt($message);

        if (!empty($this->options['model'])) {
            $builder->using_model_preference($this->options['model']);
        }

        if (isset($this->options['temperature'])) {
            $builder->using_temperature($this->options['temperature']);
        }

        if (!empty($this->options['systemMessage'])) {
            $builder->using_system_instruction($this->options['systemMessage']);
        }

        return $builder->generate_text();
    }

    /**
     * Log a call made through the official client.
     * Native client calls are already logged by AI::send().
     */
    private function log_call($message, $response, $status, $duration, $error = null) {
        $input_preview = is_string($message) ? $message : wp_json_encode($message);

        AI_Log::save([
            'thread_id'      => null,
            'model'          => $this->options['model'] ?? 'auto',
            'engine'         => 'wp_ai_client',
            'input_tokens'   => $this->estimate_tokens($input_preview),
            'output_tokens'  => $response ? $this->estimate_tokens($response) : 0,
            'total_tokens'   => $this->estimate_tokens($input_preview) + ($response ? $this->estimate_tokens($response) : 0),
            'response_time'  => $duration,
            'status'         => $status,
            'error_message'  => $error ?? '',
            'input_preview'  => mb_substr($input_preview, 0, 500),
            'output_preview' => $response ? mb_substr($response, 0, 500) : '',
        ]);
    }

    // ------------------------------------------------------------------
    // Thread management (always uses native client — WP AI Client has none)
    // ------------------------------------------------------------------

    public function newThread($title = '') {
        return $this->get_native_client()->newThread($title);
    }

    public function loadThread($id) {
        return $this->get_native_client()->loadThread($id);
    }

    public function getChat() {
        return $this->get_native_client()->getChat();
    }

    public function setChat(array $messages) {
        return $this->get_native_client()->setChat($messages);
    }

    public function clearChat() {
        return $this->get_native_client()->clearChat();
    }

    public function getThreads($limit = 50, $offset = 0) {
        return $this->get_native_client()->getThreads($limit, $offset);
    }

    public function deleteThread($id) {
        return $this->get_native_client()->deleteThread($id);
    }

    public function getThreadId() {
        return $this->get_native_client()->getThreadId();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Lazily create the native AI client when needed.
     */
    private function get_native_client() {
        if (!$this->native_client) {
            $this->native_client = new AI($this->options);
        }
        return $this->native_client;
    }

    /**
     * Check if there is an active thread loaded (requires native client path).
     */
    private function has_active_thread() {
        return $this->native_client && $this->native_client->getThreadId();
    }

    /**
     * Rough token estimate (~4 chars per token).
     */
    private function estimate_tokens($text) {
        return (int) (mb_strlen((string) $text) / 4);
    }

    /**
     * Whether the official WP AI Client is being used.
     */
    public function is_using_official_client() {
        return $this->use_official_client;
    }

    /**
     * Return the engine identifier for headers / responses.
     */
    public function get_engine_label() {
        return $this->use_official_client ? 'wp_ai_client' : 'native';
    }

    /**
     * Get the model currently configured.
     */
    public function getModel() {
        if ($this->native_client) {
            return $this->options['model'] ?? 'gpt-4o';
        }
        return $this->options['model'] ?? 'auto';
    }
}
