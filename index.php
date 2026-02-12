<?php

require_once __DIR__ . '/includes/engines/class-engine.php';
require_once __DIR__ . '/includes/engines/class-openai.php';
require_once __DIR__ . '/includes/engines/class-anthropic.php';
require_once __DIR__ . '/includes/class-ai-log.php';
require_once __DIR__ . '/includes/class-ai-thread.php';
require_once __DIR__ . '/includes/class-ai.php';
require_once __DIR__ . '/includes/class-ai-client-wrapper.php';
require_once __DIR__ . '/includes/class-core-ai-abilities.php';
require_once __DIR__ . '/includes/rest-api.php';
require_once __DIR__ . '/admin/admin.php';

register_activation_hook(
    dirname(__DIR__, 2) . '/plugin.php',
    ['AI_Thread', 'install']
);

// Run migrations for existing installs (adds cost column if missing)
add_action('admin_init', ['AI_Log', 'maybe_add_cost_column']);

function call_ai($input, array $args = []) {
    $ai = new AI_Client_Wrapper($args);

    if (is_array($input)) {
        $last = array_pop($input);
        $ai->setChat($input);
        return $ai->send($last['content'] ?? '');
    }

    return $ai->send($input);
}
