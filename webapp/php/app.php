<?php

use Slim\Http\Request;
use Slim\Http\Response;
use Torb\PDOWrapper;
use Psr\Container\ContainerInterface;
use SlimSession\Helper;

date_default_timezone_set('Asia/Tokyo');
define('TWIG_TEMPLATE', realpath(__DIR__).'/views');

$container = $app->getContainer();

$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig(TWIG_TEMPLATE);

    $baseUrl = function (\Slim\Http\Request $request): string {
      if ($request->hasHeader('Host')) {
        return $request->getUri()->getScheme().'://'.$request->getHeaderLine('Host');
      }

      return $request->getUri()->getBaseUrl();
    };

    $view->addExtension(new \Slim\Views\TwigExtension($container['router'], $baseUrl($container['request'])));

    return $view;
};

$app->add(new \Slim\Middleware\Session([
    'name' => 'torb_session',
    'autorefresh' => true,
    'lifetime' => '1 week',
]));

$container['session'] = function (): Helper {
    return new Helper();
};

$login_required = function (Request $request, Response $response, callable $next): Response {
    $user = get_login_user($this);
    if (!$user) {
        return res_error($response, 'login_required', 401);
    }

    return $next($request, $response);
};

$fillin_user = function (Request $request, Response $response, callable $next): Response {
    $user = get_login_user($this);
    if ($user) {
        $this->view->offsetSet('user', $user);
    }

    return $next($request, $response);
};

$container['dbh'] = function (): PDOWrapper {
    $database = getenv('DB_DATABASE');
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASS');

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4;";
    $pdo = new PDO(
        $dsn,
        $user,
        $password,
        [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    return new PDOWrapper($pdo);
};

$app->get('/', function (Request $request, Response $response): Response {
    $events = array_map(function (array $event) {
        return sanitize_event($event);
    }, get_events($this->dbh, true));

    return $this->view->render($response, 'index.twig', [
        'events' => $events,
    ]);
})->add($fillin_user);

$app->get('/initialize', function (Request $request, Response $response): Response {
    exec('../../db/init.sh');

    return $response->withStatus(204);
});

$app->post('/api/users', function (Request $request, Response $response): Response {
    $nickname = $request->getParsedBodyParam('nickname');
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $user_id = null;

    $this->dbh->beginTransaction();

    try {
        $duplicated = $this->dbh->select_one('SELECT id FROM users WHERE login_name = ?', $login_name);
        if ($duplicated) {
            $this->dbh->rollback();

            return res_error($response, 'duplicated', 409);
        }

        $this->dbh->execute('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, SHA2(?, 256), ?)', $login_name, $password, $nickname);
        $user_id = $this->dbh->last_insert_id();
        $this->dbh->commit();
    } catch (\Throwable $throwable) {
        $this->dbh->rollback();

        return res_error($response);
    }

    return $response->withJson([
        'id' => $user_id,
        'nickname' => $nickname,
    ], 201, JSON_NUMERIC_CHECK);
});

/**
 * @param ContainerInterface $app
 *
 * @return bool|array
 */
function get_login_user(ContainerInterface $app)
{
    /** @var Helper $session */
    $session = $app->session;
    $user_id = $session->get('user_id');
    if (null === $user_id) {
        return false;
    }

    $user = $app->dbh->select_row('SELECT id, nickname FROM users WHERE id = ?', $user_id);
    $user['id'] = (int) $user['id'];
    return $user;
}

$app->get('/api/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user = $this->dbh->select_row('SELECT id, nickname FROM users WHERE id = ?', $args['id']);
    $user['id'] = (int) $user['id'];
    if (!$user || $user['id'] !== get_login_user($this)['id']) {
        return res_error($response, 'forbidden', 403);
    }

    $recent_reservations = function (ContainerInterface $app) use ($user) {
        $recent_reservations = [];

        $rows = $app->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY IFNULL(r.canceled_at, r.reserved_at) DESC LIMIT 5', $user['id']);
        foreach ($rows as $row) {
            $event = get_event($app->dbh, $row['event_id']);
            $price = $event['sheets'][$row['sheet_rank']]['price'];
            unset($event['sheets']);
            unset($event['total']);
            unset($event['remains']);

            $reservation = [
                'id' => $row['id'],
                'event' => $event,
                'sheet_rank' => $row['sheet_rank'],
                'sheet_num' => $row['sheet_num'],
                'price' => $price,
                'reserved_at' => (new \DateTime("{$row['reserved_at']}", new DateTimeZone('UTC')))->getTimestamp(),
            ];

            if ($row['canceled_at']) {
                $reservation['canceled_at'] = (new \DateTime("{$row['canceled_at']}", new DateTimeZone('UTC')))->getTimestamp();
            }

            array_push($recent_reservations, $reservation);
        }

        return $recent_reservations;
    };

    $user['recent_reservations'] = $recent_reservations($this);
    $user['total_price'] = $this->dbh->select_one('SELECT IFNULL(SUM(e.price + s.price), 0) FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', $user['id']);

    $recent_events = function (ContainerInterface $app) use ($user) {
        $recent_events = [];

        $rows = $app->dbh->select_all('SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', $user['id']);
        foreach ($rows as $row) {
            $event = get_event($app->dbh, $row['event_id']);
            foreach (array_keys($event['sheets']) as $rank) {
                unset($event['sheets'][$rank]['detail']);
            }
            array_push($recent_events, $event);
        }

        return $recent_events;
    };

    $user['recent_events'] = $recent_events($this);

    return $response->withJson($user, null, JSON_NUMERIC_CHECK);
})->add($login_required);

$app->post('/api/actions/login', function (Request $request, Response $response): Response {
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $user = $this->dbh->select_row('SELECT id, pass_hash FROM users WHERE login_name = ?', $login_name);
    $pass_hash = $this->dbh->select_one('SELECT SHA2(?, 256)', $password);

    if (!$user || $pass_hash != $user['pass_hash']) {
        return res_error($response, 'authentication_failed', 401);
    }

    /** @var Helper $session */
    $session = $this->session;
    $session->set('user_id', $user['id']);

    $user = get_login_user($this);

    return $response->withJson($user, null, JSON_NUMERIC_CHECK);
});

$app->post('/api/actions/logout', function (Request $request, Response $response): Response {
    /** @var Helper $session */
    $session = $this->session;
    $session->delete('user_id');

    return $response->withStatus(204);
})->add($login_required);

$app->get('/api/events', function (Request $request, Response $response): Response {
    $events = array_map(function (array $event) {
        return sanitize_event($event);
    }, get_events($this->dbh, true));

    return $response->withJson($events, null, JSON_NUMERIC_CHECK);
});

$app->get('/api/events/{id}', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'not_found', 404);
    }

    $event = sanitize_event($event);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
});

function get_events(PDOWrapper $dbh, bool $public = false): array
{
    $query = 'SELECT id FROM events';
    if ($public) {
        $query .= ' WHERE public_fg = 1';
    }
    $query .= ' ORDER BY id ASC';
    $dbh->beginTransaction();

    $events = [];
    $event_ids = array_map(function (array $event) {
        return $event['id'];
    }, $dbh->select_all($query));

    foreach ($event_ids as $event_id) {
        $event = get_event($dbh, $event_id);

        foreach (array_keys($event['sheets']) as $rank) {
            unset($event['sheets'][$rank]['detail']);
        }

        array_push($events, $event);
    }

    $dbh->commit();

    return $events;
}

function get_event(PDOWrapper $dbh, int $event_id, ?int $login_user_id = null): array
{
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['id'] = (int) $event['id'];

    // zero fill
    $event['total'] = 1000;
    $event['remains'] = 0;

    foreach (['S', 'A', 'B', 'C'] as $rank) {
        $event['sheets'][$rank]['total'] = 0;
        $event['sheets'][$rank]['remains'] = 0;
    }
    $tsheets = [
        'S' => [ 'num' => 50, 'price' => 5000, 'start_id' => 1 ],
        'A' => [ 'num' => 150, 'price' => 3000, 'start_id' => 51 ],
        'B' => [ 'num' => 300, 'price' => 1000, 'start_id' => 201 ],
        'C' => [ 'num' => 500, 'price' => 0, 'start_id' => 501 ]
    ];

    foreach ($tsheets as $rank => $sinfo) {
        $sheet_id = $sinfo['start_id'];
        $sheet_num = $sinfo['num'];
        $sheet_price = $sinfo['price'];

        $reservations_select = $dbh->select_all('SELECT * FROM reservations WHERE event_id = ? AND sheet_id >= ? AND sheet_id < ? AND canceled_at IS NULL GROUP BY event_id, sheet_id HAVING reserved_at = MIN(reserved_at)', $event['id'], $sinfo['start_id'], $sinfo['start_id'] + $sinfo['num']);
        $reservations = array();
        foreach($reservations_select as $r) {
             $reservations[(int)($r['sheet_id'])] = $r;
        }

        $event['sheets'][$rank]['total'] = $sinfo['num'];

        $j = 1;
        for ($i = $sinfo['start_id']; $i < $sinfo['start_id'] + $sinfo['num']; ++$i) {
            $sheet['num'] = $j++;

            $event['sheets'][$rank]['price'] = $event['sheets'][$rank]['price'] ?? $event['price'] + $sheet_price;

            if (array_key_exists($i, $reservations)) {
                $sheet['mine'] = $login_user_id && $reservations[$i]['user_id'] == $login_user_id;
                $sheet['reserved'] = true;
                $sheet['reserved_at'] = (new \DateTime("{$reservations[$i]['reserved_at']}", new DateTimeZone('UTC')))->getTimestamp();
            } else {
                ++$event['remains'];
                ++$event['sheets'][$rank]['remains'];
            }

            if (false === isset($event['sheets'][$rank]['detail'])) {
                $event['sheets'][$rank]['detail'] = [];
            }

            array_push($event['sheets'][$rank]['detail'], $sheet);
        }
    }

    $event['public'] = $event['public_fg'] ? true : false;
    $event['closed'] = $event['closed_fg'] ? true : false;

    unset($event['public_fg']);
    unset($event['closed_fg']);

    return $event;
}

function sanitize_event(array $event): array
{
    unset($event['price']);
    unset($event['public']);
    unset($event['closed']);

    return $event;
}

$app->post('/api/events/{id}/actions/reserve', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $rank = $request->getParsedBodyParam('sheet_rank');

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'invalid_event', 404);
    }

    if (!validate_rank($this->dbh, $rank)) {
        return res_error($response, 'invalid_rank', 400);
    }

    $sheet = null;
    $reservation_id = null;
    while (true) {
        $sheet = $this->dbh->select_row('SELECT id, num FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', $event['id'], $rank);
        if (!$sheet) {
            return res_error($response, 'sold_out', 409);
        }

        $this->dbh->beginTransaction();
        try {
            $this->dbh->execute('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at) VALUES (?, ?, ?, ?)', $event['id'], $sheet['id'], $user['id'], (new DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'));
            $reservation_id = (int) $this->dbh->last_insert_id();

            $this->dbh->commit();
        } catch (\Exception $e) {
            $this->dbh->rollback();
            continue;
        }

        break;
    }

    return $response->withJson([
        'id' => $reservation_id,
        'sheet_rank' => $rank,
        'sheet_num' => $sheet['num'],
    ], 202, JSON_NUMERIC_CHECK);
})->add($login_required);

$app->delete('/api/events/{id}/sheets/{ranks}/{num}/reservation', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $rank = $args['ranks'];
    $num = $args['num'];

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'invalid_event', 404);
    }

    if (!validate_rank($this->dbh, $rank)) {
        return res_error($response, 'invalid_rank', 404);
    }

    $sheet = $this->dbh->select_row('SELECT id FROM sheets WHERE `rank` = ? AND num = ?', $rank, $num);
    if (!$sheet) {
        return res_error($response, 'invalid_sheet', 404);
    }

    $this->dbh->beginTransaction();
    try {
        $reservation = $this->dbh->select_row('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', $event['id'], $sheet['id']);
        if (!$reservation) {
            $this->dbh->rollback();

            return res_error($response, 'not_reserved', 400);
        }

        if ($reservation['user_id'] != $user['id']) {
            $this->dbh->rollback();

            return res_error($response, 'not_permitted', 403);
        }

        $this->dbh->execute('UPDATE reservations SET canceled_at = ? WHERE id = ?', (new DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation['id']);
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();

        return res_error($response);
    }

    return $response->withStatus(204);
})->add($login_required);

function validate_rank(PDOWrapper $dbh, $rank)
{
    return $dbh->select_one('SELECT COUNT(*) FROM sheets WHERE `rank` = ?', $rank);
}

$admin_login_required = function (Request $request, Response $response, callable $next): Response {
    $administrator = get_login_administrator($this);
    if (!$administrator) {
        return res_error($response, 'admin_login_required', 401);
    }

    return $next($request, $response);
};

$fillin_administrator = function (Request $request, Response $response, callable $next): Response {
    $administrator = get_login_administrator($this);
    if ($administrator) {
        $this->view->offsetSet('administrator', $administrator);
    }

    return $next($request, $response);
};

$app->get('/admin/', function (Request $request, Response $response) {
    $events = get_events($this->dbh, false);

    return $this->view->render($response, 'admin.twig', [
        'events' => $events,
    ]);
})->add($fillin_administrator);

$app->post('/admin/api/actions/login', function (Request $request, Response $response): Response {
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $administrator = $this->dbh->select_row('SELECT * FROM administrators WHERE login_name = ?', $login_name);
    $pass_hash = $this->dbh->select_one('SELECT SHA2(?, 256)', $password);

    if (!$administrator || $pass_hash != $administrator['pass_hash']) {
        return res_error($response, 'authentication_failed', 401);
    }

    /** @var Helper $session */
    $session = $this->session;
    $session->set('administrator_id', $administrator['id']);

    return $response->withJson($administrator, null, JSON_NUMERIC_CHECK);
});

$app->post('/admin/api/actions/logout', function (Request $request, Response $response): Response {
    /** @var Helper $session */
    $session = $this->session;
    $session->delete('administrator_id');

    return $response->withStatus(204);
})->add($admin_login_required);

/**
 * @param ContainerInterface $app*
 *
 * @return bool|array
 */
function get_login_administrator(ContainerInterface $app)
{
    /** @var Helper $session */
    $session = $app->session;
    $administrator_id = $session->get('administrator_id');
    if (null === $administrator_id) {
        return false;
    }

    $administrator = $app->dbh->select_row('SELECT id, nickname FROM administrators WHERE id = ?', $administrator_id);
    $administrator['id'] = (int) $administrator['id'];
    return $administrator;
}

$app->get('/admin/api/events', function (Request $request, Response $response): Response {
    $events = get_events($this->dbh, false);

    return $response->withJson($events, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->post('/admin/api/events', function (Request $request, Response $response): Response {
    $title = $request->getParsedBodyParam('title');
    $public = $request->getParsedBodyParam('public') ? 1 : 0;
    $price = $request->getParsedBodyParam('price');

    $event_id = null;

    $this->dbh->beginTransaction();
    try {
        $this->dbh->execute('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', $title, $public, $price);
        $event_id = $this->dbh->last_insert_id();
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();
    }

    $event = get_event($this->dbh, $event_id);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->get('/admin/api/events/{id}', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $event = get_event($this->dbh, $event_id);
    if (empty($event)) {
        return res_error($response, 'not_found', 404);
    }

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->post('/admin/api/events/{id}/actions/edit', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $public = $request->getParsedBodyParam('public') ? 1 : 0;
    $closed = $request->getParsedBodyParam('closed') ? 1 : 0;

    if ($closed) {
        $public = 0;
    }

    $event = get_event($this->dbh, $event_id);
    if (empty($event)) {
        return res_error($response, 'not_found', 404);
    }

    if ($event['closed']) {
        return res_error($response, 'cannot_edit_closed_event', 400);
    } elseif ($event['public'] && $closed) {
        return res_error($response, 'cannot_close_public_event', 400);
    }

    $this->dbh->beginTransaction();
    try {
        $this->dbh->execute('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', $public, $closed, $event['id']);
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();
    }

    $event = get_event($this->dbh, $event_id);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->get('/admin/api/reports/events/{id}/sales', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
    $reports = array_map(function ($row) {
            return $row['csv'];
        }, $this->dbh->select_all(
                "SELECT CONCAT_WS(',',
                        r.id,
                        r.event_id,
                        s.rank,
                        s.num,
                        IFNULL((s.price + e.price), 0),
                        r.user_id,
                        IFNULL(DATE_FORMAT(r.reserved_at, '%Y-%m-%dT%H:%i:%S.%fZ'), ''),
                        IFNULL(DATE_FORMAT(r.canceled_at, '%Y-%m-%dT%H:%i:%S.%fZ'), '')) AS csv
                 FROM reservations r
                 INNER JOIN sheets s ON s.id = r.sheet_id
                 INNER JOIN events e ON e.id = r.event_id
                 WHERE r.event_id = ?
                 ORDER BY reserved_at ASC FOR UPDATE", $event_id));

    $body = implode(',', $keys);
    $body .= "\n";
    $body .= implode("\n", $reports);

    return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
        ->write($body);
});

$app->get('/admin/api/reports/sales', function (Request $request, Response $response): Response {
    $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
    $reports = array_map(function ($row) {
            return $row['csv'];
        }, $this->dbh->select_all(
                "SELECT CONCAT_WS(',',
                        r.id,
                        r.event_id,
                        s.rank,
                        s.num,
                        IFNULL((s.price + e.price), 0),
                        r.user_id,
                        IFNULL(DATE_FORMAT(r.reserved_at, '%Y-%m-%dT%H:%i:%S.%fZ'), ''),
                        IFNULL(DATE_FORMAT(r.canceled_at, '%Y-%m-%dT%H:%i:%S.%fZ'), '')) AS csv
                 FROM reservations r
                 INNER JOIN sheets s ON s.id = r.sheet_id
                 INNER JOIN events e ON e.id = r.event_id
                 ORDER BY reserved_at ASC FOR UPDATE"));

    $body = implode(',', $keys);
    $body .= "\n";
    $body .= implode("\n", $reports);

    return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
        ->write($body);
})->add($admin_login_required);

function render_report_csv(Response $response, array $reports): Response
{
    usort($reports, function ($a, $b) { return $a['sold_at'] > $b['sold_at']; });

    $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
    $body = implode(',', $keys);
    $body .= "\n";
    foreach ($reports as $report) {
        $data = [];
        foreach ($keys as $key) {
            $data[] = $report[$key];
        }
        $body .= implode(',', $data);
        $body .= "\n";
    }

    return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
        ->write($body);
}

function res_error(Response $response, string $error = 'unknown', int $status = 500): Response
{
    return $response->withStatus($status)
        ->withHeader('Content-type', 'application/json')
        ->withJson(['error' => $error]);
}
