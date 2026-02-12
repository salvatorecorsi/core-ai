# Core AI

**A plug-and-play WordPress module that simplifies AI service integration in your plugins.**

Core AI offers centralized API key management (OpenAI/Anthropic), detailed call logging, usage statistics, and a ready-to-use admin interface that each plugin can integrate wherever preferred: as main menu, submenu, or in WordPress settings.

## Why Core AI?

AI integration in WordPress plugins often requires implementing the same functionality over and over:
- API key management and storage
- Request/response logging for debugging
- Token usage tracking and statistics
- Error handling and retry logic
- Multi-turn conversation management

**Core AI solves this** by providing a unified, battle-tested foundation that:
- Works with multiple AI providers (OpenAI, Anthropic, with Gemini, Grok, and others coming soon)
- Auto-detects the correct engine based on model name
- Logs every call with full input/output for debugging
- Tracks token usage and response times
- Provides both PHP class API and REST endpoints
- Includes a complete admin interface out of the box
- Supports conversation threads with database persistence

Instead of rebuilding these features for every project, just drop in Core AI and focus on your plugin's unique functionality.

## Quick Start

### Basic Integration

Simply require the module in your plugin:

```php
// In your plugin's main file
require_once plugin_dir_path(__FILE__) . 'core-ai/index.php';
```

That's it! Core AI will automatically:
- Register the orphan admin page (`ai-settings`)
- Set up the Settings API
- Create database tables
- Register REST API endpoints

The page is accessible at `wp-admin/admin.php?page=ai-settings` but won't appear in any menu until you add it.

### Adding to Your Plugin's Menu

#### Option 1: As a submenu of your plugin

```php
add_action('admin_menu', function() {
    // Your plugin's main menu
    add_menu_page(
        'My Plugin',
        'My Plugin',
        'manage_options',
        'my-plugin',
        'my_plugin_page',
        'dashicons-admin-plugins'
    );
    
    // Add Core AI as a submenu
    add_submenu_page(
        'my-plugin',           // Parent slug
        'AI Settings',         // Page title
        'AI Settings',         // Menu title
        'manage_options',      // Capability
        'ai-settings'          // Menu slug (must match Core AI's slug)
    );
});
```

#### Option 2: As a main menu item

```php
add_action('admin_menu', function() {
    add_menu_page(
        'AI Settings',
        'AI Settings',
        'manage_options',
        'ai-settings',         // Same slug as Core AI
        '',                    // Empty callback (uses Core AI's)
        'dashicons-robot',
        80
    );
});
```

#### Option 3: In WordPress Settings

```php
add_action('admin_menu', function() {
    add_options_page(
        'AI Settings',
        'AI Settings',
        'manage_options',
        'ai-settings'          // Same slug as Core AI
    );
});
```

#### Option 4: Under Tools

```php
add_action('admin_menu', function() {
    add_management_page(
        'AI Settings',
        'AI Settings',
        'manage_options',
        'ai-settings'          // Same slug as Core AI
    );
});
```

## Usage

### Simple Function Call

```php
// One-shot message
$response = call_ai('What is the capital of France?');
echo $response; // "The capital of France is Paris."

// With specific model
$response = call_ai('Hello!', ['model' => 'claude-sonnet-4-20250514']);
```

### Using the AI Class

```php
// Initialize with options
$ai = new AI([
    'model' => 'gpt-4o',
    'systemMessage' => 'You are a helpful assistant specialized in WordPress.'
]);

// Send a message
$response = $ai->send('How do I create a custom post type?');

// Multi-turn conversation
$ai->send('My name is John');
$ai->send('What is my name?'); // "Your name is John."

// Get full conversation history
$chat = $ai->getChat();
```

### Conversation Threads (Database Persistence)

```php
// Create a new thread
$ai = new AI(['model' => 'gpt-4o']);
$threadId = $ai->newThread('Customer Support Chat');

// Send messages
$ai->send('I need help with my order #12345');
$ai->send('Can you check the shipping status?');

// Later, in another request...
$ai->loadThread($threadId);
$response = $ai->send('Is it shipped yet?');

// List all threads
$threads = $ai->getThreads();
foreach ($threads as $thread) {
    echo "{$thread['id']}: {$thread['title']} ({$thread['message_count']} messages)\n";
}

// Delete a thread
$ai->deleteThread($threadId);
```

### REST API

All functionality is also available via REST endpoints:

```javascript
// Send a message
const response = await fetch('/wp-json/core-ai/send', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify({
        message: 'Hello!',
        model: 'gpt-4o'
    })
});

const data = await response.json();
console.log(data.response);

// Create and use a thread
const thread = await fetch('/wp-json/core-ai/threads', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify({ title: 'My Conversation' })
}).then(r => r.json());

const msg = await fetch(`/wp-json/core-ai/threads/${thread.thread_id}/send`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify({ message: 'Hello from thread!' })
}).then(r => r.json());
```

## Supported Models

### OpenAI
- `gpt-4o` (default)
- `gpt-4o-mini`
- `gpt-4-turbo`
- `o3-mini`
- All `o1-*`, `o3-*`, `o4-*` models

### Anthropic
- `claude-sonnet-4-20250514`
- `claude-haiku-4-20250514`
- `claude-opus-4-20250514`

### Coming Soon
- Google Gemini
- xAI Grok
- Other providers

The engine is automatically detected from the model name prefix. Just specify the model and Core AI handles the rest.

## Features

### Admin Interface

The included admin page provides four tabs:

1. **Logs** - View all API calls with full details
   - Search by model
   - Filter by engine (OpenAI/Anthropic) and status (success/error)
   - Expandable rows showing full input/output
   - Sortable columns

2. **Stats** - Real-time analytics
   - Total calls, tokens, average response time
   - Success/error rates
   - Per-model breakdown with detailed metrics

3. **Settings** - Configuration
   - API key management (OpenAI, Anthropic)
   - Default model selection
   - All settings stored in `wp_options`

4. **Docs** - Built-in documentation
   - PHP usage examples
   - REST API reference
   - JavaScript examples
   - All in one place

### Logging & Debugging

Every API call is automatically logged to the database with:
- Full request and response
- Token counts (input/output/total)
- Response time
- Success/error status
- Model and engine used
- Timestamp

Perfect for debugging, monitoring costs, and analyzing usage patterns.

### Thread Management

Persistent conversation threads stored in the database:
- Create unlimited threads
- Each thread maintains its own conversation history
- Load and continue conversations across requests
- Perfect for chatbots, customer support, or any multi-turn interaction
- List, retrieve, and delete threads programmatically

## Constructor Options

```php
$ai = new AI([
    'model' => 'gpt-4o',                  // Model name (auto-detects engine)
    'apiKey' => 'sk-...',                 // Override API key (optional)
    'systemMessage' => 'You are...',      // System prompt
    'persistentMessage' => 'Always...'    // Additional context for every request
]);
```

## REST API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/wp-json/core-ai/send` | Send a message |
| GET | `/wp-json/core-ai/threads` | List all threads |
| POST | `/wp-json/core-ai/threads` | Create a new thread |
| GET | `/wp-json/core-ai/threads/{id}` | Get thread with history |
| DELETE | `/wp-json/core-ai/threads/{id}` | Delete a thread |
| POST | `/wp-json/core-ai/threads/{id}/send` | Send message in thread |
| GET | `/wp-json/core-ai/logs` | Get logs with filters |
| GET | `/wp-json/core-ai/logs/stats` | Get aggregated statistics |
| GET | `/wp-json/core-ai/settings` | Get current settings |
| POST | `/wp-json/core-ai/settings` | Update settings |

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+ or MariaDB 10.0+

## License

GPL v2 or later

## Contributing

This module is designed to be a solid foundation for AI integration in WordPress. If you'd like to contribute:
- Add support for new AI providers
- Improve error handling
- Enhance the admin interface
- Add more features

Pull requests are welcome!

**Core AI** - Making AI integration in WordPress simple, unified, and maintainable.
