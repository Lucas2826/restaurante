<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('America/Sao_Paulo');

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo 'Arquivo de configuração não encontrado.';
    exit;
}

$appConfig = require $configFile;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function repositoryPath(string $path): string
{
    return __DIR__ . '/' . ltrim($path, '/');
}

function setFlash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function appUrl(string $action = 'dashboard', array $query = []): string
{
    $params = array_merge(['action' => $action], $query);

    return '?' . http_build_query($params);
}

function oldValue(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

function formatMoney(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'R$ 0,00';
    }

    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function dbConnection(array $config, ?string &$error = null): ?PDO
{
    if (!isset($config['mysql']) || !is_array($config['mysql'])) {
        $error = 'Configuração MySQL não encontrada em config.php.';
        return null;
    }

    $m = $config['mysql'];
    $host = $m['host'] ?? '127.0.0.1';
    $port = $m['port'] ?? 3306;
    $db = $m['database'] ?? '';
    $user = $m['username'] ?? '';
    $pass = $m['password'] ?? '';
    $charset = $m['charset'] ?? 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $db, $charset);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    } catch (Throwable $exception) {
        $error = 'Falha ao conectar MySQL: ' . $exception->getMessage();
        return null;
    }
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function executeStatement(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->rowCount();
}

function callProcedure(PDO $pdo, string $procedure, array $params = []): array
{
    return match ($procedure) {
        'cadastrar_cliente' => (function () use ($pdo, $params): array {
            executeStatement($pdo, 'INSERT INTO clientes (nome, telefone, email) VALUES (:nome, :telefone, :email)', [
                'nome' => $params['nome'] ?? '',
                'telefone' => $params['telefone'] ?? '',
                'email' => $params['email'] ?? '',
            ]);

            return [];
        })(),
        'inserir_pedido' => (function () use ($pdo, $params): array {
            executeStatement($pdo, 'INSERT INTO pedidos (id_cliente, id_mesa, id_garcom, status_pedido, data_pedido) VALUES (:id_cliente, :id_mesa, :id_garcom, :status_pedido, CURRENT_TIMESTAMP)', [
                'id_cliente' => $params['id_cliente'] ?? null,
                'id_mesa' => $params['id_mesa'] ?? null,
                'id_garcom' => $params['id_garcom'] ?? null,
                'status_pedido' => 'ABERTO',
            ]);

            return [];
        })(),
        'atualizar_preco_produto' => (function () use ($pdo, $params): array {
            executeStatement($pdo, 'UPDATE produtos SET preco = :novo_preco WHERE id_produto = :id_produto', [
                'id_produto' => $params['id_produto'] ?? null,
                'novo_preco' => $params['novo_preco'] ?? null,
            ]);

            return [];
        })(),
        'listar_pedidos_cliente' => (function () use ($pdo, $params): array {
            return fetchAll($pdo, '
                SELECT
                    p.id_pedido,
                    c.nome AS cliente,
                    m.numero_mesa AS mesa,
                    g.nome AS garcom,
                    p.status_pedido AS status,
                    p.data_pedido
                FROM pedidos p
                INNER JOIN clientes c ON c.id_cliente = p.id_cliente
                INNER JOIN mesas m ON m.id_mesa = p.id_mesa
                INNER JOIN garcons g ON g.id_garcom = p.id_garcom
                WHERE p.id_cliente = :id_cliente
                ORDER BY p.data_pedido DESC, p.id_pedido DESC
            ', [
                'id_cliente' => $params['id_cliente'] ?? null,
            ]);
        })(),
        default => throw new InvalidArgumentException('Procedure desconhecida: ' . $procedure),
    };
}

function initialiseDatabase(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS clientes (
            id_cliente INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            telefone TEXT NOT NULL DEFAULT "",
            email TEXT NOT NULL DEFAULT ""
        );

        CREATE TABLE IF NOT EXISTS mesas (
            id_mesa INTEGER PRIMARY KEY AUTOINCREMENT,
            numero_mesa INTEGER NOT NULL UNIQUE,
            capacidade INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "DISPONIVEL"
        );

        CREATE TABLE IF NOT EXISTS garcons (
            id_garcom INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            telefone TEXT NOT NULL DEFAULT ""
        );

        CREATE TABLE IF NOT EXISTS produtos (
            id_produto INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            descricao TEXT NOT NULL DEFAULT "",
            preco REAL NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS pedidos (
            id_pedido INTEGER PRIMARY KEY AUTOINCREMENT,
            id_cliente INTEGER NOT NULL,
            id_mesa INTEGER NOT NULL,
            id_garcom INTEGER NOT NULL,
            status_pedido TEXT NOT NULL DEFAULT "ABERTO",
            data_pedido TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
            FOREIGN KEY (id_mesa) REFERENCES mesas(id_mesa) ON DELETE RESTRICT,
            FOREIGN KEY (id_garcom) REFERENCES garcons(id_garcom) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS itens_pedido (
            id_item INTEGER PRIMARY KEY AUTOINCREMENT,
            id_pedido INTEGER NOT NULL,
            id_produto INTEGER NOT NULL,
            quantidade INTEGER NOT NULL,
            preco_unitario REAL NOT NULL,
            FOREIGN KEY (id_pedido) REFERENCES pedidos(id_pedido) ON DELETE CASCADE,
            FOREIGN KEY (id_produto) REFERENCES produtos(id_produto) ON DELETE RESTRICT
        );
    ');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO clientes (nome, telefone, email) VALUES
            ('Ana Lima', '(11) 98888-7777', 'ana@exemplo.com'),
            ('João Pedro', '(21) 97777-6666', 'joao@exemplo.com'),
            ('Marina Souza', '(31) 96666-5555', 'marina@exemplo.com')");

        $pdo->exec("INSERT INTO mesas (numero_mesa, capacidade, status) VALUES
            (1, 2, 'DISPONIVEL'),
            (2, 4, 'DISPONIVEL'),
            (3, 6, 'OCUPADA'),
            (4, 4, 'DISPONIVEL')");

        $pdo->exec("INSERT INTO garcons (nome, telefone) VALUES
            ('Carlos Mendes', '(11) 90000-1111'),
            ('Fernanda Alves', '(11) 90000-2222'),
            ('Paulo Costa', '(11) 90000-3333')");

        $pdo->exec("INSERT INTO produtos (nome, descricao, preco) VALUES
            ('Filé à parmegiana', 'Acompanha arroz e fritas', 42.90),
            ('Pizza Margherita', 'Molho, mussarela e manjericão', 39.90),
            ('Hambúrguer artesanal', 'Pão brioche, carne e cheddar', 28.50),
            ('Salada Caesar', 'Alface, croutons e molho caesar', 24.00),
            ('Refrigerante', 'Lata 350ml', 6.50),
            ('Brownie com sorvete', 'Sobremesa da casa', 18.00)");

        $pdo->exec("INSERT INTO pedidos (id_cliente, id_mesa, id_garcom, status_pedido, data_pedido) VALUES
            (1, 1, 1, 'PAGO', datetime('now', '-2 day')),
            (2, 3, 2, 'ABERTO', datetime('now', '-1 day'))");

        $pdo->exec("INSERT INTO itens_pedido (id_pedido, id_produto, quantidade, preco_unitario) VALUES
            (1, 1, 1, 42.90),
            (1, 5, 2, 6.50),
            (2, 2, 1, 39.90),
            (2, 6, 2, 18.00)");

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function renderPageStart(string $title): void
{
    echo '<!doctype html>';
    echo '<html lang="pt-BR"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>';
    echo ':root{--bg:#0f172a;--panel:#111827;--panel-2:#1f2937;--text:#e5e7eb;--muted:#94a3b8;--accent:#f59e0b;--accent-2:#22c55e;--danger:#ef4444;--line:#334155;--input:#0b1220;--radius:18px;}';
    echo '*{box-sizing:border-box} body{margin:0;font-family:Arial,Helvetica,sans-serif;background:radial-gradient(circle at top,#1e293b 0,#0f172a 45%,#020617 100%);color:var(--text);}';
    echo 'a{color:inherit;text-decoration:none} .wrap{max-width:1400px;margin:0 auto;padding:24px}';
    echo '.hero{display:flex;justify-content:space-between;align-items:end;gap:24px;flex-wrap:wrap;margin-bottom:24px}';
    echo '.title{font-size:clamp(28px,4vw,44px);margin:0} .subtitle{margin:6px 0 0;color:var(--muted)}';
    echo '.nav{display:flex;flex-wrap:wrap;gap:12px;margin:20px 0 28px} .nav a{padding:10px 14px;border:1px solid var(--line);border-radius:999px;background:rgba(15,23,42,.75)} .nav a.active{background:var(--accent);color:#111827;font-weight:700;border-color:transparent}';
    echo '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px} .panel{background:rgba(17,24,39,.88);backdrop-filter:blur(12px);border:1px solid rgba(148,163,184,.15);border-radius:var(--radius);padding:18px;box-shadow:0 18px 45px rgba(0,0,0,.25)}';
    echo '.panel h2,.panel h3{margin-top:0} .muted{color:var(--muted)} .flash{padding:14px 16px;border-radius:14px;margin:0 0 18px;border:1px solid transparent} .flash.success{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.35)} .flash.error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}';
    echo 'form{display:grid;gap:12px} label{display:grid;gap:6px;font-weight:600;color:#f8fafc} input,select,textarea{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--line);background:var(--input);color:var(--text)} input:focus,select:focus,textarea:focus{outline:2px solid rgba(245,158,11,.45)}';
    echo '.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px} .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center} button,.btn{display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:12px;padding:11px 14px;font-weight:700;cursor:pointer} .btn-primary,button{background:var(--accent);color:#111827} .btn-secondary{background:#334155;color:var(--text)} .btn-danger{background:var(--danger);color:white}';
    echo 'table{width:100%;border-collapse:collapse;overflow:hidden;border-radius:14px} th,td{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.14);text-align:left;vertical-align:top} th{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#cbd5e1;background:rgba(30,41,59,.8)} tr:hover td{background:rgba(30,41,59,.4)} .table-wrap{overflow:auto;border-radius:14px;border:1px solid rgba(148,163,184,.14)}';
    echo '.badge{display:inline-flex;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(148,163,184,.15)} .metric{font-size:30px;font-weight:800;margin:0} .small{font-size:13px;color:var(--muted)} .footer{margin-top:24px;color:var(--muted);font-size:13px}';
    echo '</style></head><body><div class="wrap">';
}

function renderPageEnd(): void
{
    echo '<div class="footer">Sistema em PHP com interface web, PDO e SQLite.</div></div></body></html>';
}

function renderHeader(string $title, string $subtitle): void
{
    echo '<div class="hero">';
    echo '<div><h1 class="title">' . h($title) . '</h1><p class="subtitle">' . h($subtitle) . '</p></div>';
    echo '<div class="badge">Restaurante</div>';
    echo '</div>';
}

function renderNav(string $currentAction): void
{
    $items = [
        'dashboard' => 'Início',
        'customers' => 'Clientes',
        'orders' => 'Pedidos',
        'menu' => 'Cardápio',
        'reports' => 'Relatórios',
    ];

    echo '<nav class="nav">';
    foreach ($items as $action => $label) {
        $class = $action === $currentAction ? 'active' : '';
        echo '<a class="' . $class . '" href="' . h(appUrl($action)) . '">' . h($label) . '</a>';
    }
    echo '</nav>';
}

function renderFlash(?array $flash): void
{
    if ($flash === null) {
        return;
    }

    $type = $flash['type'] === 'error' ? 'error' : 'success';
    echo '<div class="flash ' . $type . '">' . h($flash['message']) . '</div>';
}

function renderTable(array $rows, array $headers = []): void
{
    if (empty($rows)) {
        echo '<p class="muted">Nenhum registro encontrado.</p>';
        return;
    }

    if (empty($headers)) {
        $headers = array_keys($rows[0]);
    }

    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . h($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $header) {
            $value = $row[$header] ?? '';
            if (is_float($value) || is_int($value)) {
                $value = (string) $value;
            }
            echo '<td>' . h($value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function renderOrderBadge(string $status): string
{
    $status = strtoupper($status);
    $class = match ($status) {
        'PAGO' => 'badge',
        'CANCELADO' => 'badge',
        default => 'badge',
    };

    return '<span class="' . $class . '">' . h($status) . '</span>';
}

function renderSectionTitle(string $title, string $subtitle = ''): void
{
    echo '<div style="margin-bottom:14px">';
    echo '<h2 style="margin:0 0 6px">' . h($title) . '</h2>';
    if ($subtitle !== '') {
        echo '<p class="muted" style="margin:0">' . h($subtitle) . '</p>';
    }
    echo '</div>';
}

function renderReadOnlyInfo(string $title, string $text): void
{
    echo '<div class="panel">';
    renderSectionTitle($title);
    echo '<p class="muted" style="margin:0">' . h($text) . '</p>';
    echo '</div>';
}

function normalizeDate(string $value): ?string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function parseInt(string $value): ?int
{
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
}

function parseFloatValue(string $value): ?float
{
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float) $value : null;
}

function requirePost(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function redirect(string $action, array $query = []): never
{
    header('Location: ' . appUrl($action, $query));
    exit;
}

function listCustomers(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_cliente, nome, telefone, email FROM clientes ORDER BY nome');
}

function listProducts(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_produto, nome, descricao, preco FROM produtos ORDER BY nome');
}

function listWaiters(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_garcom, nome, telefone FROM garcons ORDER BY nome');
}

function listTables(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_mesa, numero_mesa, capacidade, status FROM mesas ORDER BY numero_mesa');
}

function listOrders(PDO $pdo): array
{
    return fetchAll($pdo, '
        SELECT
            p.id_pedido,
            c.nome AS cliente,
            m.numero_mesa AS mesa,
            g.nome AS garcom,
            p.status_pedido AS status,
            p.data_pedido
        FROM pedidos p
        INNER JOIN clientes c ON c.id_cliente = p.id_cliente
        INNER JOIN mesas m ON m.id_mesa = p.id_mesa
        INNER JOIN garcons g ON g.id_garcom = p.id_garcom
        ORDER BY p.data_pedido DESC, p.id_pedido DESC
    ');
}

function listOrderItems(PDO $pdo, int $pedidoId): array
{
    return fetchAll($pdo, '
        SELECT
            ip.id_item,
            pr.nome AS prato,
            ip.quantidade,
            ip.preco_unitario,
            (ip.quantidade * ip.preco_unitario) AS subtotal
        FROM itens_pedido ip
        INNER JOIN produtos pr ON pr.id_produto = ip.id_produto
        WHERE ip.id_pedido = :id_pedido
        ORDER BY ip.id_item
    ', ['id_pedido' => $pedidoId]);
}

function createCustomer(PDO $pdo): void
{
    $nome = trim(requirePost('nome'));
    $telefone = trim(requirePost('telefone'));
    $email = trim(requirePost('email'));

    if ($nome === '') {
        setFlash('Informe o nome do cliente.', 'error');
        redirect('customers');
    }

    try {
        callProcedure($pdo, 'cadastrar_cliente', [
            'nome' => $nome,
            'telefone' => $telefone,
            'email' => $email,
        ]);
        setFlash('Cliente cadastrado com sucesso.');
    } catch (Throwable $exception) {
        setFlash('Falha ao cadastrar cliente: ' . $exception->getMessage(), 'error');
    }

    redirect('customers');
}

function searchCustomers(PDO $pdo): array
{
    $term = trim(requirePost('search_term'));

    if ($term === '') {
        return listCustomers($pdo);
    }

    return fetchAll($pdo, 'SELECT id_cliente, nome, telefone, email FROM clientes WHERE nome LIKE :term ORDER BY nome', [
        'term' => '%' . $term . '%',
    ]);
}

function createDish(PDO $pdo): void
{
    $nome = trim(requirePost('dish_name'));
    $descricao = trim(requirePost('dish_description'));
    $preco = parseFloatValue(requirePost('dish_price'));

    if ($nome === '' || $preco === null) {
        setFlash('Preencha nome e preço do prato corretamente.', 'error');
        redirect('menu');
    }

    try {
        executeStatement($pdo, 'INSERT INTO produtos (nome, descricao, preco) VALUES (:nome, :descricao, :preco)', [
            'nome' => $nome,
            'descricao' => $descricao,
            'preco' => $preco,
        ]);
        setFlash('Prato cadastrado com sucesso.');
    } catch (Throwable $exception) {
        setFlash('Falha ao cadastrar prato: ' . $exception->getMessage(), 'error');
    }

    redirect('menu');
}

function updateDishPrice(PDO $pdo): void
{
    $produtoId = parseInt(requirePost('dish_id'));
    $novoPreco = parseFloatValue(requirePost('new_price'));

    if ($produtoId === null || $novoPreco === null) {
        setFlash('Informe um ID válido e um novo preço válido.', 'error');
        redirect('menu');
    }

    try {
        callProcedure($pdo, 'atualizar_preco_produto', [
            'id_produto' => $produtoId,
            'novo_preco' => $novoPreco,
        ]);
        setFlash('Preço atualizado com sucesso.');
    } catch (Throwable $exception) {
        setFlash('Falha ao atualizar preço: ' . $exception->getMessage(), 'error');
    }

    redirect('menu');
}

function createOrder(PDO $pdo): void
{
    $clienteId = parseInt(requirePost('cliente_id'));
    $mesaId = parseInt(requirePost('mesa_id'));
    $garcomId = parseInt(requirePost('garcom_id'));

    if ($clienteId === null || $mesaId === null || $garcomId === null) {
        setFlash('Selecione cliente, mesa e garçom válidos.', 'error');
        redirect('orders');
    }

    try {
        callProcedure($pdo, 'inserir_pedido', [
            'id_cliente' => $clienteId,
            'id_mesa' => $mesaId,
            'id_garcom' => $garcomId,
        ]);

        $pedidoIdRow = fetchOne($pdo, 'SELECT LAST_INSERT_ID() AS id_pedido');
        $pedidoId = (int) ($pedidoIdRow['id_pedido'] ?? 0);

        setFlash('Pedido criado com ID ' . $pedidoId . '.');
        redirect('orders', ['order_id' => $pedidoId]);
    } catch (Throwable $exception) {
        setFlash('Falha ao criar pedido: ' . $exception->getMessage(), 'error');
        redirect('orders');
    }
}

function addItemToOrder(PDO $pdo): void
{
    $pedidoId = parseInt(requirePost('order_id'));
    $produtoId = parseInt(requirePost('product_id'));
    $quantidade = parseInt(requirePost('quantity'));

    if ($pedidoId === null || $produtoId === null || $quantidade === null || $quantidade <= 0) {
        setFlash('Informe pedido, produto e quantidade válidos.', 'error');
        redirect('orders', ['order_id' => $_POST['order_id'] ?? '']);
    }

    $produto = fetchOne($pdo, 'SELECT id_produto, preco FROM produtos WHERE id_produto = :id_produto', [
        'id_produto' => $produtoId,
    ]);

    if ($produto === null) {
        setFlash('Produto não encontrado.', 'error');
        redirect('orders', ['order_id' => $pedidoId]);
    }

    try {
        executeStatement(
            $pdo,
            'INSERT INTO itens_pedido (id_pedido, id_produto, quantidade, preco_unitario) VALUES (:id_pedido, :id_produto, :quantidade, :preco_unitario)',
            [
                'id_pedido' => $pedidoId,
                'id_produto' => $produtoId,
                'quantidade' => $quantidade,
                'preco_unitario' => $produto['preco'],
            ]
        );
        setFlash('Item adicionado com sucesso.');
    } catch (Throwable $exception) {
        setFlash('Falha ao adicionar item: ' . $exception->getMessage(), 'error');
    }

    redirect('orders', ['order_id' => $pedidoId]);
}

function updateOrderStatus(PDO $pdo): void
{
    $pedidoId = parseInt(requirePost('status_order_id'));
    $status = strtoupper(trim(requirePost('new_status')));

    if ($pedidoId === null || !in_array($status, ['ABERTO', 'PAGO', 'CANCELADO'], true)) {
        setFlash('Status inválido.', 'error');
        redirect('orders');
    }

    try {
        executeStatement($pdo, 'UPDATE pedidos SET status_pedido = :status WHERE id_pedido = :id_pedido', [
            'status' => $status,
            'id_pedido' => $pedidoId,
        ]);
        setFlash('Status atualizado com sucesso.');
    } catch (Throwable $exception) {
        setFlash('Falha ao atualizar status: ' . $exception->getMessage(), 'error');
    }

    redirect('orders', ['order_id' => $pedidoId]);
}

function salesByPeriod(PDO $pdo): array
{
    $start = normalizeDate(trim(requirePost('start_date')));
    $end = normalizeDate(trim(requirePost('end_date')));

    if ($start === null || $end === null) {
        return [];
    }

    return fetchAll($pdo, "
        SELECT
            DATE(p.data_pedido) AS dia,
            SUM(ip.quantidade * ip.preco_unitario) AS total_vendas
        FROM pedidos p
        INNER JOIN itens_pedido ip ON ip.id_pedido = p.id_pedido
        WHERE p.status_pedido = 'PAGO'
          AND DATE(p.data_pedido) BETWEEN :inicio AND :fim
        GROUP BY DATE(p.data_pedido)
        ORDER BY dia
    ", [
        'inicio' => $start,
        'fim' => $end,
    ]);
}

function waiterRanking(PDO $pdo): array
{
    return fetchAll($pdo, '
        SELECT
            g.id_garcom,
            g.nome,
            COUNT(p.id_pedido) AS total_pedidos
        FROM garcons g
        LEFT JOIN pedidos p ON p.id_garcom = g.id_garcom
        GROUP BY g.id_garcom, g.nome
        ORDER BY total_pedidos DESC, g.nome ASC
    ');
}

function bestSellingDishes(PDO $pdo): array
{
    return fetchAll($pdo, '
        SELECT
            pr.id_produto,
            pr.nome,
            SUM(ip.quantidade) AS quantidade_vendida,
            SUM(ip.quantidade * ip.preco_unitario) AS faturamento
        FROM produtos pr
        INNER JOIN itens_pedido ip ON ip.id_produto = pr.id_produto
        GROUP BY pr.id_produto, pr.nome
        ORDER BY quantidade_vendida DESC, faturamento DESC
    ');
}

function revenueByTable(PDO $pdo): array
{
    return fetchAll($pdo, "
        SELECT
            m.id_mesa,
            m.numero_mesa,
            SUM(ip.quantidade * ip.preco_unitario) AS faturamento
        FROM mesas m
        INNER JOIN pedidos p ON p.id_mesa = m.id_mesa
        INNER JOIN itens_pedido ip ON ip.id_pedido = p.id_pedido
        WHERE p.status_pedido = 'PAGO'
        GROUP BY m.id_mesa, m.numero_mesa
        ORDER BY faturamento DESC
    ");
}

function customerOrders(PDO $pdo, int $clienteId): array
{
    return callProcedure($pdo, 'listar_pedidos_cliente', [
        'id_cliente' => $clienteId,
    ]);
}

function classifyOrders(PDO $pdo): array
{
    return fetchAll($pdo, "
        SELECT
            p.id_pedido,
            c.nome AS cliente,
            COALESCE(SUM(ip.quantidade), 0) AS total_itens,
            CASE
                WHEN COALESCE(SUM(ip.quantidade), 0) <= 3 THEN 'PEQUENO'
                WHEN COALESCE(SUM(ip.quantidade), 0) BETWEEN 4 AND 6 THEN 'MEDIO'
                ELSE 'GRANDE'
            END AS classificacao
        FROM pedidos p
        INNER JOIN clientes c ON c.id_cliente = p.id_cliente
        LEFT JOIN itens_pedido ip ON ip.id_pedido = p.id_pedido
        GROUP BY p.id_pedido, c.nome
        ORDER BY p.id_pedido DESC
    ");
}

function renderDashboard(?PDO $pdo, ?string $dbError): void
{
    renderSectionTitle('Painel Inicial', 'Acesse as áreas abaixo para gerenciar clientes, pedidos, cardápio e relatórios.');
    echo '<div class="grid">';
    echo '<div class="panel"><h3>Clientes</h3><p class="muted">Cadastrar, listar e buscar clientes por nome.</p><a class="btn btn-primary" href="' . h(appUrl('customers')) . '">Abrir</a></div>';
    echo '<div class="panel"><h3>Pedidos</h3><p class="muted">Criar pedidos, adicionar itens e alterar status.</p><a class="btn btn-primary" href="' . h(appUrl('orders')) . '">Abrir</a></div>';
    echo '<div class="panel"><h3>Cardápio</h3><p class="muted">Listar pratos, cadastrar e atualizar preços.</p><a class="btn btn-primary" href="' . h(appUrl('menu')) . '">Abrir</a></div>';
    echo '<div class="panel"><h3>Relatórios</h3><p class="muted">Vendas, ranking de garçons, pratos vendidos e faturamento.</p><a class="btn btn-primary" href="' . h(appUrl('reports')) . '">Abrir</a></div>';
    echo '</div>';

    if ($dbError !== null) {
        echo '<div class="flash error" style="margin-top:18px">' . h($dbError) . '</div>';
    }

    echo '<div class="panel" style="margin-top:18px">';
    renderSectionTitle('Fluxo sugerido', 'Usando o sistema no navegador');
    echo '<ol class="muted" style="line-height:1.8;margin:0;padding-left:20px">';
    echo '<li>Cadastrar o cliente em Clientes.</li>';
    echo '<li>Criar o pedido em Pedidos.</li>';
    echo '<li>Adicionar itens ao pedido após obter o ID gerado.</li>';
    echo '<li>Marcar o pedido como PAGO ao finalizar o atendimento.</li>';
    echo '</ol>';
    echo '</div>';
}

function renderCustomersPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Gerenciar Clientes', 'Lista, cadastro e busca de clientes.');

    echo '<div class="grid">';
    echo '<div class="panel">';
    echo '<h3>Cadastrar cliente</h3>';
    echo '<form method="post" action="' . h(appUrl('customers')) . '">';
    echo '<input type="hidden" name="task" value="create">';
    echo '<label>Nome <input name="nome" required></label>';
    echo '<label>Telefone <input name="telefone"></label>';
    echo '<label>E-mail <input type="email" name="email"></label>';
    echo '<div class="actions"><button type="submit">Cadastrar</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Buscar cliente</h3>';
    echo '<form method="post" action="' . h(appUrl('customers')) . '">';
    echo '<input type="hidden" name="task" value="search">';
    echo '<label>Nome ou parte do nome <input name="search_term"></label>';
    echo '<div class="actions"><button type="submit">Buscar</button></div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    if ($dbError !== null) {
        echo '<div class="flash error" style="margin-top:18px">' . h($dbError) . '</div>';
        return;
    }

    echo '<div class="panel" style="margin-top:18px">';
    echo '<h3>Clientes cadastrados</h3>';
    renderTable(listCustomers($pdo), ['id_cliente', 'nome', 'telefone', 'email']);
    echo '</div>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['task'] ?? '') === 'search') {
        $results = searchCustomers($pdo);
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Resultado da busca</h3>';
        renderTable($results, ['id_cliente', 'nome', 'telefone', 'email']);
        echo '</div>';
    }
}

function renderOrdersPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Gerenciar Pedidos', 'Criação de pedidos, itens, status e consultas relacionadas.');

    echo '<div class="grid">';
    echo '<div class="panel">';
    echo '<h3>Criar pedido</h3>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="create">';
    echo '<label>Cliente <select name="cliente_id" required><option value="">Selecione</option>';
    foreach (listCustomers($pdo) as $customer) {
        echo '<option value="' . h($customer['id_cliente']) . '">' . h($customer['nome']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Mesa <select name="mesa_id" required><option value="">Selecione</option>';
    foreach (listTables($pdo) as $table) {
        $label = 'Mesa ' . $table['numero_mesa'] . ' - ' . $table['capacidade'] . ' lugares';
        echo '<option value="' . h($table['id_mesa']) . '">' . h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Garçom <select name="garcom_id" required><option value="">Selecione</option>';
    foreach (listWaiters($pdo) as $waiter) {
        echo '<option value="' . h($waiter['id_garcom']) . '">' . h($waiter['nome']) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="actions"><button type="submit">Criar pedido</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Adicionar item ao pedido</h3>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="add_item">';
    echo '<label>ID do pedido <input type="number" name="order_id" value="' . h($_GET['order_id'] ?? '') . '" required></label>';
    echo '<label>Produto <select name="product_id" required><option value="">Selecione</option>';
    foreach (listProducts($pdo) as $product) {
        echo '<option value="' . h($product['id_produto']) . '">' . h($product['nome']) . ' - ' . h(formatMoney($product['preco'])) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Quantidade <input type="number" min="1" name="quantity" value="1" required></label>';
    echo '<div class="actions"><button type="submit">Adicionar item</button></div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div class="grid" style="margin-top:18px">';
    echo '<div class="panel">';
    echo '<h3>Alterar status</h3>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="status">';
    echo '<label>ID do pedido <input type="number" name="status_order_id" value="' . h($_GET['order_id'] ?? '') . '" required></label>';
    echo '<label>Status <select name="new_status" required><option value="ABERTO">ABERTO</option><option value="PAGO">PAGO</option><option value="CANCELADO">CANCELADO</option></select></label>';
    echo '<div class="actions"><button type="submit">Atualizar status</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Consultar itens por pedido</h3>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="action" value="orders">';
    echo '<label>ID do pedido <input type="number" name="order_id" value="' . h($_GET['order_id'] ?? '') . '"></label>';
    echo '<div class="actions"><button type="submit">Visualizar itens</button></div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div class="grid" style="margin-top:18px">';
    echo '<div class="panel">';
    echo '<h3>Pedidos por cliente</h3>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="by_customer">';
    echo '<label>Cliente <select name="customer_id" required><option value="">Selecione</option>';
    foreach (listCustomers($pdo) as $customer) {
        echo '<option value="' . h($customer['id_cliente']) . '">' . h($customer['nome']) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="actions"><button type="submit">Listar pedidos</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Classificação dos pedidos</h3>';
    echo '<p class="muted">PEQUENO até 3 itens, MEDIO entre 4 e 6, GRANDE acima de 6.</p>';
    echo '<div class="actions"><a class="btn btn-primary" href="' . h(appUrl('orders', ['view' => 'classification'])) . '">Ver classificação</a></div>';
    echo '</div>';
    echo '</div>';

    if ($dbError !== null) {
        echo '<div class="flash error" style="margin-top:18px">' . h($dbError) . '</div>';
        return;
    }

    echo '<div class="panel" style="margin-top:18px">';
    echo '<h3>Pedidos cadastrados</h3>';
    renderTable(listOrders($pdo), ['id_pedido', 'cliente', 'mesa', 'garcom', 'status', 'data_pedido']);
    echo '</div>';

    if (isset($_GET['order_id']) && parseInt((string) $_GET['order_id']) !== null) {
        $orderId = (int) $_GET['order_id'];
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Itens do pedido #' . h($orderId) . '</h3>';
        renderTable(listOrderItems($pdo, $orderId), ['id_item', 'prato', 'quantidade', 'preco_unitario', 'subtotal']);
        echo '</div>';
    }

    if (($_GET['view'] ?? '') === 'classification') {
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Classificação dos pedidos</h3>';
        renderTable(classifyOrders($pdo), ['id_pedido', 'cliente', 'total_itens', 'classificacao']);
        echo '</div>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['task'] ?? '') === 'by_customer') {
        $customerId = parseInt(requirePost('customer_id'));
        if ($customerId !== null) {
            echo '<div class="panel" style="margin-top:18px">';
            echo '<h3>Pedidos do cliente #' . h($customerId) . '</h3>';
            $rows = customerOrders($pdo, $customerId);
            renderTable($rows);
            echo '</div>';
        }
    }
}

function renderMenuPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Gerenciar Cardápio', 'Listagem, cadastro e atualização de preços dos pratos.');

    echo '<div class="grid">';
    echo '<div class="panel">';
    echo '<h3>Cadastrar prato</h3>';
    echo '<form method="post" action="' . h(appUrl('menu')) . '">';
    echo '<input type="hidden" name="task" value="create">';
    echo '<label>Nome <input name="dish_name" required></label>';
    echo '<label>Descrição <textarea name="dish_description" rows="3"></textarea></label>';
    echo '<label>Preço <input type="text" name="dish_price" placeholder="42.90" required></label>';
    echo '<div class="actions"><button type="submit">Cadastrar prato</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Atualizar preço</h3>';
    echo '<form method="post" action="' . h(appUrl('menu')) . '">';
    echo '<input type="hidden" name="task" value="price">';
    echo '<label>Prato <select name="dish_id" required><option value="">Selecione</option>';
    foreach (listProducts($pdo) as $product) {
        echo '<option value="' . h($product['id_produto']) . '">' . h($product['nome']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Novo preço <input type="text" name="new_price" placeholder="45.00" required></label>';
    echo '<div class="actions"><button type="submit">Atualizar preço</button></div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    if ($dbError !== null) {
        echo '<div class="flash error" style="margin-top:18px">' . h($dbError) . '</div>';
        return;
    }

    echo '<div class="panel" style="margin-top:18px">';
    echo '<h3>Pratos cadastrados</h3>';
    renderTable(listProducts($pdo), ['id_produto', 'nome', 'descricao', 'preco']);
    echo '</div>';
}

function renderReportsPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Relatórios', 'Consultas consolidadas do restaurante.');

    echo '<div class="grid">';
    echo '<div class="panel">';
    echo '<h3>Total de vendas por período</h3>';
    echo '<form method="post" action="' . h(appUrl('reports')) . '">';
    echo '<input type="hidden" name="task" value="sales">';
    echo '<div class="row"><label>Data inicial <input type="date" name="start_date" required></label><label>Data final <input type="date" name="end_date" required></label></div>';
    echo '<div class="actions"><button type="submit">Gerar relatório</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Ranking de garçons</h3>';
    echo '<p class="muted">Número de pedidos atendidos por garçom.</p>';
    echo '<form method="get" action=""><input type="hidden" name="action" value="reports"><input type="hidden" name="report" value="waiters"><div class="actions"><button type="submit">Exibir ranking</button></div></form>';
    echo '</div>';
    echo '</div>';

    echo '<div class="grid" style="margin-top:18px">';
    echo '<div class="panel">';
    echo '<h3>Pratos mais vendidos</h3>';
    echo '<form method="get" action=""><input type="hidden" name="action" value="reports"><input type="hidden" name="report" value="best_dishes"><div class="actions"><button type="submit">Exibir pratos</button></div></form>';
    echo '</div>';

    echo '<div class="panel">';
    echo '<h3>Faturamento por mesa</h3>';
    echo '<form method="get" action=""><input type="hidden" name="action" value="reports"><input type="hidden" name="report" value="revenue_tables"><div class="actions"><button type="submit">Exibir faturamento</button></div></form>';
    echo '</div>';
    echo '</div>';

    if ($dbError !== null) {
        echo '<div class="flash error" style="margin-top:18px">' . h($dbError) . '</div>';
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['task'] ?? '') === 'sales') {
        $rows = salesByPeriod($pdo);
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Total de vendas por período</h3>';
        renderTable($rows, ['dia', 'total_vendas']);
        echo '</div>';
    }

    $report = $_GET['report'] ?? '';
    if ($report === 'waiters') {
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Ranking de garçons</h3>';
        renderTable(waiterRanking($pdo), ['id_garcom', 'nome', 'total_pedidos']);
        echo '</div>';
    } elseif ($report === 'best_dishes') {
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Pratos mais vendidos</h3>';
        renderTable(bestSellingDishes($pdo), ['id_produto', 'nome', 'quantidade_vendida', 'faturamento']);
        echo '</div>';
    } elseif ($report === 'revenue_tables') {
        echo '<div class="panel" style="margin-top:18px">';
        echo '<h3>Faturamento por mesa</h3>';
        renderTable(revenueByTable($pdo), ['id_mesa', 'numero_mesa', 'faturamento']);
        echo '</div>';
    }
}

$pdo = dbConnection($appConfig, $dbError);

if ($pdo === null) {
    $dbError = $dbError ?? 'Falha ao abrir o banco MySQL. Verifique as configurações em config.php e o acesso ao servidor MySQL.';
}$flash = consumeFlash();
$action = $_GET['action'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null) {
    $task = $_POST['task'] ?? '';
    try {
        if ($action === 'customers' && $task === 'create') {
            createCustomer($pdo);
        } elseif ($action === 'orders' && $task === 'create') {
            createOrder($pdo);
        } elseif ($action === 'orders' && $task === 'add_item') {
            addItemToOrder($pdo);
        } elseif ($action === 'orders' && $task === 'status') {
            updateOrderStatus($pdo);
        } elseif ($action === 'menu' && $task === 'create') {
            createDish($pdo);
        } elseif ($action === 'menu' && $task === 'price') {
            updateDishPrice($pdo);
        }
    } catch (Throwable $exception) {
        setFlash('Erro inesperado: ' . $exception->getMessage(), 'error');
        redirect($action);
    }

}

renderPageStart('Sistema de Gerenciamento de Pedidos');
renderHeader('Sistema de Gerenciamento de Pedidos', 'Interface web em PHP conectada ao SQLite.');
renderNav($action);
renderFlash($flash);

switch ($action) {
    case 'customers':
        if ($pdo !== null) {
            renderCustomersPage($pdo, $dbError, $flash);
        } else {
            renderReadOnlyInfo('Gerenciar Clientes', $dbError ?? 'Banco indisponível.');
        }
        break;
    case 'orders':
        if ($pdo !== null) {
            renderOrdersPage($pdo, $dbError, $flash);
        } else {
            renderReadOnlyInfo('Gerenciar Pedidos', $dbError ?? 'Banco indisponível.');
        }
        break;
    case 'menu':
        if ($pdo !== null) {
            renderMenuPage($pdo, $dbError, $flash);
        } else {
            renderReadOnlyInfo('Gerenciar Cardápio', $dbError ?? 'Banco indisponível.');
        }
        break;
    case 'reports':
        if ($pdo !== null) {
            renderReportsPage($pdo, $dbError, $flash);
        } else {
            renderReadOnlyInfo('Relatórios', $dbError ?? 'Banco indisponível.');
        }
        break;
    default:
        renderDashboard($pdo, $dbError);
        break;
}

renderPageEnd();
