<?php

declare(strict_types=1);

function auth_handle(array $context): void
{
    $method = $context['method'];
    $segments = $context['segments'];
    $pdo = $context['pdo'];
    $config = $context['config'];

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $action = $segments[1] ?? '';
    switch ($action) {
        case 'register':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $payload = get_json_body();
            $username = trim((string) ($payload['username'] ?? ''));
            $email = trim((string) ($payload['email'] ?? ''));
            $password = (string) ($payload['password'] ?? '');
            $langPref = (string) ($payload['lang_pref'] ?? 'en');

            if ($username === '' || $email === '' || $password === '') {
                json_response(['error' => 'Missing required fields'], 422);
            }

            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, lang_pref) VALUES (:username, :email, :password_hash, :lang_pref)');
            try {
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':lang_pref' => $langPref,
                ]);
            } catch (PDOException $exception) {
                json_response(['error' => 'User registration failed', 'detail' => $exception->getMessage()], 409);
            }

            $userId = (int) $pdo->lastInsertId();
            $token = jwt_sign(['sub' => $userId, 'email' => $email, 'lang' => $langPref], $config['jwt']);
            json_response(['token' => $token, 'user_id' => $userId], 201);
            break;

        case 'login':
            if ($method !== 'POST') {
                json_response(['error' => 'Method not allowed'], 405);
            }
            $payload = get_json_body();
            $email = trim((string) ($payload['email'] ?? ''));
            $password = (string) ($payload['password'] ?? '');

            $stmt = $pdo->prepare('SELECT id, password_hash, lang_pref FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($password, $user['password_hash'])) {
                json_response(['error' => 'Invalid credentials'], 401);
            }

            $token = jwt_sign(['sub' => (int) $user['id'], 'email' => $email, 'lang' => $user['lang_pref']], $config['jwt']);
            json_response(['token' => $token, 'user_id' => (int) $user['id']], 200);
            break;

        case 'me':
            $identity = require_auth($config['jwt']);
            json_response(['user' => $identity], 200);
            break;

        default:
            json_response(['error' => 'Unknown auth action'], 404);
    }
}

function require_auth(array $jwtConfig): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
        json_response(['error' => 'Authorization required'], 401);
    }

    $token = trim($matches[1]);
    $payload = jwt_verify($token, $jwtConfig);
    if (!$payload) {
        json_response(['error' => 'Invalid or expired token'], 401);
    }

    return $payload;
}

function get_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function jwt_sign(array $payload, array $config): string
{
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $issuedAt = time();
    $payload = array_merge($payload, [
        'iss' => $config['issuer'],
        'iat' => $issuedAt,
        'exp' => $issuedAt + $config['ttl_seconds'],
    ]);

    $encodedHeader = base64url_encode(json_encode($header));
    $encodedPayload = base64url_encode(json_encode($payload));
    $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $config['secret'], true);

    return $encodedHeader . '.' . $encodedPayload . '.' . base64url_encode($signature);
}

function jwt_verify(string $token, array $config): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $expectedSignature = base64url_encode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $config['secret'], true));
    if (!hash_equals($expectedSignature, $encodedSignature)) {
        return null;
    }

    $payload = json_decode(base64url_decode($encodedPayload), true);
    if (!is_array($payload)) {
        return null;
    }

    if (($payload['iss'] ?? '') !== $config['issuer']) {
        return null;
    }

    if (($payload['exp'] ?? 0) < time()) {
        return null;
    }

    return $payload;
}
