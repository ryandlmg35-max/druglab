<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

function view(string $name, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/app/views/' . $name . '.php';
    return (string)ob_get_clean();
}

function render(string $viewName, array $vars = [], string $active = '', ?string $title = null): void
{
    $content = view($viewName, $vars);
    $title = $title ?? (config('site_name') . ' - ' . config('tagline'));
    include __DIR__ . '/app/views/layout.php';
}

function not_found(string $message = 'Página não encontrada.'): void
{
    http_response_code(404);
    render('404', ['message' => $message], '', '404');
    exit;
}

$page = isset($_GET['page']) ? (string)$_GET['page'] : 'index';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($page) {

    case 'index':
        $cat = max(0, (int)($_GET['cat'] ?? 0));
        $all = products_all();
        $products = $cat > 0 ? array_values(array_filter($all, fn($p) => (int)$p['category'] === $cat)) : $all;
        $catName = 'All Products';
        foreach (categories_all() as $c) {
            if ((int)$c['id'] === $cat) {
                $catName = $c['name'];
                break;
            }
        }
        render('home', ['products' => $products, 'cat' => $cat, 'catName' => $catName], 'index');
        break;

    case 'product':
        $p = product_find((int)($_GET['id'] ?? 0));
        if (!$p || !(int)$p['active']) {
            not_found('Produto não encontrado.');
        }
        render('product', ['product' => $p], '', $p['name'] . ' - ' . config('site_name'));
        break;

    case 'cart':
        if ($method === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if (isset($_POST['addproduct'])) {
                $pid = (int)$_POST['addproduct'];
                $qty = max(1, min(10000, (int)($_POST['qty'] ?? 1)));
                $p = product_find($pid);
                if ($p && (int)$p['active']) {
                    $current = cart()[$pid] ?? 0;
                    cart_set($pid, $current + $qty);
                    flash('Produto adicionado ao carrinho.');
                }
            } elseif ($action === 'update' && is_array($_POST['qty'] ?? null)) {
                foreach ($_POST['qty'] as $pid => $qty) {
                    cart_set((int)$pid, (int)$qty);
                }
                flash('Carrinho atualizado.');
            } elseif ($action === 'remove') {
                cart_set((int)($_POST['pid'] ?? 0), 0);
                flash('Produto removido.');
            } elseif ($action === 'clear') {
                $_SESSION['cart'] = [];
                flash('Carrinho esvaziado.');
            }
            redirect('?page=cart');
        }
        render('cart', ['cart' => cart_lines()], 'cart');
        break;

    case 'checkout':
        $user = require_login();
        $cart = cart_lines();
        if (!$cart['lines']) {
            flash('Seu carrinho está vazio.', 'warning');
            redirect('?page=cart');
        }
        $min = (float)config('min_order');
        if ($cart['subtotal'] < $min) {
            flash('Pedido mínimo: ' . money($min) . '.', 'warning');
            redirect('?page=cart');
        }

        if ($method === 'POST') {
            csrf_verify();
            $currency = (string)($_POST['currency'] ?? '');
            $wallets = config('wallets');
            if (!isset($wallets[$currency])) {
                flash('Moeda de pagamento inválida.', 'error');
                redirect('?page=checkout');
            }
            $fields = ['full_name', 'address', 'city', 'zip', 'country'];
            $ship = [];
            foreach ($fields as $f) {
                $v = trim((string)($_POST[$f] ?? ''));
                if ($v === '') {
                    flash('Preencha todos os campos de entrega.', 'error');
                    redirect('?page=checkout');
                }
                $ship[$f] = mb_substr($v, 0, 200);
            }
            $ship['notes'] = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);

            $rates = rates();
            $rate = (float)($rates[$currency] ?? 0);
            if ($rate <= 0) {
                flash('Cotação indisponível para esta moeda. Tente outra.', 'error');
                redirect('?page=checkout');
            }
            $total = $cart['total'];
            $amount = format_coin($total / $rate, (int)$wallets[$currency]['decimals']);

            $items = [];
            foreach ($cart['lines'] as $l) {
                $items[] = [
                    'id' => (int)$l['product']['id'],
                    'name' => $l['product']['name'],
                    'qty' => (int)$l['qty'],
                    'unit' => $l['unit'],
                    'subtotal' => $l['subtotal'],
                ];
            }

            $shipText = "Name: {$ship['full_name']}\nAddress: {$ship['address']}\nCity: {$ship['city']}\nZIP: {$ship['zip']}\nCountry: {$ship['country']}\nNotes: {$ship['notes']}";

            $code = new_order_code();
            $stmt = db()->prepare('INSERT INTO orders (code, user_id, items, total_usd, currency, crypto_amount, rate_usd, address, ship_enc, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $code,
                (int)$user['id'],
                json_encode($items, JSON_UNESCAPED_UNICODE),
                $total,
                $currency,
                $amount,
                $rate,
                $wallets[$currency]['address'],
                encrypt_ship($shipText),
                'pending_payment',
                date('Y-m-d H:i:s'),
            ]);

            $_SESSION['cart'] = [];
            redirect('?page=payment&code=' . $code);
        }

        render('checkout', ['cart' => $cart, 'rates' => rates()], 'cart');
        break;

    case 'payment':
        $user = require_login();
        $order = order_find((string)($_GET['code'] ?? ''));
        if (!$order) {
            not_found('Pedido não encontrado.');
        }
        if ((int)$order['user_id'] !== (int)$user['id'] && !is_admin()) {
            not_found('Pedido não encontrado.');
        }

        if ($method === 'POST' && ($_POST['action'] ?? '') === 'paid') {
            csrf_verify();
            if ($order['status'] === 'pending_payment') {
                $stmt = db()->prepare("UPDATE orders SET status = 'awaiting_confirmation' WHERE id = ?");
                $stmt->execute([(int)$order['id']]);
                flash('Pagamento confirmado. Verificando...');
            }
            redirect('?page=payment_confirming&code=' . urlencode($order['code']));
        }

        $wallets = config('wallets');
        $wallet = $wallets[$order['currency']] ?? ['name' => $order['currency'], 'icon' => 'icon_bitcoin.png'];
        render('payment', ['order' => $order, 'wallet' => $wallet], 'account', 'Payment ' . $order['code']);
        break;

    case 'order':
        redirect('?page=payment&code=' . urlencode((string)($_GET['code'] ?? '')));
        break;

    case 'login':
        if (current_user()) {
            redirect('?page=account');
        }
        $next = (string)($_POST['next'] ?? ($_GET['next'] ?? ''));

        if ($method === 'POST') {
            csrf_verify();
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if ($u && password_verify($password, $u['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id'];
                unset($_SESSION['is_admin']);
                flash('Bem-vindo de volta, ' . $u['username'] . '.');
                if ($next !== '' && (str_starts_with($next, '?') || str_starts_with($next, '/'))) {
                    redirect($next);
                }
                redirect('?page=account');
            }
            usleep(400000);
            flash('Usuário ou senha inválidos.', 'error');
            redirect('?page=login');
        }
        render('auth', ['next' => $next], 'login', 'Login - ' . config('site_name'));
        break;

    case 'register':
        if (current_user()) {
            redirect('?page=account');
        }
        if ($method === 'POST') {
            csrf_verify();
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password2'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
                flash('Usuário inválido: 3-32 caracteres, letras/números/underscore.', 'error');
                redirect('?page=login');
            }
            if (strlen($password) < 8 || $password !== $password2) {
                flash('Senha inválida (mínimo 8) ou confirmação diferente.', 'error');
                redirect('?page=login');
            }
            try {
                $stmt = db()->prepare('INSERT INTO users (username, pass_hash, created_at) VALUES (?,?,?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)db()->lastInsertId();
                unset($_SESSION['is_admin']);
                flash('Conta criada com sucesso.');
                redirect('?page=account');
            } catch (PDOException $e) {
                flash('Este usuário já existe.', 'error');
                redirect('?page=login');
            }
        }
        redirect('?page=login');
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        redirect('?page=index');
        break;

    case 'account':
        $user = require_login();
        $stmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([(int)$user['id']]);
        $orders = $stmt->fetchAll();
        render('account', ['orders' => $orders], 'account', 'Minha conta');
        break;

    case 'support':
        render('support', [], 'support');
        break;

    case 'faq':
        render('faq', [], 'faq', 'FAQ - ' . config('site_name'));
        break;

    // ================= ADMIN =================
    case 'admin':
        if (is_admin()) {
            redirect('?page=admin_orders');
        }
        if ($method === 'POST') {
            csrf_verify();
            $pass = (string)($_POST['password'] ?? '');
            if (admin_check_password($pass)) {
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                redirect('?page=admin_orders');
            }
            usleep(500000);
            flash('Senha de admin inválida.', 'error');
            redirect('?page=admin');
        }
        render('admin_login', [], '', 'Admin');
        break;

    case 'admin_orders':
        require_admin();
        $filter = (string)($_GET['status'] ?? '');
        $sql = 'SELECT o.*, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id';
        $params = [];
        if ($filter !== '' && isset(order_statuses()[$filter])) {
            $sql .= ' WHERE o.status = ?';
            $params[] = $filter;
        }
        $sql .= ' ORDER BY o.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        render('admin_orders', ['orders' => $stmt->fetchAll(), 'filter' => $filter, 'adminActive' => 'orders'], '', 'Admin - Orders');
        break;

    case 'admin_order':
        require_admin();
        $order = order_find((string)($_GET['code'] ?? ''));
        if (!$order) {
            not_found('Pedido não encontrado.');
        }
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'status') {
            csrf_verify();
            $new = (string)($_POST['status'] ?? '');
            if (isset(order_statuses()[$new])) {
                $stmt = db()->prepare('UPDATE orders SET status = ? WHERE id = ?');
                $stmt->execute([$new, (int)$order['id']]);
                flash('Status atualizado.');
            }
            redirect('?page=admin_order&code=' . urlencode($order['code']));
        }
        $stmt = db()->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([(int)$order['user_id']]);
        $order['username'] = $stmt->fetchColumn() ?: '-';
        $wallets = config('wallets');
        render('admin_order', [
            'order' => $order,
            'wallet' => $wallets[$order['currency']] ?? [],
            'shipPlain' => decrypt_ship($order['ship_enc']),
            'adminActive' => 'orders',
        ], '', 'Admin - Order ' . $order['code']);
        break;

    case 'admin_products':
        require_admin();
        render('admin_products', [
            'products' => products_all(false),
            'categories' => categories_all(),
            'adminActive' => 'products',
        ], '', 'Admin - Products');
        break;

    case 'admin_product':
        require_admin();
        $p = product_find((int)($_GET['id'] ?? 0));
        if (!$p) {
            not_found('Produto não encontrado.');
        }
        if ($method === 'POST') {
            csrf_verify();
            $tiersRaw = trim((string)($_POST['tiers'] ?? '[]'));
            $tiers = json_decode($tiersRaw, true);
            $valid = is_array($tiers);
            if ($valid) {
                foreach ($tiers as $t) {
                    if (!isset($t['min'], $t['price']) || !is_numeric($t['min']) || !is_numeric($t['price'])) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) {
                flash('JSON de faixas inválido.', 'error');
                redirect('?page=admin_product&id=' . (int)$p['id']);
            }
            $stmt = db()->prepare('UPDATE products SET name=?, category=?, price=?, tiers=?, unit=?, description=?, active=? WHERE id=?');
            $stmt->execute([
                mb_substr(trim((string)($_POST['name'] ?? $p['name'])), 0, 120),
                (int)($_POST['category'] ?? $p['category']),
                (float)($_POST['price'] ?? $p['price']),
                json_encode($tiers),
                mb_substr(trim((string)($_POST['unit'] ?? '')), 0, 80),
                (string)($_POST['description'] ?? ''),
                isset($_POST['active']) ? 1 : 0,
                (int)$p['id'],
            ]);
            flash('Produto atualizado.');
            redirect('?page=admin_products');
        }
        render('admin_product_edit', [
            'product' => $p,
            'categories' => categories_all(),
            'adminActive' => 'products',
        ], '', 'Admin - Edit product');
        break;

    case 'admin_tickets':
        require_admin();
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'close') {
            csrf_verify();
            $stmt = db()->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?");
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            flash('Ticket fechado.');
            redirect('?page=admin_tickets');
        }
        $rows = db()->query('SELECT t.*, u.username FROM tickets t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id DESC')->fetchAll();
        render('admin_tickets', ['tickets' => $rows, 'adminActive' => 'tickets'], '', 'Admin - Tickets');
        break;

    case 'admin_password':
        require_admin();
        if ($method === 'POST') {
            csrf_verify();
            $current = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $new2 = (string)($_POST['new2'] ?? '');
            if (!admin_check_password($current)) {
                flash('Senha atual incorreta.', 'error');
            } elseif (strlen($new) < 10 || $new !== $new2) {
                flash('Nova senha inválida (mínimo 10) ou confirmação diferente.', 'error');
            } else {
                admin_set_password($new);
                flash('Senha de admin alterada.');
            }
            redirect('?page=admin_password');
        }
        render('admin_password', ['adminActive' => 'password'], '', 'Admin - Password');
        break;

    case 'payment_confirming':
        $order = order_find((string)($_GET['code'] ?? ''));
        if (!$order) {
            not_found('Pedido não encontrado.');
        }
        $user = require_login();
        if ((int)$order['user_id'] !== (int)$user['id'] && !is_admin()) {
            not_found('Pedido não encontrado.');
        }
        render('payment_confirming', ['order' => $order], 'account', 'Confirmando Pagamento - ' . $order['code']);
        break;

    case 'api_check_payment':
        header('Content-Type: application/json');
        $code = (string)($_GET['code'] ?? '');
        $order = order_find($code);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Pedido não encontrado']);
            exit;
        }
        $user = current_user();
        if ($user && (int)$order['user_id'] !== (int)$user['id'] && !is_admin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            exit;
        }
        echo json_encode([
            'status' => $order['status'],
            'code' => $order['code'],
            'total_usd' => (float)$order['total_usd'],
            'currency' => $order['currency'],
        ]);
        exit;
        break;

    default:
        not_found();
}
