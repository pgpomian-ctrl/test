<?php

declare(strict_types=1);

function engine_handle(array $context): void
{
    $method = $context['method'];
    $segments = $context['segments'];
    $pdo = $context['pdo'];
    $config = $context['config'];

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $resource = $segments[0] ?? '';
    switch ($resource) {
        case 'guide':
            guide_handle($context);
            break;
        case 'garage':
            garage_handle($context);
            break;
        case 'lessons':
            lessons_handle($context);
            break;
        case 'academy':
            academy_handle($context);
            break;
        case 'voice':
            voice_handle($context);
            break;
        default:
            json_response(['error' => 'Unknown endpoint'], 404);
    }
}

function guide_handle(array $context): void
{
    $method = $context['method'];
    $segments = $context['segments'];
    $pdo = $context['pdo'];

    $lang = $_GET['lang'] ?? 'en';
    $guideId = isset($segments[1]) ? (int) $segments[1] : null;

    if ($method === 'GET' && $guideId === null) {
        $stmt = $pdo->query('SELECT id, category, difficulty, tool_list, content FROM guides_multilang ORDER BY id DESC');
        $guides = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $guides[] = guide_localize($row, $lang);
        }
        json_response(['data' => $guides]);
    }

    if ($method === 'GET' && $guideId !== null) {
        $stmt = $pdo->prepare('SELECT id, category, difficulty, tool_list, content FROM guides_multilang WHERE id = :id');
        $stmt->execute([':id' => $guideId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['error' => 'Guide not found'], 404);
        }
        json_response(['data' => guide_localize($row, $lang)]);
    }

    if ($method === 'POST' && $guideId !== null && ($segments[2] ?? '') === 'comments') {
        $payload = get_json_body();
        $identity = require_auth($context['config']['jwt']);
        $stepId = (int) ($payload['step_id'] ?? 0);
        $content = trim((string) ($payload['content'] ?? ''));
        $photoUrl = trim((string) ($payload['photo_url'] ?? ''));

        if ($stepId <= 0 || $content === '') {
            json_response(['error' => 'Invalid comment payload'], 422);
        }

        $stmt = $pdo->prepare('INSERT INTO comments (guide_id, step_id, user_id, content, photo_url) VALUES (:guide_id, :step_id, :user_id, :content, :photo_url)');
        $stmt->execute([
            ':guide_id' => $guideId,
            ':step_id' => $stepId,
            ':user_id' => (int) $identity['sub'],
            ':content' => $content,
            ':photo_url' => $photoUrl !== '' ? $photoUrl : null,
        ]);

        json_response(['status' => 'comment_created'], 201);
    }

    json_response(['error' => 'Unsupported guide operation'], 405);
}

function guide_localize(array $row, string $lang): array
{
    $toolList = json_decode($row['tool_list'], true) ?: [];
    $content = json_decode($row['content'], true) ?: [];
    $localized = $content[$lang] ?? $content['en'] ?? [];

    return [
        'id' => (int) $row['id'],
        'category' => $row['category'],
        'difficulty' => $row['difficulty'],
        'tool_list' => $toolList,
        'content' => $localized,
    ];
}

function garage_handle(array $context): void
{
    $method = $context['method'];
    $segments = $context['segments'];
    $pdo = $context['pdo'];
    $identity = require_auth($context['config']['jwt']);

    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, brand, model, specs, service_history FROM guitars WHERE user_id = :user_id');
        $stmt->execute([':user_id' => (int) $identity['sub']]);
        $guitars = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $guitars[] = [
                'id' => (int) $row['id'],
                'brand' => $row['brand'],
                'model' => $row['model'],
                'specs' => json_decode($row['specs'], true) ?: [],
                'service_history' => json_decode($row['service_history'], true) ?: [],
            ];
        }
        json_response(['data' => $guitars]);
    }

    if ($method === 'POST') {
        $payload = get_json_body();
        $stmt = $pdo->prepare('INSERT INTO guitars (user_id, brand, model, specs, service_history) VALUES (:user_id, :brand, :model, :specs, :service_history)');
        $stmt->execute([
            ':user_id' => (int) $identity['sub'],
            ':brand' => (string) ($payload['brand'] ?? ''),
            ':model' => (string) ($payload['model'] ?? ''),
            ':specs' => json_encode($payload['specs'] ?? new stdClass()),
            ':service_history' => json_encode($payload['service_history'] ?? []),
        ]);
        json_response(['status' => 'guitar_added'], 201);
    }

    if ($method === 'PUT' && isset($segments[1])) {
        $payload = get_json_body();
        $guitarId = (int) $segments[1];
        $stmt = $pdo->prepare('UPDATE guitars SET brand = :brand, model = :model, specs = :specs, service_history = :service_history WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':brand' => (string) ($payload['brand'] ?? ''),
            ':model' => (string) ($payload['model'] ?? ''),
            ':specs' => json_encode($payload['specs'] ?? new stdClass()),
            ':service_history' => json_encode($payload['service_history'] ?? []),
            ':id' => $guitarId,
            ':user_id' => (int) $identity['sub'],
        ]);
        json_response(['status' => 'guitar_updated']);
    }

    if ($method === 'DELETE' && isset($segments[1])) {
        $guitarId = (int) $segments[1];
        $stmt = $pdo->prepare('DELETE FROM guitars WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':id' => $guitarId,
            ':user_id' => (int) $identity['sub'],
        ]);
        json_response(['status' => 'guitar_deleted']);
    }

    json_response(['error' => 'Unsupported garage operation'], 405);
}

function voice_handle(array $context): void
{
    $method = $context['method'];
    $pdo = $context['pdo'];

    if ($method !== 'GET') {
        json_response(['error' => 'Method not allowed'], 405);
    }

    $guideId = (int) ($_GET['guide_id'] ?? 0);
    $step = (int) ($_GET['step'] ?? 0);
    $lang = $_GET['lang'] ?? 'en';

    $stmt = $pdo->prepare('SELECT content FROM guides_multilang WHERE id = :id');
    $stmt->execute([':id' => $guideId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['error' => 'Guide not found'], 404);
    }

    $content = json_decode($row['content'], true) ?: [];
    $steps = $content[$lang]['steps'] ?? $content['en']['steps'] ?? [];
    $nextStep = $steps[$step] ?? null;

    if (!$nextStep) {
        json_response(['status' => 'complete', 'next' => null]);
    }

    json_response(['status' => 'ok', 'next' => $nextStep]);
}

function lessons_handle(array $context): void
{
    $method = $context['method'];
    $segments = $context['segments'];
    $pdo = $context['pdo'];
    $lang = $_GET['lang'] ?? 'en';
    $lessonType = $_GET['type'] ?? null;

    if ($method !== 'GET') {
        json_response(['error' => 'Method not allowed'], 405);
    }

    $lessonId = isset($segments[1]) ? (int) $segments[1] : null;

    if ($lessonId) {
        $stmt = $pdo->prepare('SELECT id, lesson_type, level, title, summary, duration_minutes, content FROM lessons WHERE id = :id');
        $stmt->execute([':id' => $lessonId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['error' => 'Lesson not found'], 404);
        }
        json_response(['data' => lesson_localize($row, $lang)]);
    }

    if ($lessonType) {
        $stmt = $pdo->prepare('SELECT id, lesson_type, level, title, summary, duration_minutes, content FROM lessons WHERE lesson_type = :lesson_type ORDER BY id DESC');
        $stmt->execute([':lesson_type' => $lessonType]);
    } else {
        $stmt = $pdo->query('SELECT id, lesson_type, level, title, summary, duration_minutes, content FROM lessons ORDER BY id DESC');
    }

    $lessons = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lessons[] = lesson_localize($row, $lang);
    }

    json_response(['data' => $lessons]);
}

function lesson_localize(array $row, string $lang): array
{
    $content = json_decode($row['content'], true) ?: [];
    $localized = $content[$lang] ?? $content['en'] ?? [];

    return [
        'id' => (int) $row['id'],
        'type' => $row['lesson_type'],
        'level' => $row['level'],
        'title' => $row['title'],
        'summary' => $row['summary'],
        'duration_minutes' => (int) $row['duration_minutes'],
        'content' => $localized,
    ];
}

function academy_handle(array $context): void
{
    $method = $context['method'];
    $segments = $context['segments'];
    $pdo = $context['pdo'];

    if ($method === 'GET') {
        $trackId = isset($segments[1]) ? (int) $segments[1] : null;
        if ($trackId) {
            $stmt = $pdo->prepare('SELECT id, level, title, summary, modules FROM lesson_tracks WHERE id = :id');
            $stmt->execute([':id' => $trackId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['error' => 'Track not found'], 404);
            }
            json_response(['data' => track_format($row)]);
        }

        $stmt = $pdo->query('SELECT id, level, title, summary, modules FROM lesson_tracks ORDER BY id DESC');
        $tracks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tracks[] = track_format($row);
        }
        json_response(['data' => $tracks]);
    }

    if ($method === 'POST' && ($segments[1] ?? '') === 'enroll') {
        $identity = require_auth($context['config']['jwt']);
        $payload = get_json_body();
        $trackId = (int) ($payload['track_id'] ?? 0);
        if ($trackId <= 0) {
            json_response(['error' => 'Invalid track'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO enrollments (user_id, track_id, progress) VALUES (:user_id, :track_id, :progress)');
        $stmt->execute([
            ':user_id' => (int) $identity['sub'],
            ':track_id' => $trackId,
            ':progress' => json_encode(['completed' => [], 'status' => 'active']),
        ]);
        json_response(['status' => 'enrolled'], 201);
    }

    json_response(['error' => 'Unsupported academy operation'], 405);
}

function track_format(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'level' => $row['level'],
        'title' => $row['title'],
        'summary' => $row['summary'],
        'modules' => json_decode($row['modules'], true) ?: [],
    ];
}
