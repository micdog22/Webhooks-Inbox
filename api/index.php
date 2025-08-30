<?php
declare(strict_types=1);
session_start();

/**
 * MicDog Webhooks Inbox - API
 */

header_remove('X-Powered-By');

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function text_response(string $text, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): void {
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    echo $text;
    exit;
}

function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

function route_path(): string {
    $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $path = '/' . ltrim(substr($uri, strlen($base)), '/');
    $path = preg_replace('#/index\.php#', '', $path, 1);
    return $path === '' ? '/' : $path;
}

function query(string $k, ?string $default = null): ?string {
    return isset($_GET[$k]) && $_GET[$k] !== '' ? (string)$_GET[$k] : $default;
}

function parse_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    return is_array($data) ? $data : [];
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'inbox.sqlite';
    $needInit = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($needInit) init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
        PRAGMA journal_mode = WAL;
        CREATE TABLE IF NOT EXISTS channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id INTEGER NOT NULL,
            method TEXT NOT NULL,
            path TEXT,
            query TEXT,
            headers TEXT,
            body TEXT,
            ip TEXT,
            ua TEXT,
            content_type TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_events_channel ON events(channel_id);
        CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at);
    ");
}

// CSRF for admin ops
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function require_csrf(): void {
    if (method() === 'GET') return;
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$hdr || !hash_equals($_SESSION['csrf'], $hdr)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
}

function gen_token(int $len = 24): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    for ($i=0; $i<$len; $i++) $s .= $alphabet[random_int(0, strlen($alphabet)-1)];
    return $s;
}

$path = route_path();
$method = method();

try {
    if ($path === '/' || $path === '') {
        json_response(['ok' => true, 'service' => 'MicDog Webhooks Inbox API']);
    }

    if ($path === '/csrf' && $method === 'GET') {
        json_response(['token' => $_SESSION['csrf']]);
    }

    // Channels
    if ($path === '/channels') {
        $pdo = get_db();
        if ($method === 'GET') {
            $q = query('q');
            $sql = 'SELECT id, name, token, created_at FROM channels';
            $params = [];
            if ($q) {
                $sql .= ' WHERE name LIKE ? OR token LIKE ?';
                $like = '%' . $q . '%';
                $params = [$like, $like];
            }
            $sql .= ' ORDER BY created_at DESC, id DESC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            json_response(['items' => $st->fetchAll()]);
        }
        if ($method === 'POST') {
            require_csrf();
            $d = parse_json();
            $name = trim((string)($d['name'] ?? ''));
            $token = trim((string)($d['token'] ?? ''));
            if ($name === '') json_response(['errors' => ['name' => 'Required']], 422);
            if ($token !== '' && !preg_match('/^[a-zA-Z0-9]{12,64}$/', $token)) {
                json_response(['errors' => ['token' => 'Token must be 12-64 alphanumerics']], 422);
            }
            if ($token === '') {
                do {
                    $token = gen_token(24);
                    $ck = $pdo->prepare('SELECT 1 FROM channels WHERE token = ?');
                    $ck->execute([$token]);
                } while ($ck->fetchColumn());
            } else {
                $ck = $pdo->prepare('SELECT 1 FROM channels WHERE token = ?');
                $ck->execute([$token]);
                if ($ck->fetchColumn()) json_response(['errors' => ['token' => 'Token already in use']], 422);
            }
            $st = $pdo->prepare('INSERT INTO channels (name, token) VALUES (?, ?)');
            $st->execute([$name, $token]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->query('SELECT id, name, token, created_at FROM channels WHERE id = '.$id)->fetch();
            json_response(['item' => $row], 201);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/channels/(\d+)$#', $path, $m)) {
        $pdo = get_db();
        $id = (int)$m[1];
        if ($method === 'DELETE') {
            require_csrf();
            $st = $pdo->prepare('DELETE FROM channels WHERE id = ?');
            $st->execute([$id]);
            json_response(['deleted' => $id]);
        }
        if ($method === 'GET') {
            $row = $pdo->query('SELECT id, name, token, created_at FROM channels WHERE id = '.$id)->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    // Events listing
    if ($path === '/events' && $method === 'GET') {
        $pdo = get_db();
        $where = [];
        $params = [];
        if ($cid = query('channel_id')) { $where[] = 'channel_id = ?'; $params[] = (int)$cid; }
        if ($from = query('from')) { $where[] = 'created_at >= ?'; $params[] = $from; }
        if ($to = query('to')) { $where[] = 'created_at <= ?'; $params[] = $to; }
        if ($q = query('q')) {
            $where[] = '(method LIKE ? OR path LIKE ? OR body LIKE ? OR headers LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = 'SELECT e.id, e.channel_id, c.name AS channel_name, e.method, e.path, e.created_at, e.content_type FROM events e JOIN channels c ON c.id=e.channel_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY e.id DESC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_response(['items' => $st->fetchAll()]);
    }

    if (preg_match('#^/events/(\d+)$#', $path, $m)) {
        $pdo = get_db();
        $id = (int)$m[1];
        if ($method === 'GET') {
            $st = $pdo->prepare('SELECT e.*, c.name AS channel_name, c.token AS channel_token FROM events e JOIN channels c ON c.id=e.channel_id WHERE e.id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }
        if ($method === 'DELETE') {
            require_csrf();
            $st = $pdo->prepare('DELETE FROM events WHERE id = ?');
            $st->execute([$id]);
            json_response(['deleted' => $id]);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/events/(\d+)/replay$#', $path, $m) && $method === 'POST') {
        require_csrf();
        $pdo = get_db();
        $id = (int)$m[1];
        $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $ev = $st->fetch();
        if (!$ev) json_response(['error' => 'Not found'], 404);

        $d = parse_json();
        $url = trim((string)($d['url'] ?? ''));
        $methodReplay = strtoupper(trim((string)($d['method'] ?? $ev['method'])));
        $headers = $d['headers'] ?? [];
        $bodyMode = $d['bodyMode'] ?? 'original';
        $rawBody = (string)($d['rawBody'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL)) json_response(['errors' => ['url' => 'Invalid URL']], 422);

        $bodyToSend = ($bodyMode === 'raw') ? $rawBody : (string)$ev['body'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodReplay);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $hdrs = [];
            if (is_array($headers)) {
                foreach ($headers as $k => $v) { $hdrs[] = $k.': '.$v; }
            }
            if (!empty($ev['content_type']) && !array_key_exists('Content-Type', $headers)) {
                $hdrs[] = 'Content-Type: '.$ev['content_type'];
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
            if (!in_array($methodReplay, ['GET','HEAD'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyToSend);
            }
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($resp === false) json_response(['error' => 'cURL error', 'message' => $err], 500);
            $respHeaders = substr($resp, 0, $headerSize);
            $respBody = substr($resp, $headerSize);
            json_response(['status' => $status, 'responseHeaders' => $respHeaders, 'responseBody' => $respBody]);
        } else {
            $opts = ['http' => ['method' => $methodReplay, 'ignore_errors' => true]];
            if (!in_array($methodReplay, ['GET','HEAD'])) $opts['http']['content'] = $bodyToSend;
            $context = stream_context_create($opts);
            $resp = @file_get_contents($url, false, $context);
            $statusLine = $http_response_header[0] ?? 'HTTP/1.1 200 OK';
            json_response(['statusLine' => $statusLine, 'responseBody' => $resp]);
        }
    }

    // Export CSV
    if ($path === '/export/events' && $method === 'GET') {
        $pdo = get_db();
        $where = [];
        $params = [];
        if ($cid = query('channel_id')) { $where[] = 'channel_id = ?'; $params[] = (int)$cid; }
        if ($from = query('from')) { $where[] = 'created_at >= ?'; $params[] = $from; }
        if ($to = query('to')) { $where[] = 'created_at <= ?'; $params[] = $to; }
        if ($q = query('q')) {
            $where[] = '(method LIKE ? OR path LIKE ? OR body LIKE ? OR headers LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = 'SELECT e.id, c.name AS channel, e.created_at, e.method, e.path, e.content_type, e.ip, e.ua, e.query, e.headers, e.body
                FROM events e JOIN channels c ON c.id=e.channel_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY e.id DESC';

        $st = $pdo->prepare($sql);
        $st->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="events.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','channel','created_at','method','path','content_type','ip','ua','query','headers','body']);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    // Incoming webhook - public
    if (preg_match('#^/incoming/([a-zA-Z0-9]{12,64})$#', $path, $m)) {
        $pdo = get_db();
        $token = $m[1];

        $st = $pdo->prepare('SELECT id FROM channels WHERE token = ?');
        $st->execute([$token]);
        $channelId = $st->fetchColumn();
        if (!$channelId) json_response(['error' => 'Unknown token'], 404);

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', substr($k, 5));
                $headers[$name] = $v;
            }
        }

        $body = file_get_contents('php://input') ?: '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? ($headers['CONTENT-TYPE'] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $pathOnly = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $queryArr = $_GET;
        // remove routing noise: token already parsed, keep raw query without token
        $queryStr = http_build_query($queryArr);

        $ins = $pdo->prepare('INSERT INTO events (channel_id, method, path, query, headers, body, ip, ua, content_type) VALUES (?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            (int)$channelId,
            method(),
            $pathOnly,
            json_encode($queryArr, JSON_UNESCAPED_UNICODE),
            json_encode($headers, JSON_UNESCAPED_UNICODE),
            $body,
            $ip,
            $ua,
            $contentType
        ]);
        $id = (int)$pdo->lastInsertId();
        json_response(['ok' => true, 'id' => $id]);
    }

    // Stats simple
    if ($path === '/stats' && $method === 'GET') {
        $pdo = get_db();
        $totChannels = (int)$pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
        $totEvents = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

        $series = $pdo->query("
            WITH days AS (
                SELECT date('now','-13 day') AS d
                UNION ALL
                SELECT date(d,'+1 day') FROM days WHERE d < date('now')
            )
            SELECT d AS day, COALESCE( (SELECT COUNT(*) FROM events WHERE substr(created_at,1,10)=d), 0 ) AS events
            FROM days;
        ")->fetchAll();

        json_response(['totalChannels' => $totChannels, 'totalEvents' => $totEvents, 'series' => $series]);
    }

    json_response(['error' => 'Not found', 'path' => $path], 404);
} catch (Throwable $e) {
    json_response(['error' => 'Server error', 'message' => $e->getMessage()], 500);
}
