<?php

add_action('rest_api_init', function () {
    $namespace = 'core-ai/';

    register_rest_route($namespace, '/send', [
        'methods'             => 'POST',
        'callback'            => 'ai_rest_send',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/threads', [
        'methods'             => 'GET',
        'callback'            => 'ai_rest_get_threads',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/threads', [
        'methods'             => 'POST',
        'callback'            => 'ai_rest_create_thread',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/threads/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'ai_rest_get_thread',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/threads/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'ai_rest_delete_thread',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/threads/(?P<id>\d+)/send', [
        'methods'             => 'POST',
        'callback'            => 'ai_rest_thread_send',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/logs', [
        'methods'             => 'GET',
        'callback'            => 'ai_rest_get_logs',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/logs/stats', [
        'methods'             => 'GET',
        'callback'            => 'ai_rest_get_stats',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/settings', [
        'methods'             => 'GET',
        'callback'            => 'ai_rest_get_settings',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/settings', [
        'methods'             => 'POST',
        'callback'            => 'ai_rest_save_settings',
        'permission_callback' => 'ai_rest_permission',
    ]);

    register_rest_route($namespace, '/models', [
        'methods'             => 'GET',
        'callback'            => 'ai_rest_get_models',
        'permission_callback' => 'ai_rest_permission',
    ]);
});

function ai_rest_permission($request) {

    $result = apply_filters('ai_rest_permission', null, $request);
    if ($result !== null) {
        return $result;
    }

    return current_user_can('manage_options');
}

function ai_rest_send(WP_REST_Request $request) {
    $body    = $request->get_json_params();
    $message = $body['message'] ?? '';
    $chat    = $body['chat'] ?? [];
    $model   = $body['model'] ?? 'gpt-4o';
    $system  = $body['systemMessage'] ?? '';

    if (!$message && empty($chat)) {
        return new WP_Error('missing_input', 'Provide message or chat', ['status' => 400]);
    }

    $ai = new AI_Client_Wrapper([
        'model'         => $model,
        'systemMessage' => $system,
    ]);

    if (!empty($chat)) {
        $ai->setChat($chat);
    }

    $response = $ai->send($message ?: $chat);

    if (is_wp_error($response)) {
        return $response;
    }

    $result = rest_ensure_response([
        'response' => $response,
        'chat'     => $ai->getChat(),
    ]);
    $result->header('X-Core-AI-Engine', $ai->get_engine_label());
    return $result;
}

function ai_rest_get_threads() {
    $ai = new AI_Client_Wrapper();
    return rest_ensure_response($ai->getThreads());
}

function ai_rest_create_thread(WP_REST_Request $request) {
    $body  = $request->get_json_params();
    $title = $body['title'] ?? '';
    $model = $body['model'] ?? 'gpt-4o';
    $system = $body['systemMessage'] ?? '';

    $ai = new AI_Client_Wrapper(['model' => $model, 'systemMessage' => $system]);
    $thread_id = $ai->newThread($title);

    return rest_ensure_response([
        'thread_id' => $thread_id,
    ]);
}

function ai_rest_get_thread(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $thread = AI_Thread::get($id);

    if (!$thread) {
        return new WP_Error('not_found', 'Thread non trovato', ['status' => 404]);
    }

    return rest_ensure_response($thread);
}

function ai_rest_delete_thread(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $deleted = AI_Thread::delete($id);

    if (!$deleted) {
        return new WP_Error('not_found', 'Thread non trovato', ['status' => 404]);
    }

    return rest_ensure_response(['deleted' => true]);
}

function ai_rest_thread_send(WP_REST_Request $request) {
    $id      = (int) $request['id'];
    $body    = $request->get_json_params();
    $message = $body['message'] ?? '';

    if (!$message) {
        return new WP_Error('missing_message', 'Provide a message', ['status' => 400]);
    }

    $ai = new AI_Client_Wrapper(['model' => $body['model'] ?? 'gpt-4o']);
    $loaded = $ai->loadThread($id);

    if (is_wp_error($loaded)) {
        return $loaded;
    }

    $response = $ai->send($message);

    if (is_wp_error($response)) {
        return $response;
    }

    $result = rest_ensure_response([
        'response'  => $response,
        'thread_id' => $ai->getThreadId(),
        'chat'      => $ai->getChat(),
    ]);
    $result->header('X-Core-Ai-Engine', $ai->get_engine_label());
    return $result;
}

function ai_rest_get_logs(WP_REST_Request $request) {
    $filters = [];
    foreach (['model', 'engine', 'status', 'date_from', 'date_to'] as $key) {
        $val = $request->get_param($key);
        if ($val) $filters[$key] = $val;
    }

    $limit  = (int) ($request->get_param('per_page') ?: 50);
    $page   = (int) ($request->get_param('page') ?: 1);
    $offset = ($page - 1) * $limit;

    return rest_ensure_response(AI_Log::list($filters, $limit, $offset));
}

function ai_rest_get_stats() {
    return rest_ensure_response([
        'overview'  => AI_Log::stats(),
        'by_model'  => AI_Log::stats_by_model(),
    ]);
}

function ai_rest_get_settings() {
    return rest_ensure_response([
        'openai_key'    => get_option('ai_openai_key', ''),
        'anthropic_key' => get_option('ai_anthropic_key', ''),
        'default_model' => get_option('ai_default_model', 'gpt-4o'),
    ]);
}

function ai_rest_get_models(WP_REST_Request $request) {
    $force = (bool) $request->get_param('refresh');

    $openai_key    = get_option('ai_openai_key', '');
    $anthropic_key = get_option('ai_anthropic_key', '');

    $openai_models    = [];
    $anthropic_models = [];

    if ($openai_key) {
        $result = AI_Engine_OpenAI::list_models($openai_key, $force);
        $openai_models = is_wp_error($result) ? [] : $result;
    }

    if ($anthropic_key) {
        $result = AI_Engine_Anthropic::list_models($anthropic_key, $force);
        $anthropic_models = is_wp_error($result) ? [] : $result;
    }

    return rest_ensure_response([
        'openai'    => $openai_models,
        'anthropic' => $anthropic_models,
    ]);
}

function ai_rest_save_settings(WP_REST_Request $request) {
    $body = $request->get_json_params();

    if (isset($body['openai_key'])) {
        update_option('ai_openai_key', sanitize_text_field($body['openai_key']));
    }
    if (isset($body['anthropic_key'])) {
        update_option('ai_anthropic_key', sanitize_text_field($body['anthropic_key']));
    }
    if (isset($body['default_model'])) {
        update_option('ai_default_model', sanitize_text_field($body['default_model']));
    }

    return rest_ensure_response(['saved' => true]);
}
