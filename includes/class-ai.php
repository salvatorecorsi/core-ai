<?php

class AI {

    private $model;
    private $api_key;
    private $system_message;
    private $persistent_message;
    private $engine;
    private $chat = [];
    private $thread_id = null;

    private static $engines = [
        'AI_Engine_OpenAI',
        'AI_Engine_Anthropic',
    ];

    public function __construct(array $args = []) {
        $this->model              = $args['model'] ?? 'gpt-4o';
        $this->system_message     = $args['systemMessage'] ?? '';
        $this->persistent_message = $args['persistentMessage'] ?? '';
        $this->api_key            = $args['apiKey'] ?? $this->resolve_key();
        $this->engine             = $this->make_engine();
    }

    public function send($input, array $options = []) {
        $messages = $this->build_messages($input);
        $input_preview = is_string($input) ? $input : wp_json_encode($input);

        $start = microtime(true);
        $result = $this->engine->chat($messages, $options);
        $elapsed = round(microtime(true) - $start, 3);

        if (is_wp_error($result)) {
            AI_Log::save([
                'thread_id'     => $this->thread_id,
                'model'         => $this->model,
                'engine'        => AI_Engine_Anthropic::detect($this->model) ? 'anthropic' : 'openai',
                'response_time' => $elapsed,
                'status'        => 'error',
                'error_message' => $result->get_error_message(),
                'input_preview' => mb_substr($input_preview, 0, 500),
            ]);
            return $result;
        }

        $content = $result['content'];

        AI_Log::save([
            'thread_id'      => $this->thread_id,
            'model'          => $this->model,
            'engine'         => $result['engine'],
            'input_tokens'   => $result['input_tokens'],
            'output_tokens'  => $result['output_tokens'],
            'total_tokens'   => $result['total_tokens'],
            'response_time'  => $elapsed,
            'status'         => 'success',
            'input_preview'  => mb_substr($input_preview, 0, 500),
            'output_preview' => mb_substr($content, 0, 500),
        ]);

        if (is_string($input)) {
            $this->chat[] = ['role' => 'user', 'content' => $input];
        }
        $this->chat[] = ['role' => 'assistant', 'content' => $content];

        if ($this->thread_id) {
            AI_Thread::update_messages($this->thread_id, $this->chat);
        }

        return $content;
    }

    public function setChat(array $messages) {
        $this->chat = $messages;
        return $this;
    }

    public function getChat() {
        return $this->chat;
    }

    public function clearChat() {
        $this->chat = [];
        $this->thread_id = null;
        return $this;
    }

    public function newThread($title = '') {
        $this->chat = [];
        $this->thread_id = AI_Thread::create($title, $this->model, $this->system_message);
        return $this->thread_id;
    }

    public function loadThread($id) {
        $thread = AI_Thread::get($id);

        if (!$thread) {
            return new WP_Error('thread_not_found', 'Thread non trovato');
        }

        $this->thread_id = (int) $thread['id'];
        $this->chat = $thread['messages'];

        if ($thread['system_msg']) {
            $this->system_message = $thread['system_msg'];
        }
        if ($thread['model']) {
            $this->model = $thread['model'];
            $this->engine = $this->make_engine();
        }

        return $this;
    }

    public function getThreads($limit = 50, $offset = 0) {
        return AI_Thread::list($limit, $offset);
    }

    public function deleteThread($id) {
        if ($this->thread_id === (int) $id) {
            $this->thread_id = null;
            $this->chat = [];
        }
        return AI_Thread::delete($id);
    }

    public function getThreadId() {
        return $this->thread_id;
    }

    private function build_messages($input) {
        $messages = [];

        if ($this->system_message) {
            $messages[] = ['role' => 'system', 'content' => $this->system_message];
        }

        if ($this->persistent_message) {
            $messages[] = ['role' => 'system', 'content' => $this->persistent_message];
        }

        foreach ($this->chat as $msg) {
            $messages[] = $msg;
        }

        if (is_string($input)) {
            $messages[] = ['role' => 'user', 'content' => $input];
        } elseif (is_array($input)) {
            foreach ($input as $msg) {
                $messages[] = $msg;
            }
        }

        return $messages;
    }

    private function resolve_key() {
        if (AI_Engine_Anthropic::detect($this->model)) {
            return get_option('ai_anthropic_key', '');
        }
        return get_option('ai_openai_key', '');
    }

    private function make_engine() {
        foreach (self::$engines as $class) {
            if ($class::detect($this->model)) {
                return new $class($this->model, $this->api_key);
            }
        }
        return new AI_Engine_OpenAI($this->model, $this->api_key);
    }
}
