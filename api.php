<?php
// ============================================================
// CRM-STARCOMP — передаточник между страницей и базой MySQL.
// Все запросы идут сюда из index.html.
// ============================================================
// Куки только по HTTPS (на локальном http-тесте HTTPS не требуется),
// недоступны для JavaScript и не отправляются с чужих сайтов.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
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

    default:
        fail('Неизвестное действие', 404);
}
