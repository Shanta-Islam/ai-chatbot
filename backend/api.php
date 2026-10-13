<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$origin = envValue('CORS_ORIGIN', 'http://localhost:5173');
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        respond(['error' => 'Invalid JSON request body.'], 400);
    }
    return $data;
}



function callAi(array $history): string {
    $apiKey = envValue('AI_API_KEY');
    $model = envValue('AI_MODEL', 'openai/gpt-oss-120b');

    if ($apiKey === '' || $apiKey === 'replace_with_your_api_key') {
        throw new RuntimeException(
            'Groq API is not configured. Add AI_API_KEY to backend/.env.'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }

    $messages = array_merge(
        [[
            'role' => 'system',
            'content' => 'You are a helpful, clear, and friendly AI assistant. If the user writes in Bangla, reply in Bangla.'
        ]],
        $history
    );

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 1024,
    ];

    $jsonPayload = json_encode($payload);

    if ($jsonPayload === false) {
        throw new RuntimeException('Could not encode AI request.');
    }

    $ch = curl_init(
        'https://api.groq.com/openai/v1/chat/completions'
    );

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException(
            'Could not reach Groq: ' . $curlError
        );
    }

    $decoded = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $message = $decoded['error']['message']
            ?? ('Groq returned HTTP ' . $status);

        throw new RuntimeException((string) $message);
    }

    $content = $decoded['choices'][0]['message']['content'] ?? null;

    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException(
            'Groq returned an empty response.'
        );
    }

    return trim($content);
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'conversations') {
        $stmt = $pdo->query('SELECT id, title, created_at, updated_at FROM conversations ORDER BY updated_at DESC LIMIT 50');
        respond(['conversations' => $stmt->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'messages') {
        $conversationId = filter_input(INPUT_GET, 'conversation_id', FILTER_VALIDATE_INT);
        if (!$conversationId || $conversationId < 1) {
            respond(['error' => 'A valid conversation_id is required.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, role, content, created_at FROM messages WHERE conversation_id = ? ORDER BY id ASC');
        $stmt->execute([$conversationId]);
        respond(['messages' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'chat') {
        $data = requestJson();
        $message = trim((string) ($data['message'] ?? ''));
        $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : 0;

        if ($message === '') {
            respond(['error' => 'Message cannot be empty.'], 422);
        }
        if (mb_strlen($message) > 8000) {
            respond(['error' => 'Message is too long (maximum 8000 characters).'], 422);
        }

        if ($conversationId > 0) {
            $check = $pdo->prepare('SELECT id FROM conversations WHERE id = ?');
            $check->execute([$conversationId]);
            if (!$check->fetch()) {
                respond(['error' => 'Conversation not found.'], 404);
            }
        } else {
            $title = mb_substr($message, 0, 70);
            $stmt = $pdo->prepare('INSERT INTO conversations (title) VALUES (?)');
            $stmt->execute([$title ?: 'New chat']);
            $conversationId = (int) $pdo->lastInsertId();
        }

        $pdo->beginTransaction();
        try {
            $insertUser = $pdo->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "user", ?)');
            $insertUser->execute([$conversationId, $message]);

            // Keep recent context only, to control request size.
            $historyStmt = $pdo->prepare('SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 20');
            $historyStmt->execute([$conversationId]);
            $history = array_reverse($historyStmt->fetchAll());

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            $reply = callAi($history);
        } catch (Throwable $e) {
            respond([
                'error' => 'AI request failed: ' . $e->getMessage(),
                'conversation_id' => $conversationId,
            ], 502);
        }

        $insertAssistant = $pdo->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "assistant", ?)');
        $insertAssistant->execute([$conversationId, $reply]);
        $update = $pdo->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$conversationId]);

        respond([
            'conversation_id' => $conversationId,
            'reply' => $reply,
        ]);
    }

    respond(['error' => 'Unknown endpoint. Use ?action=conversations, ?action=messages, or POST ?action=chat.'], 404);
} catch (Throwable $e) {
    error_log((string) $e);
    respond(['error' => 'Server error. Check PHP configuration and database settings.'], 500);
}
