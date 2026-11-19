<?php
// ============================================================
// CRM-STARCOMP — передаточник между страницей и базой MySQL.
// Все запросы идут сюда из index.html.
// ============================================================
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
function strval0($v) {
    return $v === null ? '' : (string)$v;
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
        $aliases = ['igor' => 'igor@star-comp.ru', 'admin' => 'admin@star-comp.ru'];
        $key = strtolower($login);
        $email = $aliases[$key] ?? $login;
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? OR login = ? LIMIT 1');
        $st->execute([$email, $login]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            fail('Неверный логин или пароль', 401);
        }
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

        if ($id) {
            $st = $pdo->prepare('UPDATE orders SET number=?, date=?, name=?, phone=?, login=?, source=?, payment=?, ship=?, address=?, comment=?, config=?, status=?, archived=? WHERE id=?');
            $st->execute([$number, $date, $name, $phone, $login, $source, $payment, $ship, $address, $comment, $config, $status, $archived, $id]);
        } else {
            $st = $pdo->prepare('INSERT INTO orders (number, date, name, phone, login, source, payment, ship, address, comment, config, status, archived) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
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

    default:
        fail('Неизвестное действие', 404);
}
