<?php
// ============================================================
// CRM-STARCOMP — передаточник между страницей и базой MySQL.
// Все запросы идут сюда из index.html.
// ============================================================
// Куки только по HTTPS (на локальном http-тесте HTTPS не требуется),
// недоступны для JavaScript и не отправляются с чужих сайтов.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$sessionLifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('starcomp_crm');
session_start();
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($message, $code = 400) {
    out(['error' => $message], $code);
}
function input() {
    static $in = null;
    if ($in === null) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $in = is_array($decoded) ? $decoded : [];
    }
    return $in;
}
function needAuth() {
    if (empty($_SESSION['user'])) fail('Не авторизован', 401);
    return $_SESSION['user'];
}
function needAdmin() {
    $u = needAuth();
    if ($u !== 'admin') fail('Недостаточно прав', 403);
    return $u;
}
function strval0($v) {
    return $v === null ? '' : (string)$v;
}

// Дебаг-логи хранятся вне публичной папки сайта: по одному файлу на день.
function debugLogDir() {
    $dir = dirname(dirname(__DIR__)) . '/crm_logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

// Файлы чеков лежат на диске вне публичной папки сайта (можно переопределить
// константой CRM_FILES_DIR в db.php). В базе — только сведения о файле.
function receiptsDir() {
    $dir = defined('CRM_FILES_DIR') ? CRM_FILES_DIR : dirname(dirname(__DIR__)) . '/crm_files';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}
function settingGet($pdo, $k) {
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function settingSet($pdo, $k, $v) {
    $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$k, $v]);
}
function itemsFromRaw($raw) {
    if ($raw === null || $raw === '') return [];
    $o = json_decode($raw, true);
    if (!is_array($o)) return [];
    if (isset($o['items']) && is_array($o['items'])) return array_values($o['items']);
    return array_values($o);
}
function itemsJson($items) {
    $json = json_encode(['items' => array_values($items)], JSON_UNESCAPED_UNICODE);
    if ($json === false) fail('Не удалось сохранить список файлов', 500);
    return $json;
}
function receiptMime($m) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    return in_array($m, $allowed, true) ? $m : 'application/octet-stream';
}
// Сохраняет файл на диск (одинаковое содержимое — один файл) и добавляет запись в $list.
function receiptSave(&$list, $dir, $bin, $f) {
    $sha = hash('sha256', $bin);
    foreach ($list as $it) {
        if (isset($it['sha256']) && $it['sha256'] === $sha) return $it;
    }
    $origName = (string)($f['origName'] ?? '');
    $ext = preg_match('/\.[a-zA-Z0-9]{1,9}$/', $origName, $m) ? strtolower($m[0]) : '';
    $created = (string)($f['created'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $created)) $created = date('Y-m-d');
    $rel = substr($created, 0, 4) . '/' . substr($created, 5, 2) . '/' . $sha . $ext;
    $path = $dir . '/' . $rel;
    if (!is_file($path)) {
        @mkdir(dirname($path), 0700, true);
        $tmp = $path . '.part' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bin) !== strlen($bin) || !rename($tmp, $path)) {
            @unlink($tmp);
            fail('Не удалось сохранить файл на диск', 500);
        }
    }
    $id = (float)($f['id'] ?? 0);
    if ($id <= 0) $id = (float)(microtime(true) * 1000) + mt_rand(0, 999999) / 1000000;
    $name = (string)($f['name'] ?? '');
    if ($name === '') $name = $origName !== '' ? $origName : 'chek' . $ext;
    $meta = [
        'id'       => $id,
        'name'     => $name,
        'origName' => $origName,
        'mime'     => receiptMime((string)($f['mime'] ?? '')),
        'size'     => strlen($bin),
        'created'  => $created,
        'sha256'   => $sha,
        'path'     => $rel,
    ];
    $list[] = $meta;
    return $meta;
}
function receiptFindById($list, $id) {
    foreach ($list as $it) {
        if (isset($it['id']) && (float)$it['id'] === (float)$id) return $it;
    }
    return null;
}

// Файлы заказа: папка orders/<номер заказа> внутри папки файлов, список — в settings.order_files.
function orderFolderName($number) {
    $safe = trim(preg_replace('/[^\p{L}\p{N}_\- .]+/u', '_', (string)$number), ' .');
    if ($safe === '') fail('Не указан номер заказа');
    return $safe;
}
function orderFileSafeName($name) {
    $safe = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/', '_', (string)$name), " .\t");
    return $safe === '' ? 'файл' : $safe;
}
function orderFileMime($ext) {
    $map = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'heic' => 'image/heic',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
    ];
    return $map[$ext] ?? null;
}
function orderFileUnique($dir, $name) {
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));
    $cand = $name;
    for ($n = 2; file_exists($dir . '/' . $cand); $n++) {
        $cand = $base . ' (' . $n . ')' . ($ext === '' ? '' : '.' . $ext);
    }
    return $cand;
}
function orderFilesList($pdo) {
    return itemsFromRaw(settingGet($pdo, 'order_files'));
}

function orderRow($pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) return null;
    $cfg = json_decode($o['config'] ?: '[]', true);
    return [
        'id'       => (int)$o['id'],
        'number'   => (string)$o['number'],
        'date'     => $o['date'],
        'name'     => (string)$o['name'],
        'phone'    => strval0($o['phone']),
        'login'    => strval0($o['login']),
        'source'   => strval0($o['source']),
        'payment'  => strval0($o['payment']),
        'ship'     => strval0($o['ship']),
        'address'  => strval0($o['address']),
        'comment'  => strval0($o['comment']),
        'config'   => is_array($cfg) ? $cfg : [],
        'status'   => (string)$o['status'],
        'archived' => (bool)$o['archived'],
        'updated_at' => isset($o['updated_at']) ? (string)$o['updated_at'] : null,
    ];
}
function partRow($pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM parts WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) return null;
    return [
        'id'       => (int)$p['id'],
        'category' => (string)$p['category'],
        'name'     => (string)$p['name'],
        'price'    => (float)$p['price'],
        'link'     => strval0($p['link']),
    ];
}

$action = $_GET['a'] ?? '';

switch ($action) {

    // ---------- ВХОД ----------
    case 'login':
        $in = input();
        $login = trim(strval0($in['login'] ?? ''));
        $pass  = strval0($in['password'] ?? '');
        if ($login === '' || $pass === '') fail('Введите логин и пароль');

        // Защита от подбора пароля: 5 неудачных попыток с одного адреса
        // за 15 минут → временная блокировка.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $pdo->exec("DELETE FROM login_attempts WHERE last_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $sa = $pdo->prepare('SELECT tries FROM login_attempts WHERE ip = ?');
        $sa->execute([$ip]);
        $tries = (int)$sa->fetchColumn();
        if ($tries >= 5) {
            fail('Слишком много неудачных попыток. Подождите 15 минут.', 429);
        }

        $aliases = ['igor' => 'igor@star-comp.ru', 'admin' => 'admin@star-comp.ru'];
        $key = strtolower($login);
        $email = $aliases[$key] ?? $login;
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? OR login = ? LIMIT 1');
        $st->execute([$email, $login]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            $sb = $pdo->prepare('INSERT INTO login_attempts (ip, tries, last_at) VALUES (?, 1, NOW())
                                 ON DUPLICATE KEY UPDATE tries = tries + 1, last_at = NOW()');
            $sb->execute([$ip]);
            fail('Неверный логин или пароль', 401);
        }
        $sd = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
        $sd->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['user'] = $u['login'];
        $_SESSION['uid']  = (int)$u['id'];
        out(['ok' => true, 'user' => $u['login']]);

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        out(['ok' => true]);

    case 'me':
        if (empty($_SESSION['user'])) fail('Не авторизован', 401);
        out(['ok' => true, 'login' => $_SESSION['user']]);

    // ---------- ЗАГРУЗКА ВСЕХ ДАННЫХ ----------
    case 'bootstrap':
        needAuth();
        // Один запрос на таблицу: при тысячах заказов так намного быстрее.
        $orders = [];
        foreach ($pdo->query('SELECT * FROM orders')->fetchAll() as $o) {
            $cfg = json_decode($o['config'] ?: '[]', true);
            $orders[] = [
                'id'       => (int)$o['id'],
                'number'   => (string)$o['number'],
                'date'     => $o['date'],
                'name'     => (string)$o['name'],
                'phone'    => strval0($o['phone']),
                'login'    => strval0($o['login']),
                'source'   => strval0($o['source']),
                'payment'  => strval0($o['payment']),
                'ship'     => strval0($o['ship']),
                'address'  => strval0($o['address']),
                'comment'  => strval0($o['comment']),
                'config'   => is_array($cfg) ? $cfg : [],
                'status'   => (string)$o['status'],
                'archived' => (bool)$o['archived'],
                'updated_at' => isset($o['updated_at']) ? (string)$o['updated_at'] : null,
            ];
        }
        $parts = [];
        foreach ($pdo->query('SELECT * FROM parts')->fetchAll() as $p) {
            $parts[] = [
                'id'       => (int)$p['id'],
                'category' => (string)$p['category'],
                'name'     => (string)$p['name'],
                'price'    => (float)$p['price'],
                'link'     => strval0($p['link']),
            ];
        }
        $settings = [];
        foreach ($pdo->query('SELECT k, v FROM settings')->fetchAll() as $r) {
            $settings[$r['k']] = $r['v'];
        }
        out(['ok' => true, 'orders' => $orders, 'parts' => $parts,
             'settings' => $settings, 'user' => $_SESSION['user']]);

    // ---------- ЗАКАЗЫ ----------
    case 'order_save':
        needAuth();
        $in = input();
        $id = !empty($in['id']) ? (int)$in['id'] : 0;
        $date = trim(strval0($in['date'] ?? ''));
        if ($date === '') $date = null;
        $number   = trim(strval0($in['number'] ?? ''));
        $name     = trim(strval0($in['name'] ?? ''));
        if ($number === '' || $name === '') fail('Не заполнен номер или имя заказчика');
        $phone    = trim(strval0($in['phone'] ?? ''));
        $login    = trim(strval0($in['login'] ?? ''));
        $source   = trim(strval0($in['source'] ?? ''));
        $payment  = trim(strval0($in['payment'] ?? ''));
        $ship     = trim(strval0($in['ship'] ?? ''));
        $address  = trim(strval0($in['address'] ?? ''));
        $comment  = strval0($in['comment'] ?? '');
        $config   = json_encode($in['config'] ?? [], JSON_UNESCAPED_UNICODE);
        $status   = strval0($in['status'] ?? 'Согласование');
        if ($status === '') $status = 'Согласование';
        $archived = !empty($in['archived']) ? 1 : 0;
        $expectedUpdated = $in['expected_updated_at'] ?? null;
        if ($expectedUpdated !== null && $expectedUpdated !== '') $expectedUpdated = strval0($expectedUpdated);
        else $expectedUpdated = null;

        if ($id) {
            if ($expectedUpdated !== null) {
                $st = $pdo->prepare('UPDATE orders SET number=?, date=?, name=?, phone=?, login=?, source=?, payment=?, ship=?, address=?, comment=?, config=?, status=?, archived=?, updated_at=NOW() WHERE id=? AND (updated_at IS NULL OR updated_at = ?)');
                $st->execute([$number, $date, $name, $phone, $login, $source, $payment, $ship, $address, $comment, $config, $status, $archived, $id, $expectedUpdated]);
                if ($st->rowCount() === 0) {
                    $cur = orderRow($pdo, $id);
                    if (!$cur) fail('Заказ не найден', 404);
                    out(['error' => 'conflict', 'message' => 'Заказ был изменён другим пользователем. Обновите данные (закройте и откройте заказ) и повторите сохранение.', 'order' => $cur], 409);
                }
            } else {
                $st = $pdo->prepare('UPDATE orders SET number=?, date=?, name=?, phone=?, login=?, source=?, payment=?, ship=?, address=?, comment=?, config=?, status=?, archived=?, updated_at=NOW() WHERE id=?');
                $st->execute([$number, $date, $name, $phone, $login, $source, $payment, $ship, $address, $comment, $config, $status, $archived, $id]);
            }
        } else {
            $st = $pdo->prepare('INSERT INTO orders (number, date, name, phone, login, source, payment, ship, address, comment, config, status, archived, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())');
            $st->execute([$number, $date, $name, $phone, $login, $source, $payment, $ship, $address, $comment, $config, $status, $archived]);
            $id = (int)$pdo->lastInsertId();
        }
        out(['ok' => true, 'order' => orderRow($pdo, $id)]);

    case 'order_status':
        needAuth();
        $in = input();
        $id = (int)($in['id'] ?? 0);
        $status = strval0($in['status'] ?? '');
        $archived = !empty($in['archived']) ? 1 : 0;
        if (!$id || $status === '') fail('Неверные данные');
        $st = $pdo->prepare('UPDATE orders SET status = ?, archived = ? WHERE id = ?');
        $st->execute([$status, $archived, $id]);
        out(['ok' => true]);

    // ---------- ЖЕЛЕЗО ----------
    case 'part_save':
        needAuth();
        $in = input();
        $id = !empty($in['id']) ? (int)$in['id'] : 0;
        $category = trim(strval0($in['category'] ?? ''));
        $name = trim(strval0($in['name'] ?? ''));
        if ($category === '' || $name === '') fail('Заполните категорию и название');
        $price = (float)($in['price'] ?? 0);
        $link = trim(strval0($in['link'] ?? ''));
        if ($id) {
            $st = $pdo->prepare('UPDATE parts SET category=?, name=?, price=?, link=? WHERE id=?');
            $st->execute([$category, $name, $price, $link, $id]);
        } else {
            $st = $pdo->prepare('INSERT INTO parts (category, name, price, link) VALUES (?,?,?,?)');
            $st->execute([$category, $name, $price, $link]);
            $id = (int)$pdo->lastInsertId();
        }
        out(['ok' => true, 'part' => partRow($pdo, $id)]);

    // ---------- ИМПОРТ ЖЕЛЕЗА ----------
    // Принимаем список деталей и добавляем только те, которых ещё нет.
    // duplicate = совпадение названия (без учёта регистра/порядка слов/знаков)
    // или одной и той же ссылки. Повторный импорт ничего не меняет.
    case 'parts_import':
        needAdmin();
        $in = input();
        $rows = $in['parts'] ?? [];
        if (!is_array($rows)) fail('Некорректный список деталей');
        $dry = !empty($in['dry_run']);

        $normName = function ($s) {
            $s = strval0($s);
            $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
            $s = preg_replace('/[^a-zа-яё0-9+]+/ui', ' ', $s);
            $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
            if (!$words) return '';
            sort($words, SORT_STRING);
            return implode(' ', $words);
        };

        $byName = [];
        $byLink = [];
        foreach ($pdo->query('SELECT name, link FROM parts')->fetchAll() as $p) {
            $nk = $normName($p['name']);
            if ($nk !== '') $byName[$nk] = true;
            $lk = trim(strval0($p['link']));
            if ($lk !== '') $byLink[$lk] = true;
        }

        $added = 0;
        $skipped = 0;
        $sample = [];
        $ins = $pdo->prepare('INSERT INTO parts (category, name, price, link) VALUES (?,?,?,?)');
        foreach ($rows as $r) {
            if (!is_array($r)) { $skipped++; continue; }
            $category = trim(strval0($r['category'] ?? ''));
            $name = trim(strval0($r['name'] ?? ''));
            if ($category === '' || $name === '') { $skipped++; continue; }
            $price = (float)($r['price'] ?? 0);
            $link = trim(strval0($r['link'] ?? ''));
            $nk = $normName($name);
            $lk = trim($link);
            if (($nk !== '' && isset($byName[$nk])) || ($lk !== '' && isset($byLink[$lk]))) {
                $skipped++;
                continue;
            }
            if (!$dry) {
                $ins->execute([$category, $name, $price, $link]);
            }
            if ($nk !== '') $byName[$nk] = true;
            if ($lk !== '') $byLink[$lk] = true;
            $added++;
            if (count($sample) < 15) {
                $sample[] = ['category' => $category, 'name' => $name, 'price' => $price, 'link' => $link];
            }
        }
        out(['ok' => true, 'added' => $added, 'skipped' => $skipped,
             'dry_run' => $dry, 'sample' => $sample]);

    case 'part_delete':
        needAuth();
        $id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
        if (!$id) {
            $in = input();
            $id = (int)($in['id'] ?? 0);
        }
        if (!$id) fail('Не указана деталь');
        $st = $pdo->prepare('DELETE FROM parts WHERE id = ?');
        $st->execute([$id]);
        out(['ok' => true]);

    // ---------- НАСТРОЙКИ ----------
    case 'settings_set':
        needAuth();
        $in = input();
        $k = strval0($in['k'] ?? '');
        $v = strval0($in['v'] ?? '');
        if ($k === '') fail('Не указан ключ');
        $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute([$k, $v]);
        out(['ok' => true]);

    // ---------- ДЕБАГ-ЛОГ ----------
    case 'debug_append':
        needAuth();
        $in = input();
        $text = strval0($in['text'] ?? '');
        if ($text !== '') {
            $dir = debugLogDir();
            $file = $dir . '/' . date('Y-m-d') . '.log';
            if (file_exists($file) && filesize($file) > 5 * 1024 * 1024) {
                $tail = substr(file_get_contents($file), -2 * 1024 * 1024);
                @file_put_contents($file, "[старые записи обрезаны]\n" . $tail, LOCK_EX);
            }
            @file_put_contents($file, $text, FILE_APPEND | LOCK_EX);
        }
        out(['ok' => true]);

    case 'debug_read':
        $user = needAuth();
        if ($user !== 'admin') fail('Недостаточно прав', 403);
        $in = input();
        $day = preg_replace('/[^0-9-]/', '', strval0($in['day'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');
        $dir = debugLogDir();
        $file = $dir . '/' . $day . '.log';
        $text = file_exists($file) ? strval0(file_get_contents($file)) : '';
        $days = [];
        foreach (glob($dir . '/????-??-??.log') as $f) {
            $days[] = basename($f, '.log');
        }
        rsort($days);
        out(['ok' => true, 'day' => $day, 'days' => $days, 'text' => $text]);

    // ---------- РАСХОДЫ ----------
    case 'expenses_list':
        needAdmin();
        $rows = [];
        $q = $pdo->query('SELECT id, dt, category, amount, note, created_at FROM expenses ORDER BY dt DESC, id DESC');
        foreach ($q->fetchAll() as $r) {
            $rows[] = [
                'id' => (int)$r['id'],
                'dt' => (string)$r['dt'],
                'category' => (string)$r['category'],
                'amount' => (float)$r['amount'],
                'note' => strval0($r['note']),
                'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : null,
            ];
        }
        out(['ok' => true, 'expenses' => $rows]);

    case 'expenses_add':
        needAdmin();
        $in = input();
        $dt = trim(strval0($in['dt'] ?? ''));
        $category = trim(strval0($in['category'] ?? ''));
        $amount = (float)($in['amount'] ?? 0);
        $note = trim(strval0($in['note'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) fail('Укажите дату');
        if ($category === '') fail('Укажите категорию');
        if ($amount <= 0) fail('Укажите сумму');
        $st = $pdo->prepare('INSERT INTO expenses (dt, category, amount, note) VALUES (?,?,?,?)');
        $st->execute([$dt, $category, $amount, $note]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT id, dt, category, amount, note, created_at FROM expenses WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        out(['ok' => true, 'expense' => [
            'id' => (int)$r['id'],
            'dt' => (string)$r['dt'],
            'category' => (string)$r['category'],
            'amount' => (float)$r['amount'],
            'note' => strval0($r['note']),
            'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : null,
        ]]);

    case 'expenses_update':
        needAdmin();
        $in = input();
        $id = (int)($in['id'] ?? 0);
        if (!$id) fail('Не указан id');
        $dt = trim(strval0($in['dt'] ?? ''));
        $category = trim(strval0($in['category'] ?? ''));
        $amount = (float)($in['amount'] ?? 0);
        $note = trim(strval0($in['note'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) fail('Укажите дату');
        if ($category === '') fail('Укажите категорию');
        if ($amount <= 0) fail('Укажите сумму');
        $st = $pdo->prepare('UPDATE expenses SET dt=?, category=?, amount=?, note=? WHERE id=?');
        $st->execute([$dt, $category, $amount, $note, $id]);
        $st = $pdo->prepare('SELECT id, dt, category, amount, note, created_at FROM expenses WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) fail('Не найдено', 404);
        out(['ok' => true, 'expense' => [
            'id' => (int)$r['id'],
            'dt' => (string)$r['dt'],
            'category' => (string)$r['category'],
            'amount' => (float)$r['amount'],
            'note' => strval0($r['note']),
            'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : null,
        ]]);

    case 'expenses_delete':
        needAdmin();
        $in = input();
        $id = (int)($in['id'] ?? 0);
        if (!$id) fail('Не указан id');
        $st = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
        $st->execute([$id]);
        out(['ok' => true]);

    // ---------- ЧЕКИ И ФАЙЛЫ СКЛАДА (только admin) ----------
    case 'receipt_upload':
        needAdmin();
        $in = input();
        $bin = base64_decode(strval0($in['data'] ?? ''), true);
        if ($bin === false || $bin === '') fail('Файл пустой или повреждён');
        if (strlen($bin) > 2 * 1024 * 1024) fail('Файл больше 2 МБ');
        $dir = receiptsDir();
        $list = itemsFromRaw(settingGet($pdo, 'stock_receipts'));
        $meta = receiptSave($list, $dir, $bin, [
            'id'       => $in['id'] ?? 0,
            'name'     => strval0($in['name'] ?? ''),
            'origName' => strval0($in['origName'] ?? ''),
            'mime'     => strval0($in['mime'] ?? ''),
            'created'  => strval0($in['created'] ?? ''),
        ]);
        settingSet($pdo, 'stock_receipts', itemsJson($list));
        out(['ok' => true, 'receipt' => $meta]);

    case 'receipt_file':
        needAdmin();
        $list = itemsFromRaw(settingGet($pdo, 'stock_receipts'));
        $meta = receiptFindById($list, $_GET['id'] ?? 0);
        if (!$meta || empty($meta['path'])) fail('Файл не найден', 404);
        $path = receiptsDir() . '/' . $meta['path'];
        if (!is_file($path)) fail('Файл не найден на диске', 404);
        $asciiName = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', (string)$meta['name']);
        header('Content-Type: ' . $meta['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode((string)$meta['name']));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;

    case 'receipts_migrate':
        // Однократный перенос: файлы, которые раньше лежали base64 в базе, уходят на диск.
        needAdmin();
        $rawR = settingGet($pdo, 'stock_receipts');
        $rawS = settingGet($pdo, 'stock_items');
        $receipts = itemsFromRaw($rawR);
        $stock = itemsFromRaw($rawS);
        $legacy = false;
        foreach ($receipts as $r) if (isset($r['data'])) $legacy = true;
        foreach ($stock as $s) if (!empty($s['files'])) $legacy = true;
        if (!$legacy) out(['ok' => true, 'migrated' => 0]);

        $dir = receiptsDir();
        @mkdir($dir . '/backup', 0700, true);
        $backup = $dir . '/backup/' . date('Ymd_His') . '_settings.json';
        if (file_put_contents($backup, json_encode(['stock_receipts' => $rawR, 'stock_items' => $rawS], JSON_UNESCAPED_UNICODE)) === false) {
            fail('Не удалось создать резервную копию перед переносом', 500);
        }

        $list = [];
        $pairs = [];
        $count = 0;
        foreach ($receipts as $r) {
            if (!isset($r['data'])) { $list[] = $r; continue; }
            $bin = base64_decode((string)$r['data'], true);
            if ($bin === false) fail('Повреждённый чек: ' . ($r['name'] ?? ''), 500);
            $meta = receiptSave($list, $dir, $bin, $r);
            $pairs[] = [(float)$r['id'], (float)$meta['id']];
            $count++;
        }
        foreach ($stock as &$s) {
            $ids = [];
            foreach (($s['receiptIds'] ?? []) as $rid) {
                $new = (float)$rid;
                foreach ($pairs as $p) if ($p[0] === (float)$rid) $new = $p[1];
                if (!in_array($new, $ids, true)) $ids[] = $new;
            }
            if (!empty($s['files'])) {
                foreach ($s['files'] as $f) {
                    if (empty($f['data'])) continue;
                    $bin = base64_decode((string)$f['data'], true);
                    if ($bin === false) fail('Повреждённый файл в позиции склада', 500);
                    $meta = receiptSave($list, $dir, $bin, [
                        'name'     => $f['name'] ?? '',
                        'origName' => $f['origName'] ?? ($f['name'] ?? ''),
                        'mime'     => $f['mime'] ?? '',
                        'created'  => $s['date'] ?? '',
                    ]);
                    if (!in_array((float)$meta['id'], $ids, true)) $ids[] = (float)$meta['id'];
                    $count++;
                }
                $s['files'] = [];
            }
            $s['receiptIds'] = $ids;
        }
        unset($s);

        settingSet($pdo, 'stock_receipts', itemsJson($list));
        settingSet($pdo, 'stock_items', itemsJson($stock));
        out(['ok' => true, 'migrated' => $count]);

    // ---------- ФАЙЛЫ ЗАКАЗА ----------
    case 'order_files_list':
        needAuth();
        $in = input();
        $number = trim(strval0($in['number'] ?? ''));
        $rows = [];
        if ($number !== '') {
            foreach (orderFilesList($pdo) as $x) {
                if (($x['number'] ?? '') === $number) $rows[] = $x;
            }
        }
        out(['ok' => true, 'files' => $rows]);

    case 'order_file_upload':
        needAuth();
        $number = trim(strval0($_POST['number'] ?? ''));
        $folder = orderFolderName($number);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail('Файл не загрузился (возможно, он больше допустимого размера)');
        }
        if ($f['size'] > 10 * 1024 * 1024) fail('Файл больше 10 МБ');
        $name = orderFileSafeName($f['name']);
        $mime = orderFileMime(strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        if ($mime === null) fail('Этот тип файла не поддерживается');
        $dir = receiptsDir() . '/orders/' . $folder;
        @mkdir($dir, 0700, true);
        $name = orderFileUnique($dir, $name);
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) fail('Не удалось сохранить файл на диск', 500);
        $items = orderFilesList($pdo);
        $item = [
            'id'      => bin2hex(random_bytes(8)),
            'number'  => $number,
            'name'    => $name,
            'mime'    => $mime,
            'size'    => (int)$f['size'],
            'created' => date('Y-m-d'),
            'path'    => 'orders/' . $folder . '/' . $name,
        ];
        $items[] = $item;
        settingSet($pdo, 'order_files', itemsJson($items));
        out(['ok' => true, 'file' => $item]);

    case 'order_file':
        needAuth();
        $id = (string)($_GET['id'] ?? '');
        $it = null;
        foreach (orderFilesList($pdo) as $x) {
            if (($x['id'] ?? '') === $id) $it = $x;
        }
        if (!$it) fail('Файл не найден', 404);
        $path = receiptsDir() . '/' . $it['path'];
        if (!is_file($path)) fail('Файл не найден на диске', 404);
        $asciiName = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', (string)$it['name']);
        header('Content-Type: ' . $it['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode((string)$it['name']));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;

    case 'order_file_delete':
        needAuth();
        $in = input();
        $id = strval0($in['id'] ?? '');
        $keep = [];
        $gone = null;
        foreach (orderFilesList($pdo) as $x) {
            if (($x['id'] ?? '') === $id) $gone = $x;
            else $keep[] = $x;
        }
        if (!$gone) fail('Файл не найден', 404);
        @unlink(receiptsDir() . '/' . $gone['path']);
        settingSet($pdo, 'order_files', itemsJson($keep));
        out(['ok' => true]);

    case 'order_files_move':
        // Номер заказа изменили — файлы переезжают в папку с новым номером.
        needAuth();
        $in = input();
        $from = trim(strval0($in['from'] ?? ''));
        $to = trim(strval0($in['to'] ?? ''));
        if ($from === '' || $to === '' || $from === $to) out(['ok' => true, 'moved' => 0]);
        $base = receiptsDir() . '/orders';
        $fromDir = $base . '/' . orderFolderName($from);
        $toDir = $base . '/' . orderFolderName($to);
        @mkdir($toDir, 0700, true);
        $items = orderFilesList($pdo);
        $moved = 0;
        foreach ($items as &$x) {
            if (($x['number'] ?? '') !== $from) continue;
            $src = receiptsDir() . '/' . $x['path'];
            $name = orderFileUnique($toDir, $x['name']);
            if (is_file($src) && !rename($src, $toDir . '/' . $name)) fail('Не удалось перенести файл «' . $x['name'] . '»', 500);
            $x['number'] = $to;
            $x['name'] = $name;
            $x['path'] = 'orders/' . orderFolderName($to) . '/' . $name;
            $moved++;
        }
        unset($x);
        if ($moved) settingSet($pdo, 'order_files', itemsJson($items));
        if (is_dir($fromDir) && count(scandir($fromDir)) === 2) @rmdir($fromDir);
        out(['ok' => true, 'moved' => $moved]);

    default:
        fail('Неизвестное действие', 404);
}
