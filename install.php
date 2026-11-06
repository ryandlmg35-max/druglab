<?php
declare(strict_types=1);

require __DIR__ . '/app/helpers.php';

$dataDir = config('data_dir');
$dbFile = $dataDir . '/shop.sqlite';
$alreadyInstalled = false;

if (is_file($dbFile)) {
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $n = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn();
        $alreadyInstalled = $n > 0;
    } catch (Throwable $e) {
        $alreadyInstalled = false;
    }
}

$log = [];
$error = null;

if (!$alreadyInstalled) {
    try {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            image TEXT NOT NULL,
            price REAL NOT NULL,
            tiers TEXT NOT NULL DEFAULT '[]',
            category INTEGER NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            items TEXT NOT NULL,
            total_usd REAL NOT NULL,
            currency TEXT NOT NULL,
            crypto_amount TEXT NOT NULL,
            rate_usd REAL NOT NULL,
            address TEXT NOT NULL,
            ship_enc TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending_payment',
            created_at TEXT NOT NULL,
            paid_at TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            message TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            created_at TEXT NOT NULL
        )");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products(category)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id)');
        $log[] = 'Tabelas criadas.';

        $catalog = json_decode((string)file_get_contents(__DIR__ . '/app/catalog.json'), true);
        if (!is_array($catalog)) {
            throw new RuntimeException('app/catalog.json inválido ou ausente.');
        }

        $stmtCat = $pdo->prepare('INSERT OR REPLACE INTO categories (id, name) VALUES (?,?)');
        foreach ($catalog['categories'] as $c) {
            $stmtCat->execute([(int)$c['id'], $c['name']]);
        }
        $log[] = count($catalog['categories']) . ' categorias importadas.';

        $stmtProd = $pdo->prepare('INSERT OR REPLACE INTO products (id, name, image, price, tiers, category, unit, description, active) VALUES (?,?,?,?,?,?,?,?,1)');
        foreach ($catalog['products'] as $p) {
            $stmtProd->execute([
                (int)$p['id'],
                $p['name'],
                $p['image'],
                (float)$p['price'],
                json_encode($p['tiers']),
                (int)$p['category'],
                $p['unit'] ?? '',
                $p['description'] ?? '',
            ]);
        }
        $log[] = count($catalog['products']) . ' produtos importados.';

        if (!is_file($dataDir . '/admin.hash')) {
            file_put_contents($dataDir . '/admin.hash', password_hash('changeme', PASSWORD_DEFAULT));
            $log[] = 'Senha de admin inicial: changeme (troque no painel).';
        }

        if (!is_file($dataDir . '/app.key')) {
            file_put_contents($dataDir . '/app.key', base64_encode(random_bytes(32)));
            @chmod($dataDir . '/app.key', 0600);
            $log[] = 'Chave de criptografia gerada em data/app.key.';
        }

        file_put_contents($dataDir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");

        $log[] = 'Instalação concluída.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - DrugLab</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container" style="padding-top:40px;">
    <div class="well well-small">
        <h2>DrugLab &mdash; Instalador</h2>
        <hr class="soft">

        <?php if ($error): ?>
            <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($error, ENT_QUOTES) ?></div>
            <p>Verifique permissões de escrita na pasta <code>data/</code> e se a extensão <code>pdo_sqlite</code> está ativa.</p>
        <?php elseif ($alreadyInstalled): ?>
            <div class="alert alert-success">Sistema já instalado.</div>
            <p><a href="?page=index" class="btn">Abrir loja</a> &nbsp; <a href="?page=admin" class="btn">Painel admin</a></p>
            <p class="muted">Por segurança, apague o arquivo <code>install.php</code> do servidor.</p>
        <?php else: ?>
            <div class="alert alert-success">Instalação concluída com sucesso.</div>
            <ul>
                <?php foreach ($log as $line): ?><li><?= htmlspecialchars($line, ENT_QUOTES) ?></li><?php endforeach; ?>
            </ul>
            <p><strong>Próximos passos:</strong></p>
            <ol>
                <li>Edite <code>app/config.php</code> e coloque as SUAS carteiras em <code>wallets</code>.</li>
                <li>Entre em <code>?page=admin</code> com a senha <code>changeme</code> e troque a senha.</li>
                <li>Apague <code>install.php</code>.</li>
            </ol>
            <p><a href="?page=index" class="btn btn-primary">Abrir loja</a> &nbsp; <a href="?page=admin" class="btn">Painel admin</a></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
